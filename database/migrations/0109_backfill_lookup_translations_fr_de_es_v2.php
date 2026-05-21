<?php
/**
 * Migration 0109 — re-run the 0106 backfill, but unload the
 * `talenttrack` textdomain between locale switches so the
 * gettext lookups actually pick up fr_FR / de_DE / es_ES.
 *
 * Why a new migration: 0106 was applied on the pilot but produced
 * zero `tt_translations` rows for fr/de/es. Root cause (most
 * likely): `load_plugin_textdomain()` short-circuits when the
 * textdomain instance is already in memory — so after the first
 * locale, subsequent `switch_to_locale()` + `load_plugin_textdomain()`
 * calls returned the previously-cached gettext map, and
 * `__($raw)` kept returning the English source. The migration
 * detected `$translated === $raw` and skipped every row.
 *
 * The same migration can't be re-applied because the runner
 * skips entries already in `tt_migrations`. This is a new file
 * (`0109_…_v2.php`) so it runs even on installs where 0106 has
 * already succeeded silently.
 *
 * Fix: between locales, call `unload_textdomain('talenttrack', true)`
 * before `load_plugin_textdomain()` so the gettext cache is reset
 * to the just-switched locale. Plus per-locale Logger output so
 * the operator (and future-me debugging this) can see how many
 * rows the migration wrote.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0109_backfill_lookup_translations_fr_de_es_v2';
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
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            Logger::info( 'migration.0109.no_lookup_rows', [] );
            return;
        }

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now            = current_time( 'mysql', true );
        $target_locales = [ 'fr_FR', 'de_DE', 'es_ES' ];
        $summary        = [];

        foreach ( $target_locales as $locale ) {
            // Unload first so a previously-loaded textdomain (Dutch
            // from 0086, English from the previous iteration of this
            // very loop) doesn't shadow the locale we just switched to.
            if ( function_exists( 'unload_textdomain' ) ) {
                unload_textdomain( 'talenttrack', true );
            }

            // `switch_to_locale` returns false when the locale's .mo
            // file isn't present — bail out for this locale rather than
            // writing English values as translations.
            $switched = switch_to_locale( $locale );
            if ( ! $switched ) {
                $summary[ $locale ] = [ 'status' => 'switch_failed' ];
                continue;
            }

            if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
                load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages' );
            }

            $scanned    = 0;
            $translated = 0;
            $written    = 0;

            foreach ( $rows as $row ) {
                $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
                $row_id  = (int) ( $row['id'] ?? 0 );
                if ( $row_id <= 0 ) continue;

                foreach ( [ 'name', 'description' ] as $field ) {
                    $raw = (string) ( $row[ $field ] ?? '' );
                    if ( $raw === '' ) continue;
                    $scanned++;

                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
                    $tr = (string) __( $raw, 'talenttrack' );
                    if ( $tr === '' || $tr === $raw ) continue;
                    $translated++;

                    $ok = $wpdb->query( $wpdb->prepare(
                        $sql,
                        $club_id,
                        'lookup',
                        $row_id,
                        $field,
                        $locale,
                        $tr,
                        $now
                    ) );
                    if ( $ok === 1 ) $written++;
                }
            }

            $summary[ $locale ] = [
                'status'     => 'ok',
                'scanned'    => $scanned,
                'translated' => $translated,
                'written'    => $written,
            ];

            restore_previous_locale();
        }

        // Reset textdomain to the runtime locale so the rest of the
        // migration runner sees a coherent gettext state.
        if ( function_exists( 'unload_textdomain' ) ) {
            unload_textdomain( 'talenttrack', true );
        }
        if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
            load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages' );
        }

        Logger::info( 'migration.0109.summary', [
            'rows_in_tt_lookups' => count( $rows ),
            'per_locale'         => $summary,
        ] );
    }
};
