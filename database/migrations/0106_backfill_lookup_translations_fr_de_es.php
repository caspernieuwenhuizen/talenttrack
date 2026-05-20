<?php
/**
 * Migration 0106 — backfill `tt_translations` rows for fr_FR, de_DE,
 * es_ES across every `tt_lookups` row, sourced from the plugin's
 * shipped `.po` files (#0798).
 *
 * Background: migration 0086 ran the same gettext-driven backfill but
 * only for `nl_NL` — because the runtime locale at migration time was
 * Dutch. The plugin has shipped `.po` files for fr_FR, de_DE, and
 * es_ES since v3.x, but those translations only resolved at render
 * time and never landed in `tt_translations`. Result: the frontend
 * lookup admin (#798) had no canonical translation to read or expose
 * in the per-locale inputs.
 *
 * This migration walks every `tt_lookups` row and, for each of the
 * three target locales, calls `__()` against the row's name and
 * description with that locale temporarily active (`switch_to_locale`).
 * When the translation differs from the English source, an
 * `INSERT IGNORE` writes a `tt_translations` row.
 *
 * Idempotent — the `INSERT IGNORE` on the unique
 * `(club_id, entity_type, entity_id, field, locale)` index is the
 * guarantee. Re-runs after this one are no-ops once the .po files
 * stop producing new translations.
 *
 * Operator-edited rows are preserved: anything already in
 * `tt_translations` (from migration 0086, the wp-admin form, or the
 * frontend form after #798's REST controller fix) is skipped by
 * `INSERT IGNORE`.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0106_backfill_lookup_translations_fr_de_es';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        if ( ! function_exists( 'switch_to_locale' ) || ! function_exists( 'restore_previous_locale' ) ) return;

        $rows = $wpdb->get_results(
            "SELECT id, club_id, name, description
               FROM {$lookups_table}
              WHERE name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) || empty( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now            = current_time( 'mysql', true );
        $target_locales = [ 'fr_FR', 'de_DE', 'es_ES' ];

        foreach ( $target_locales as $locale ) {
            // `switch_to_locale` returns false when the locale's .mo
            // file isn't present — bail out for this locale rather than
            // writing English values as translations.
            $switched = switch_to_locale( $locale );
            if ( ! $switched ) continue;
            if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
                load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages' );
            }

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
                        $locale,
                        $translated,
                        $now
                    ) );
                }
            }

            restore_previous_locale();
        }
    }
};
