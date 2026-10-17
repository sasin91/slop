<?php
/**
 * What check() sends back, and the steps the page shows while it runs.
 *
 * A request that accepts application/x-ndjson gets the check as it happens:
 * one JSON line per step as the server enters it ({"step": "fetch"}), then
 * {"result": …} or {"error": …, "status": …}. The response starts on the
 * first step, so a check refused before then (bad input, the rate limit) is
 * still an ordinary JSON error with its HTTP status. Any other request gets
 * the result as one JSON object, as before.
 *
 * STEPS is the timeline on the page (views/welcome.php): `send` and `show`
 * happen in the browser, the rest are the keys step() is called with.
 */
class Check_progress {

    const TYPE = 'application/x-ndjson';

    const STEPS = [
        'send' => [
            'title' => 'Send your link',
            'tldr' => 'Your browser sends the link, or the text you pasted, to our server.',
            'about' => ['Nothing is sent until you press Check. A visitor can run 10 checks in 10 minutes, because each one reads a page and asks Laya.'],
        ],
        'fetch' => [
            'title' => 'Fetch the page',
            'tldr' => 'We download the page, from public web addresses only.',
            'about' => [
                'We follow up to 5 redirects, read at most 2 MB and give up after 15 seconds.',
                'Addresses inside a private network are refused. If the site needs a login or blocks us, paste the text instead.',
            ],
            'skipped' => 'Skipped: you pasted the text',
        ],
        'read' => [
            'title' => 'Find the article',
            'tldr' => 'We keep the article and split it into sentences.',
            'about' => [
                'Menus, footers, comments and forms are dropped. Code blocks and links to other sites are counted, and code is left out of the sentences.',
                'A sentence needs at least 5 words, so headings and captions are skipped.',
            ],
        ],
        'score' => [
            'title' => 'Read every sentence',
            'tldr' => 'Each sentence is compared with examples of filler and of real content.',
            'about' => [
                'A sentence is filler when it is clearly closer to the filler examples than to the specific ones.',
                'We also mark sentences that repeat an earlier point, count figures, and count stock AI words such as "delve" and "seamless".',
            ],
        ],
        'tldr' => [
            'title' => 'Pick the TL;DR',
            'tldr' => 'The sentence that best states the article\'s point, trimmed to its gist.',
            'about' => [
                'It is the sentence closest to the title and to the article as a whole, preferring ones that make a claim or follow a "Takeaways" heading.',
                'Filler, repeats, examples, questions and sales pitches are never picked. Lead-ins like "So," are cut.',
            ],
        ],
        'map' => [
            'title' => 'Map the article',
            'tldr' => 'A timeline of its main points and questions to ask it.',
            'about' => ['The timeline cuts the article where the topic turns and shows the most typical sentence of each part. The questions come from the terms it keeps using.'],
        ],
        'laya' => [
            'title' => 'Ask Laya',
            'tldr' => 'Our model judges the counts, not the text, and gives the verdict.',
            'about' => [
                'Laya reads one paragraph of counts (shown under "What Laya was told") and answers two questions: is this slop, and how much real content does it have.',
                'If Laya is unavailable you still get the reading, without a verdict.',
            ],
        ],
        'save' => [
            'title' => 'Save the check',
            'tldr' => 'The result is stored with the link.',
            'about' => ['We keep the link, title, TL;DR, verdict and the reading of each sentence. Nothing about you is stored with it.'],
        ],
        'show' => [
            'title' => 'Show the result',
            'tldr' => 'Your browser draws the verdict, the TL;DR, the timeline and the Ask box.',
            'about' => ['Asking the article a question afterwards happens in your browser, apart from turning the question into numbers.'],
        ],
    ];

    private bool $started = false;

    public function __construct(private readonly bool $stream) {}

    /** Whether the request asked for the check as it happens. */
    public static function wanted(array $server): bool {
        return str_contains(strtolower((string) ($server['HTTP_ACCEPT'] ?? '')), self::TYPE);
    }

    /** The server has entered step $key (a key of STEPS). */
    public function step(string $key): void {
        if (!isset(self::STEPS[$key])) {
            throw new LogicException("No check step '$key'.");
        }
        if ($this->stream) {
            $this->line(['step' => $key]);
        }
    }

    public function finish(array $result): void {
        if ($this->stream) {
            $this->line(['result' => $result]);
            return;
        }
        self::headers(200, 'Content-Type: application/json');
        echo json_encode($result);
    }

    /** Ends the check with a message for the visitor. */
    public function fail(int $status, string $message): void {
        if ($this->started) {
            $this->line(['error' => $message, 'status' => $status]);
            return;
        }
        self::headers($status, 'Content-Type: application/json');
        echo json_encode(['error' => $message]);
    }

    private function line(array $event): void {
        if (!$this->started) {
            $this->started = true;
            // X-Accel-Buffering asks the proxy, and no-gzip the server, not
            // to hold the lines back.
            self::headers(200, 'Content-Type: ' . self::TYPE, 'Cache-Control: no-store', 'X-Accel-Buffering: no');
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
        echo json_encode($event), "\n";
        flush();
    }

    /** The status and headers, unless output has already begun (as in a test). */
    private static function headers(int $status, string ...$headers): void {
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        foreach ($headers as $header) {
            header($header);
        }
    }
}

/** A check refused for the visitor's input: its code is the HTTP status, its message is for them. */
class Check_refused extends RuntimeException {}
