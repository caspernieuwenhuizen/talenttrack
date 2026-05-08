<?php
/**
 * Migration 0082 — backfill `tt_translations` from the legacy
 * `tt_lookups.translations` JSON column (#0090 Phase 2).
 *
 * Phase 1 (migration 0080) created the `tt_translations` table; Phase
 * 2 begins migrating data-row strings into it, starting with lookups.
 *
 * For every `tt_lookups` row that has a non-empty `translations` JSON
 * blob, decode it and insert one `tt_translations` row per
 * `(locale, field)` pair where field is `name` or `description` and
 * the value is non-empty. Existing `tt_translations` rows for the
 * same `(club_id, entity_type, entity_id, field, locale)` tuple are
 * preserved (operator may have already edited via a future Phase 5
 * Translations tab in a follow-up build) — `INSERT IGNORE` against the
 * unique index handles that.
 *
 * The legacy JSON column is NOT dropped here. `LookupTranslator` keeps
 * it as a transition fallback during Phase 2-5; Phase 6 cleanup will
 * drop the column once `nl_NL.po` is also pruned.
 *
 * Idempotent. Safe to re-run on already-backfilled installs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0082_backfill_lookup_translations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        // The `translations` column was added by migration 0014. Defensive:
        // if a fresh install somehow runs 0082 before 0014, skip rather
        // than fatal. Migrations execute in numeric order so this is just
        // belt + braces.
        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$lookups_table} LIKE %s",
            'translations'
        ) );
        if ( $col === null ) return;

        // Read every row that has a translations payload. `club_id`
        // column was added by the tenancy scaffold (#0052 PR-A) — the
        // backfill rows preserve whichever club the source lookup
        // belongs to, so multi-tenant installs land cleanly.
        $rows = $wpdb->get_results(
            "SELECT id, club_id, translations
               FROM {$lookups_table}
              WHERE translations IS NOT NULL AND translations <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $club_id   = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            $lookup_id = (int) ( $row['id'] ?? 0 );
            $payload   = $this->decode( (string) ( $row['translations'] ?? '' ) );
            if ( $lookup_id <= 0 || ! $payload ) continue;

            foreach ( $payload as $locale => $fields ) {
                $locale = (string) $locale;
                if ( $locale === '' || ! is_array( $fields ) ) continue;

                foreach ( [ 'name', 'description' ] as $field ) {
                    $value = isset( $fields[ $field ] ) ? trim( (string) $fields[ $field ] ) : '';
                    if ( $value === '' ) continue;

                    $wpdb->query( $wpdb->prepare(
                        $sql,
                        $club_id,
                        'lookup',
                        $lookup_id,
                        $field,
                        $locale,
                        $value,
                        $now
                    ) );
                }
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decode( string $raw ): array {
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
};
