<?php
require_once __DIR__ . '/model/Static_model.php';
require_once __DIR__ . '/Slop_reference.php';
require_once __DIR__ . '/Article_map.php';
/**
 * Embeds an article's sentences with the taxonomy's static model
 * (potion-multilingual, model/Static_model.php, from the recruiter's taxonomy) and indexes them
 * against Slop_reference's sentences embedded with the same model, which
 * gives what the page shows and what Laya is asked about:
 *
 * - filler: a sentence whose three nearest slop references are, on
 *   average, at least FILLER_MARGIN more similar than its three nearest
 *   substance references. Checked on 20 held-out sentences (10 each way):
 *   filler sat at +0.09 to +0.23, substance at -0.17 to +0.06.
 * - repeats: a sentence at cosine REPEAT or more to an earlier one that
 *   isn't its neighbour, i.e. the same point made again.
 * - figures: sentences with a digit in them (numbers, versions, sizes).
 * - stock words: the vocabulary of generated text (STOCK_WORDS), counted
 *   as words, since a static model can't tell "delve" from "dig".
 * - TL;DR: one short sentence stating the article's point. Of the
 *   prose sentences that aren't filler, repeats, questions or citations,
 *   the one nearest both the title and the centre of the article, with a
 *   lift for sentences that state a claim (CLAIM_CUES) and a drop for the
 *   intro, which sets the scene more often than it states the point;
 *   then cut to its main clause (gist()).
 * - the timeline, the questions to ask and the sentences' vectors, for
 *   the page's Ask box: see Article_map.
 *
 * Laya then judges the counts, not the text: the bake-off found the English
 * checkpoint reliable on a code-computed summary and weak on raw text
 * (the recruiter's eval/README.md), so laya_summary() puts the counts in one English
 * paragraph and questions() asks a yes/no and a 0-4 score about it.
 */
class Slop_index {

    const MODEL_DIR = __DIR__ . '/model/potion-multilingual-da';

    const FILLER_MARGIN = 0.08;
    const NEAREST = 3;
    const REPEAT = 0.92;
    const TLDR_MIN_WORDS = 8;
    const TLDR_MAX_WORDS = 40;
    const GIST_WORDS = 18;
    const MIN_CLAUSE = 6;
    const CLAIM_LIFT = 0.06;
    const INTRO = 0.15;
    const INTRO_DROP = 0.04;
    const ASIDE_DROP = 0.1;

    // Words that state a point rather than describe a step.
    const CLAIM_CUES = '/\b(should|shouldn[\x27’]t|must|don[\x27’]t need|no need|instead|rather than| than |better|cheaper|faster|'
        . 'the point|the key|the trick|the lesson|the takeaway|the answer|the real|bottom line|in short|'
        . 'turns out|which means|that[\x27’]s why|this is why|is why|means that|so you|you can|you don[\x27’]t|'
        . 'already|stop|never|always|the best|beats?|wins?|outperforms?)\b/iu';

    // Sentences that lean on the ones around them: an example, a pointer
    // to what follows, or one that opens on what came before.
    const ASIDES = '/^(?:here[\x27’]?s|here is|here are|this|that|these|those|it|they|for example|for instance|'
        . 'e\.g\.|also|another|then|next|first|second|finally)\b|\b(?:for example|for instance|e\.g\.)/iu';

    // How a clause that isn't the sentence's main one starts.
    const NOT_MAIN = '/^(?:for|in|on|with|by|at|of|to|from|when|if|after|before|while|although|because|since|unless)\b/iu';

    // A sales pitch or a sign-off, which closes many posts but isn't their point.
    const PITCH = '/\b(?:sign up|get started|free trial|try it|subscribe|follow me|integrates with|book a demo|'
        . 'our (?:product|platform|team|tool|app|service))\b/iu';

    // Lead-ins that come before the point and add nothing to it.
    const LEAD_INS = '/^(?:(?:so|and|but|basically|ultimately|in short|in other words|in the end|'
        . 'tl;?dr|the point is(?: that)?|the takeaway(?: is)?(?: that)?|the lesson(?: here)?(?: is)?(?: that)?|'
        . 'the bottom line(?: is)?(?: that)?|that[\x27’]s why|this is why|which means|in this (?:post|article),? i(?: will)?'
        . '(?: argue| show| explain)?(?: that| how| why)?)[,:]?\s+)+/iu';

    const STOCK_WORDS = ['delve', 'delves', 'delving', 'tapestry', 'testament', 'game-changer', 'game-changing',
        'unlock', 'unlocks', 'unleash', 'seamless', 'seamlessly', 'leverage', 'leveraging', 'landscape', 'realm',
        'embark', 'elevate', 'pivotal', 'crucial', 'robust', 'foster', 'fostering', 'empower', 'empowers',
        'transformative', 'revolutionize', 'revolutionizes', 'cutting-edge', 'ever-evolving', 'synergy',
        'holistic', 'paradigm', 'navigate', 'navigating', 'intricacies', 'underscores', 'showcasing', 'vibrant'];

    const SUBSTANCE_LEVELS = ['no real content', 'very little', 'some', 'a lot', 'dense with specifics'];

    /** @var array<int, float[]> */
    private array $slop = [];
    /** @var array<int, float[]> */
    private array $substance = [];

    public function __construct(private readonly Static_model $model) {
        foreach (Slop_reference::SLOP as $sentence) {
            $this->slop[] = $model->encode($sentence)['vector'];
        }
        foreach (Slop_reference::SUBSTANCE as $sentence) {
            $this->substance[] = $model->encode($sentence)['vector'];
        }
    }

    /** The index on the shipped model (taxonomy/models). */
    public static function load(): self {
        return new self(Static_model::load(self::MODEL_DIR));
    }

    /**
     * How far $vector leans to the slop side: the mean cosine of its
     * NEAREST slop references minus that of its NEAREST substance ones.
     */
    public function lean(array $vector): float {
        return self::nearest($vector, $this->slop) - self::nearest($vector, $this->substance);
    }

    /**
     * The article (Article_reader) indexed: per sentence {text, lean, filler,
     * repeats, figure}, the counts, the stock words found, the TL;DR, the
     * timeline, questions to ask and every sentence's vector (Article_map).
     */
    public function assess(array $article): array {
        $sentences = [];
        $vectors = [];
        foreach ($article['sentences'] as $i => $text) {
            $vector = $this->model->encode($text)['vector'];
            $lean = $vector === null ? 0.0 : $this->lean($vector);
            $repeats = null;
            if ($vector !== null) {
                for ($j = 0; $j < $i - 1; $j++) {
                    if (isset($vectors[$j]) && Static_model::cosine($vectors[$j], $vector) >= self::REPEAT) {
                        $repeats = $j;
                        break;
                    }
                }
                $vectors[$i] = $vector;
            }
            $sentences[] = [
                'text' => $text,
                'lean' => round($lean, 3),
                'filler' => $lean >= self::FILLER_MARGIN,
                'repeats' => $repeats,
                'figure' => (bool) preg_match('/\d/', $text),
            ];
        }

        $stock = self::stock_words(implode(' ', $article['sentences']));
        $count = fn(string $key) => count(array_filter($sentences, fn($s) => $s[$key] !== false && $s[$key] !== null));
        return [
            'sentences' => $sentences,
            'counts' => [
                'sentences' => count($sentences),
                'words' => $article['words'],
                'filler' => $count('filler'),
                'repeats' => $count('repeats'),
                'figures' => $count('figure'),
                'code_blocks' => $article['code_blocks'],
                'links' => $article['links'],
                'stock_words' => array_sum($stock),
            ],
            'stock_words' => $stock,
            'tldr' => $this->tldr($sentences, $vectors, $this->title_vector($article['title'] ?? '')),
            'timeline' => Article_map::timeline($sentences, $vectors),
            'questions' => Article_map::questions($article['sentences']),
            'vectors' => ['dim' => $this->model->dim, 'q8' => Article_map::pack($vectors, count($sentences), $this->model->dim)],
        ];
    }

    /**
     * The TL;DR: one sentence stating the article's point, cut to its gist
     * (see the class comment), or '' when nothing qualifies.
     *
     * @param ?float[] $title the title's vector, when it has one
     */
    public function tldr(array $sentences, array $vectors, ?array $title = null): string {
        $ranked = $this->points($sentences, $vectors, $title);
        return $ranked ? self::gist($sentences[array_key_first($ranked)]['text']) : '';
    }

    /**
     * The sentences that could state the point, best first, as index =>
     * score: nearness to the title and the centre, CLAIM_LIFT for a claim,
     * less INTRO_DROP in the intro and ASIDE_DROP for an aside.
     *
     * @return array<int, float>
     */
    public function points(array $sentences, array $vectors, ?array $title = null): array {
        $n = count($sentences);
        $pool = array_filter(array_keys($vectors), fn($i) => self::could_be_point($sentences[$i]));
        if (!$pool) {
            $pool = array_filter(array_keys($vectors), fn($i) => $sentences[$i]['repeats'] === null);
        }
        if (!$pool) {
            return [];
        }
        $centre = array_fill(0, count($vectors[$pool[array_key_first($pool)]]), 0.0);
        foreach ($pool as $i) {
            foreach ($vectors[$i] as $d => $v) {
                $centre[$d] += $v;
            }
        }
        $centre = Static_model::unit($centre);
        $intro = max(2, (int) ceil($n * self::INTRO));
        $scores = [];
        foreach ($pool as $i) {
            $text = $sentences[$i]['text'];
            $score = Static_model::cosine($centre, $vectors[$i]);
            if ($title !== null) {
                $score = ($score + Static_model::cosine($title, $vectors[$i])) / 2;
            }
            $score += self::CLAIM_LIFT * min(2, preg_match_all(self::CLAIM_CUES, $text));
            if (preg_match(self::ASIDES, $text)) {
                $score -= self::ASIDE_DROP;
            }
            if ($i < $intro && $n > $intro) {
                $score -= self::INTRO_DROP;
            }
            $scores[$i] = $score;
        }
        arsort($scores);
        return $scores;
    }

    /**
     * $sentence cut to its point: without lead-ins ("So,", "The point is
     * that") or asides in brackets, and past GIST_WORDS words, to its main
     * clause: before a dash or semicolon, or after a colon that ends a
     * short lead-in ("Here's the thing: …").
     */
    public static function gist(string $sentence): string {
        $text = preg_replace('/^(?:\(?[ivx\d]{1,4}[.)]\s+)/iu', '', trim($sentence));
        $text = trim(preg_replace('/\s*\([^()]*\)/u', '', $text));
        $text = preg_replace(self::LEAD_INS, '', $text);
        $words = fn(string $s) => preg_match_all('/[\p{L}\p{N}]+/u', $s);
        if ($words($text) > self::GIST_WORDS) {
            // An aside between dashes goes first: "For most uses — a, b, c — X wins."
            // It leaves a comma only after an opening phrase, not between a subject and its verb.
            $text = preg_replace('/\s+[—–]\s+[^—–]+\s+[—–]\s+/u', preg_match(self::NOT_MAIN, $text) ? ', ' : ' ', $text);
        }
        if ($words($text) > self::GIST_WORDS) {
            $colon = preg_split('/:\s+/u', $text, 2);
            if (count($colon) === 2 && $words($colon[0]) < self::MIN_CLAUSE && $words($colon[1]) >= self::MIN_CLAUSE) {
                $text = $colon[1];
            }
        }
        if ($words($text) > self::GIST_WORDS) {
            foreach (preg_split('/\s+[—–]\s+|\s+-\s+|;\s+|:\s+|,\s+(?=(?:which|while|whereas|giving|making|letting|leaving|allowing)\b)/u', $text) as $clause) {
                // A clause opening on a preposition or "if" isn't the main one.
                if ($words($clause) >= self::MIN_CLAUSE && !preg_match(self::NOT_MAIN, $clause)) {
                    $text = $clause;
                    break;
                }
            }
        }
        $text = rtrim(trim($text), " ,;:—–-");
        if ($text === '') {
            return trim($sentence);
        }
        $text = mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
        return preg_match('/[.!?…]["”’)]*$/u', $text) ? $text : $text . '.';
    }

    /**
     * Whether a sentence could state the point: prose of TLDR_MIN_WORDS to
     * TLDR_MAX_WORDS words that isn't filler, a repeat, a fragment, code, a question, the
     * lead-in to a list (ending in a colon), a pitch (PITCH) or a citation (a footnote's ↩,
     * a [n] marker, or a quoted title).
     */
    private static function could_be_point(array $sentence): bool {
        $text = $sentence['text'];
        $words = preg_match_all('/[\p{L}\p{N}]+/u', $text);
        return !$sentence['filler'] && $sentence['repeats'] === null
            && $words >= self::TLDR_MIN_WORDS && $words <= self::TLDR_MAX_WORDS
            && !preg_match('/[?:]["”’)]*$/u', $text) && !preg_match('/^\p{Ll}/u', $text) && !preg_match(self::PITCH, $text)
            && !preg_match('/[{}<>=`]|\w_\w/u', $text)
            && !preg_match('/↩|^\[\d+\]|^\^|,["”]\s|["“][^"”]+["”],?\s+(?:\p{L}+\.?\s+)?\d{4}/u', $text);
    }

    private function title_vector(string $title): ?array {
        return $title === '' ? null : $this->model->encode($title)['vector'];
    }

    /** Each stock word found in $text with how often, most first. */
    public static function stock_words(string $text): array {
        $found = [];
        preg_match_all('/[\p{L}]+(?:-[\p{L}]+)*/u', mb_strtolower($text), $words);
        foreach ($words[0] as $word) {
            if (in_array($word, self::STOCK_WORDS, true)) {
                $found[$word] = ($found[$word] ?? 0) + 1;
            }
        }
        arsort($found);
        return $found;
    }

    /**
     * The state Laya judges: the counts in one English paragraph, worded
     * like the recruiter's match summary (the title, then plain counts).
     */
    public static function laya_summary(string $title, array $counts, array $stock_words): string {
        $n = max(1, $counts['sentences']);
        $share = fn(int $k) => (int) round($k / $n * 100);
        $words = $stock_words
            ? implode(', ', array_map(fn($w, $k) => $k > 1 ? "$w ($k)" : $w, array_keys($stock_words), $stock_words))
            : 'none';
        return 'Article: ' . ($title !== '' ? $title : 'untitled') . '. '
            . "{$counts['words']} words in {$counts['sentences']} sentences. "
            . "{$counts['filler']} of {$counts['sentences']} sentences ({$share($counts['filler'])}%) read like generic filler. "
            . self::plural($counts['repeats'], 'sentence repeats', 'sentences repeat') . ' an earlier point. '
            . self::plural($counts['figures'], 'sentence', 'sentences') . " ({$share($counts['figures'])}%) "
            . ($counts['figures'] === 1 ? 'gives' : 'give') . ' a figure. '
            . 'It has ' . self::plural($counts['code_blocks'], 'code block', 'code blocks') . ' and '
            . self::plural($counts['links'], 'link', 'links') . ' to other sites. '
            . "Stock AI words: $words.";
    }

    /** The questions Laya answers about laya_summary(). */
    public static function questions(): array {
        return [
            'slop' => ['type' => 'noul', 'instructions' => 'Is this article AI slop: generic, padded text with little real content?'],
            'substance' => ['type' => 'score', 'instructions' => 'How much real, specific content does the article have?',
                'criteria' => self::SUBSTANCE_LEVELS],
        ];
    }

    /**
     * Laya's answers as `slop` (probability), `substance` (0-4),
     * `substance_label` and `verdict` ('slop' at 0.5 or more, else 'not slop').
     */
    public static function read_answer(array $answers): array {
        $slop = $answers['slop']['noul'] ?? null;
        $substance = $answers['substance']['score'] ?? null;
        if (!is_numeric($slop) || !is_numeric($substance)) {
            throw new RuntimeException('Laya answered without the slop and substance scores.');
        }
        $substance = max(0.0, min(4.0, (float) $substance));
        return [
            'slop' => round((float) $slop, 4),
            'substance' => round($substance, 3),
            'substance_label' => self::SUBSTANCE_LEVELS[(int) round($substance)],
            'verdict' => $slop >= 0.5 ? 'slop' : 'not slop',
        ];
    }

    private static function plural(int $n, string $one, string $more): string {
        return $n === 1 ? "1 $one" : "$n $more";
    }

    /** Mean cosine of $vector's NEAREST neighbours in $set. */
    private static function nearest(array $vector, array $set): float {
        $cosines = [];
        foreach ($set as $reference) {
            $cosines[] = Static_model::cosine($reference, $vector);
        }
        rsort($cosines);
        $top = array_slice($cosines, 0, self::NEAREST);
        return array_sum($top) / count($top);
    }
}
