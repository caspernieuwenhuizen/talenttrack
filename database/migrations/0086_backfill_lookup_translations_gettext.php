<?php
/**
 * Migration 0086 — second-pass lookup translation backfill via
 * gettext (#0090 Phase 6).
 *
 * Migration 0082 (Phase 2) backfilled `tt_translations` from the
 * `tt_lookups.translations` JSON column. That caught every row where
 * an operator (or seed migration) had explicitly stored a per-locale
 * translation in JSON. It did NOT catch rows whose Dutch translation
 * existed only in `nl_NL.po` — i.e. seeded English values translated
 * via gettext at render time.
 *
 * Phase 6 prepares to drop both (a) the legacy JSON column and (b)
 * the `LookupTranslator::name()` gettext fallback. Before either of
 * those can land safely, every Dutch translation reachable through
 * gettext must have a matching `tt_translations` row. This migration
 * is the catch-up: it walks every `tt_lookups` row, calls
 * `__($name, 'talenttrack')`, and `INSERT IGNORE`s a `nl_NL` row when
 * gettext returns a different string. Same shape as migration 0084
 * (eval categories) and 0085 (roles + functional roles).
 *
 * Operator-edited rows from migration 0082 stay untouched —
 * `INSERT IGNORE` against the unique `(club_id, entity_type, entity_id,
 * field, locale)` index is the guarantee.
 *
 * Idempotent: re-runs are no-ops once every translatable row has
 * a `nl_NL` row. Defensive against missing tables.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0086_backfill_lookup_translations_gettext';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
            load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages' );
        }

        $rows = $wpdb->get_results(
            "SELECT id, club_id, name, description
               FROM {$lookups_table}
              WHERE name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            $row_id  = (int) ( $row['id'] ?? 0 );
            if ( $row_id <= 0 ) continue;

            foreach ( [ 'name', 'description' ] as $field ) {
                $raw = (string) ( $row[ $field ] ?? '' );
                if ( $raw === '' ) continue;

                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
                $translated = (string) __( $raw, 'talenttrack' );
                if ( $translated === '' || $translated === $raw ) continue;

                $wpdb->query( $wpdb->prepare(
                    $sql,
                    $club_id,
                    'lookup',
                    $row_id,
                    $field,
                    'nl_NL',
                    $translated,
                    $now
                ) );
            }
        }
    }
};
