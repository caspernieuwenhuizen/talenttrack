<?php
namespace TT\Modules\Translations\Cache;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TranslationsCacheRepository — `tt_translations_cache` data access.
 *
 * Cache lookup keys on the four-tuple
 * (source_hash, source_lang, target_lang, engine). The `source_hash`
 * is a SHA-256 hex of the source string so we can dedupe across
 * fields that happen to carry identical text.
 */
final class TranslationsCacheRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_translations_cache';
    }

    public static function hash( string $source ): string {
        return hash( 'sha256', $source );
    }

    public function find( string $source_hash, string $source_lang, string $target_lang, string $engine ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE source_hash = %s AND source_lang = %s AND target_lang = %s AND engine = %s AND club_id = %d
             LIMIT 1",
            $source_hash, $source_lang, $target_lang, $engine, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function insert(
        string $source_hash,
        string $source_lang,
        string $target_lang,
        string $translated_text,
        string $engine,
        int $char_count
    ): bool {
        global $wpdb;
        $ok = $wpdb->insert( $this->table(), [
            'club_id'         => CurrentClub::id(),
            'source_hash'     => $source_hash,
            'source_lang'     => $source_lang,
            'target_lang'     => $target_lang,
            'translated_text' => $translated_text,
            'engine'          => $engine,
            'char_count'      => max( 0, min( 65535, $char_count ) ),
        ] );
        return $ok !== false;
    }

    /** Drop every cache row for a source string — invoked when the source is edited. */
    public function deleteForHash( string $source_hash ): int {
        global $wpdb;
        return (int) $wpdb->delete( $this->table(), [ 'source_hash' => $source_hash, 'club_id' => CurrentClub::id() ] );
    }

    /** Wipe the entire cache — invoked on opt-out per #0025 GDPR posture. */
    public function truncate(): int {
        global $wpdb;
        return (int) $wpdb->query( "TRUNCATE TABLE {$this->table()}" );
    }

    /** @return array<int, object> */
    public function listRecent( int $limit = 50 ): array {
        global $wpdb;
        $limit = max( 1, min( 500, $limit ) );
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE club_id = %d ORDER BY created_at DESC LIMIT {$limit}",
            CurrentClub::id()
        ) );
    }

    public function size(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE club_id = %d", CurrentClub::id() ) );
    }
}
