<?php
require_once __DIR__ . '/Article_reader.php';
require_once __DIR__ . '/Slop_index.php';
require_once __DIR__ . '/Laya_client.php';
require_once __DIR__ . '/Laya_limit.php';
require_once __DIR__ . '/Check_progress.php';
/**
 * The home page: paste a link to an article (or its text) and get a TL;DR
 * and whether it's slop.
 *
 * check() reads the article (Article_reader), embeds and indexes its
 * sentences with the taxonomy's model (Slop_index), and asks Laya about the
 * counts. Everything happens in the one request: a fetch (at most
 * Page_reader::TIMEOUT seconds), the embeddings (well under a second for
 * MAX_SENTENCES) and one Laya call (LAYA_TIMEOUT), inside the platform's
 * 30 second limit. The result is then saved to the database when one is
 * configured (Welcome_model); a failed save fails the check like any other
 * server error. A page that asks for it gets each step as the server enters
 * it, so the visitor can follow along (Check_progress).
 *
 * The page is public, so checks are limited per visitor address (MAX_CHECKS
 * per WINDOW seconds, Laya_limit): each one fetches a page and asks Laya,
 * which answers one call at a time.
 */
class Welcome extends Trongate {

    const MAX_CHECKS = 10;
    const WINDOW = 600;
    const LAYA_TIMEOUT = 10;

    // Longest pasted text, in characters.
    const MAX_TEXT = 60000;

    // Longest question for embed(), in characters.
    const MAX_QUESTION = 300;

    /** The page. */
    public function index(): void {
        $this->view('welcome', ['base_url' => BASE_URL, 'steps' => Check_progress::STEPS]);
    }

    /**
     * POST {url} or {text}: the TL;DR, the per-sentence reading, the counts
     * and Laya's verdict (`laya`), or `laya_error` saying why there is none.
     * With `Accept: application/x-ndjson`, each step first (Check_progress).
     */
    public function check(): void {
        $progress = new Check_progress(Check_progress::wanted($_SERVER));
        try {
            $result = $this->assess($this->read_input(), $progress);
            $progress->step('save');
            $progress->finish($result + ['id' => $this->model->save_evaluation($result)]);
        } catch (Check_refused $e) {
            // The visitor's input, with a message for them.
            $progress->fail($e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            // Ours: its message (a database's can quote SQL) goes to the
            // log, not the page.
            self::log_failure($e);
            $progress->fail(500, 'Something went wrong on our side, so there is no result. Try again in a moment.');
        }
    }

    /**
     * POST {text}: its `vector` with the model the sentences were embedded
     * with, so the page can rank the article's sentences against a question
     * it is asked (app.js). Null when no word of it is known. A table lookup
     * with nothing fetched and nothing asked of Laya, so it is not counted
     * against the checks.
     */
    public function embed(): void {
        header('Content-Type: application/json');
        try {
            $text = trim((string) ($this->read_input()['text'] ?? ''));
            if ($text === '' || mb_strlen($text, 'UTF-8') > self::MAX_QUESTION) {
                throw new Check_refused('Ask a question of at most ' . self::MAX_QUESTION . ' characters.', 422);
            }
            $vector = Static_model::load(Slop_index::MODEL_DIR)->encode($text)['vector'];
            echo json_encode(['vector' => $vector === null ? null : array_map(fn($v) => round($v, 5), $vector)]);
        } catch (Throwable $e) {
            if ($e instanceof Check_refused) {
                http_response_code($e->getCode());
            } else {
                http_response_code(502);
                self::log_failure($e);
            }
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function assess(array $input, Check_progress $progress): array {
        $url = trim((string) ($input['url'] ?? ''));
        $text = trim((string) ($input['text'] ?? ''));
        if ($url === '' && $text === '') {
            throw new Check_refused('Paste a link to an article, or its text.', 422);
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
            throw new Check_refused('That text is too long. Paste only the article.', 413);
        }
        $wait = (new Laya_limit(sys_get_temp_dir() . '/slop-limit', self::MAX_CHECKS, self::WINDOW))
            ->take(Laya_limit::client($_SERVER));
        if ($wait > 0) {
            $minutes = (int) ceil($wait / 60);
            throw new Check_refused("You've checked a lot of articles in a short time. Try again in $minutes minute" . ($minutes === 1 ? '' : 's') . '.', 429);
        }

        if ($url !== '') {
            $progress->step('fetch');
            try {
                $article = Article_reader::read($url, fn() => $progress->step('read'));
            } catch (RuntimeException $e) {
                // Page_reader's messages are for the person who pasted the link.
                throw new Check_refused($e->getMessage(), 422, $e);
            }
        } else {
            $progress->step('read');
            $article = Article_reader::from_text($text);
        }
        if (!$article['sentences']) {
            throw new Check_refused('There are no sentences to read in that article. If the page needs JavaScript, copy the text and paste it instead.', 422);
        }

        $progress->step('score');
        $index = Slop_index::load();
        $reading = $index->assess($article, $progress->step(...));
        $summary = Slop_index::laya_summary($article['title'], $reading['counts'], $reading['stock_words']);
        $progress->step('laya');
        return [
            'url' => $article['url'] ?? null,
            'title' => $article['title'],
            'summary' => $summary,
        ] + $reading + $this->laya($summary);
    }

    /** Laya's answer on the summary, or why there isn't one. */
    private function laya(string $summary): array {
        $laya = Laya_client::from_env(self::LAYA_TIMEOUT);
        if ($laya === null) {
            return ['laya_error' => "Laya isn't set up on this server (no LAYA_URL), so there is no verdict. The reading below is the embeddings' alone."];
        }
        try {
            return ['laya' => Slop_index::read_answer($laya->ask($summary, Slop_index::questions()))];
        } catch (Throwable $e) {
            self::log_failure($e);
            return ['laya_error' => $e->getMessage()];
        }
    }

    private function read_input(): array {
        // A JSON content type can't be sent cross-site without a CORS
        // preflight, which this site never answers.
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new Check_refused('Expected a JSON body.', 415);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new Check_refused('Expected a JSON body.', 400);
        }
        return $input;
    }

    /** Writes a failure the visitor was told about to the server log as well. */
    private static function log_failure(Throwable $e): void {
        for (; $e; $e = $e->getPrevious()) {
            error_log('slop: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }
}
