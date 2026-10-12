<?php
/**
 * Laya, the decision model (https://pypi.org/project/laya/), over HTTP. It
 * runs as the Trongate.cloud project service "laya" (the recruiter's image,
 * ghcr.io/sasin91/recruiter-laya), and every app in the project gets its
 * address as LAYA_URL on its next deploy. LAYA_API_KEY is the service's
 * bearer key when it has one.
 *
 *   $laya = Laya_client::from_env(10);
 *   $answers = $laya?->ask($state, ['slop' => ['type' => 'noul', 'instructions' => '…']]);
 */
class Laya_client {

    // The English checkpoint: the multilingual one was near chance in the
    // recruiter's bake-off.
    public const MODEL = 'english';

    public function __construct(
        private readonly string $base_url,
        private readonly ?string $api_key = null,
        private readonly int $timeout = 20,
    ) {}

    /** The client for LAYA_URL and LAYA_API_KEY, or null when Laya isn't set up. */
    public static function from_env(int $timeout = 20): ?self {
        $url = self::env('LAYA_URL');
        return $url === null ? null : new self($url, self::env('LAYA_API_KEY'), $timeout);
    }

    /**
     * Laya's answers to $questions about a state, keyed like $questions: a
     * noul question answers ['noul' => probability], a score question
     * ['score' => expected level].
     *
     * @throws RuntimeException when the service can't be reached or fails
     */
    public function ask(string $state, array $questions): array {
        $response = $this->post(['state' => $state, 'questions' => $questions, 'model' => self::MODEL]);
        if (!is_array($response['answers'] ?? null)) {
            throw new RuntimeException('Laya answered without any answers.');
        }
        return $response['answers'];
    }

    /** POST /v1/systemone with $body, and the decoded response. */
    private function post(array $body): array {
        $curl = curl_init(rtrim($this->base_url, '/') . '/v1/systemone');
        $headers = ['Content-Type: application/json'];
        if ($this->api_key !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->api_key;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new RuntimeException("Laya could not be reached: $error");
        }
        $response = json_decode($raw, true);
        if ($status === 401) {
            throw new RuntimeException('Laya rejected the API key (LAYA_API_KEY).');
        }
        if ($status >= 400 || !is_array($response)) {
            $detail = is_string($response['detail'] ?? null) ? $response['detail'] : "HTTP $status";
            throw new RuntimeException("Laya failed: $detail");
        }
        return $response;
    }

    /** An environment variable however the server passes it, or null when unset. */
    private static function env(string $name): ?string {
        foreach ([getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null, $_SERVER["REDIRECT_$name"] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }
}
