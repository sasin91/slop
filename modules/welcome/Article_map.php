<?php
require_once __DIR__ . '/model/Static_model.php';
/**
 * The parts of a reading that help someone find their way around an
 * article, as the AI article on sasin91.xyz does with its timeline and Ask
 * views:
 *
 * - timeline(): the article as a progression of points, one sentence each.
 *   Near each place an even split would cut, it cuts where neighbouring
 *   sentences stop being about the same thing (the lowest cosine between
 *   the WINDOW sentences either side of a gap), and each part is stood for
 *   by its sentence nearest the part's centre.
 * - questions(): questions to start Ask with, about the terms the article
 *   keeps coming back to. Names and acronyms (written with a capital where
 *   a sentence doesn't start) and two-word terms count for more than plain
 *   words, which need LONG_WORD letters to count at all.
 * - pack(): every sentence's vector, as int8 with one scale per row, so the
 *   page can rank sentences against a question without asking again.
 */
class Article_map {

    const SENTENCES_PER_STEP = 5;
    const MAX_STEPS = 8;
    const MIN_PART = 3;
    const WINDOW = 3;

    const QUESTIONS = 4;
    const MIN_TERM_LETTERS = 3;
    const LONG_WORD = 6;

    // Words a question about the article is not about: function words, and
    // the words of the questions themselves (the page drops them too, in
    // ask.js, before matching words).
    const STOP_WORDS = ['a', 'about', 'above', 'after', 'again', 'all', 'also', 'am', 'an', 'and', 'any', 'are',
        'as', 'at', 'be', 'because', 'been', 'before', 'being', 'between', 'both', 'but', 'by', 'can', 'could',
        'did', 'do', 'does', 'doing', 'done', 'down', 'during', 'each', 'even', 'every', 'explain', 'few', 'for',
        'from', 'get', 'gets', 'give', 'gives', 'got', 'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers',
        'him', 'his', 'how', 'however', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'itself', 'just', 'know',
        'like', 'make', 'makes', 'many', 'may', 'me', 'might', 'more', 'most', 'much', 'must', 'my', 'need',
        'needs', 'new', 'no', 'nor', 'not', 'now', 'of', 'off', 'on', 'once', 'one', 'only', 'or', 'other', 'our',
        'ours', 'out', 'over', 'own', 'really', 'said', 'same', 'say', 'says', 'see', 'she', 'should', 'so',
        'some', 'still', 'such', 'than', 'that', 'the', 'their', 'theirs', 'them', 'then', 'there', 'these',
        'they', 'thing', 'things', 'this', 'those', 'through', 'to', 'too', 'two', 'under', 'until', 'up', 'us',
        'use', 'used', 'using', 'very', 'want', 'was', 'way', 'we', 'well', 'were', 'what', 'when', 'where',
        'whether', 'which', 'while', 'who', 'whom', 'why', 'will', 'with', 'within', 'without', 'would', 'yet',
        'you', 'your', 'yours', 'article', 'author', 'tell', 'tells', 'mean', 'means', 'work', 'works', 'against',
        'across', 'already', 'always', 'another', 'around', 'enough', 'instead', 'never', 'often', 'rather', 'though',
        'whole', 'first', 'last', 'better', 'actually', 'something', 'anything', 'everything', 'nothing'];

    /**
     * The timeline: [{sentence, text, from, to}] in article order, where
     * `sentence` stands for sentences `from` to `to` (0-based, inclusive).
     * Empty when the article is too short to have more than one step.
     *
     * @param array $sentences Slop_index::assess()'s sentences
     * @param array<int, float[]> $vectors by sentence index; a sentence with
     *        no known token has none
     */
    public static function timeline(array $sentences, array $vectors): array {
        $n = count($sentences);
        $steps = min(self::MAX_STEPS, intdiv($n, self::SENTENCES_PER_STEP));
        if ($steps < 2) {
            return [];
        }

        $gaps = [];
        for ($b = self::MIN_PART; $b <= $n - self::MIN_PART; $b++) {
            $before = self::centre($vectors, range(max(0, $b - self::WINDOW), $b - 1));
            $after = self::centre($vectors, range($b, min($n, $b + self::WINDOW) - 1));
            $gaps[$b] = $before && $after ? Static_model::cosine($before, $after) : 1.0;
        }
        // Each cut is the weakest gap near where an even split would cut,
        // so the parts follow the article's turns but stay of a similar size.
        $cuts = [];
        $reach = max(1, intdiv(intdiv($n, $steps), 2));
        for ($k = 1; $k < $steps; $k++) {
            $even = (int) round($k * $n / $steps);
            $best = null;
            foreach ($gaps as $b => $cosine) {
                if (abs($b - $even) <= $reach && $b - (end($cuts) ?: 0) >= self::MIN_PART
                    && ($best === null || $cosine < $gaps[$best])) {
                    $best = $b;
                }
            }
            if ($best !== null) {
                $cuts[] = $best;
            }
        }

        $timeline = [];
        $bounds = [0, ...$cuts, $n];
        for ($p = 0; $p < count($bounds) - 1; $p++) {
            $part = range($bounds[$p], $bounds[$p + 1] - 1);
            $i = self::representative($sentences, $vectors, $part);
            $timeline[] = ['sentence' => $i, 'text' => $sentences[$i]['text'], 'from' => $part[0], 'to' => end($part)];
        }
        return $timeline;
    }

    /**
     * Up to QUESTIONS questions about the terms that come up in the most
     * sentences, weighted as the class comment says, each in at least two
     * sentences and not in most of them.
     *
     * @param string[] $sentences
     * @return string[]
     */
    public static function questions(array $sentences): array {
        $n = count($sentences);
        $seen = [];   // term => number of sentences it is in
        $forms = [];  // term => [as written => count], to show it as the article does
        foreach ($sentences as $sentence) {
            preg_match_all('/\p{L}[\p{L}\p{N}]*(?:[-\'’]\p{L}+)*/u', $sentence, $m, PREG_OFFSET_CAPTURE);
            $terms = [];
            $previous = null;
            foreach ($m[0] as [$word, $offset]) {
                $lower = mb_strtolower($word, 'UTF-8');
                if (in_array($lower, self::STOP_WORDS, true) || mb_strlen($lower, 'UTF-8') < self::MIN_TERM_LETTERS) {
                    $previous = null;
                    continue;
                }
                // A capital that only starts the sentence says nothing about the word.
                $form = $offset === 0 && preg_match('/^\p{Lu}\p{Ll}*$/u', $word) ? $lower : $word;
                $terms[$lower][$form] = 1;
                if ($previous !== null && $previous[2] === $offset - 1 - strlen($previous[0])) {
                    $terms["$previous[1] $lower"]["$previous[3] $form"] = 1;
                }
                $previous = [$word, $lower, $offset, $form];
            }
            foreach ($terms as $term => $written) {
                $seen[$term] = ($seen[$term] ?? 0) + 1;
                foreach ($written as $form => $_) {
                    $forms[$term][$form] = ($forms[$term][$form] ?? 0) + 1;
                }
            }
        }

        $scores = [];
        foreach ($seen as $term => $count) {
            if ($count < 2 || ($n >= 10 && $count > $n / 2)) {
                continue;
            }
            arsort($forms[$term]);
            $forms[$term] = array_key_first($forms[$term]);
            $named = (bool) preg_match('/\p{Lu}/u', $forms[$term]);
            $two_words = str_contains($term, ' ');
            if (!$named && !$two_words && mb_strlen($term, 'UTF-8') < self::LONG_WORD) {
                continue;
            }
            $scores[$term] = $count * ($two_words ? 2.0 : 1.0) * ($named ? 1.5 : 1.0);
        }
        arsort($scores);

        $picked = [];
        foreach (array_keys($scores) as $term) {
            foreach ($picked as $other) {
                if (str_contains(" $other ", " $term ") || str_contains(" $term ", " $other ")) {
                    continue 2;
                }
            }
            $picked[] = $term;
            if (count($picked) === self::QUESTIONS) {
                break;
            }
        }
        return array_map(fn(string $term) => "What does it say about {$forms[$term]}?", $picked);
    }

    /**
     * The vectors for the page: base64 of, per sentence, a little-endian f32
     * scale then dim int8s (vector ≈ scale × int8s). A sentence with no
     * vector has scale 0.
     *
     * @param array<int, float[]> $vectors
     */
    public static function pack(array $vectors, int $count, int $dim): string {
        $bytes = '';
        for ($i = 0; $i < $count; $i++) {
            $vector = $vectors[$i] ?? null;
            $max = $vector ? max(array_map('abs', $vector)) : 0.0;
            if ($max == 0.0) {
                $bytes .= pack('g', 0.0) . str_repeat("\0", $dim);
                continue;
            }
            $scale = $max / 127;
            $bytes .= pack('g', $scale) . pack('c*', ...array_map(fn($v) => (int) round($v / $scale), $vector));
        }
        return base64_encode($bytes);
    }

    /** The sentence of $part nearest its centre, preferring ones that say something. */
    private static function representative(array $sentences, array $vectors, array $part): int {
        $with_vector = array_values(array_filter($part, fn($i) => isset($vectors[$i])));
        if (!$with_vector) {
            return $part[0];
        }
        $plain = array_values(array_filter($with_vector, fn($i) => !$sentences[$i]['filler']));
        $centre = self::centre($vectors, $plain ?: $with_vector);
        $candidates = array_values(array_filter($plain, fn($i) => $sentences[$i]['repeats'] === null
            && preg_match_all('/[\p{L}\p{N}]+/u', $sentences[$i]['text']) >= Slop_index::TLDR_MIN_WORDS));
        $best = null;
        $best_score = -INF;
        foreach ($candidates ?: ($plain ?: $with_vector) as $i) {
            $score = Static_model::cosine($centre, $vectors[$i]);
            if ($score > $best_score) {
                [$best, $best_score] = [$i, $score];
            }
        }
        return $best;
    }

    /** The unit mean of the vectors of $indices that have one, or null. */
    private static function centre(array $vectors, array $indices): ?array {
        $sum = null;
        foreach ($indices as $i) {
            if (!isset($vectors[$i])) {
                continue;
            }
            $sum ??= array_fill(0, count($vectors[$i]), 0.0);
            foreach ($vectors[$i] as $d => $v) {
                $sum[$d] += $v;
            }
        }
        return $sum === null ? null : Static_model::unit($sum);
    }
}
