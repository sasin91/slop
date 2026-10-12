<?php
/**
 * Saves each check to the `evaluations` table (sql/evaluations.sql): the
 * URL, title, TL;DR, Laya's verdict and scores, and the counts, stock words
 * and per-sentence reading as JSON.
 *
 * With no database configured (config/database.php, `database` empty, as
 * in a local checkout; trongate.cloud fills it in on deploy) it
 * saves nothing, so the page works without one.
 */
class Welcome_model extends Model {

    const TABLE = 'evaluations';

    /** Saves a check() result; its id, or null when there is no database. */
    public function save_evaluation(array $result): ?int {
        if (($GLOBALS['databases']['default']['database'] ?? '') === '') {
            return null;
        }
        if (!$this->db->table_exists(self::TABLE)) {
            $this->db->query(file_get_contents(__DIR__ . '/sql/evaluations.sql'));
        }
        return $this->db->insert(self::evaluation_row($result), self::TABLE);
    }

    /** A check() result as an `evaluations` row. */
    public static function evaluation_row(array $result): array {
        $url = $result['url'] ?? null;
        $laya = $result['laya'] ?? [];
        $json = fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [
            'url' => $url,
            'url_hash' => $url === null ? null : hash('sha256', $url),
            'title' => mb_substr((string) $result['title'], 0, 500, 'UTF-8'),
            'tldr' => $result['tldr'],
            'verdict' => $laya['verdict'] ?? null,
            'slop' => $laya['slop'] ?? null,
            'substance' => $laya['substance'] ?? null,
            'substance_label' => $laya['substance_label'] ?? null,
            'laya_error' => $result['laya_error'] ?? null,
            'counts' => $json($result['counts']),
            'stock_words' => $json((object) $result['stock_words']),
            'sentences' => $json($result['sentences']),
            'summary' => $result['summary'],
        ];
    }
}
