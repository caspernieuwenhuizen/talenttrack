<?php
/**
 * Migration 0131 — lookup translation seeds (v4.8.0 #985).
 *
 * Backfills `tt_translations` rows for every `tt_lookups` row x every
 * supported locale (en_US, nl_NL, de_DE, es_ES, fr_FR). The list-first
 * lookup admin (#985 spec item 1) surfaces an `en_US` row in the
 * translation grid alongside the other locales; without this seed the
 * grid would render blank for English on pre-existing rows because the
 * canonical English value historically lived only in the `tt_lookups.name`
 * column.
 *
 * Strategy per locale:
 *
 *   - **en_US**: seed from `tt_lookups.name` (the canonical column).
 *     This is the operator-editable English display value going
 *     forward; the `name` column is now treated as an immutable
 *     internal key.
 *
 *   - **nl_NL / de_DE / es_ES / fr_FR**: switch to that locale,
 *     re-load the `talenttrack` textdomain (clearing the gettext cache
 *     between iterations to avoid the 0106 bug — see 0109's recovery
 *     run for the original analysis), and pull `__( $name )` /
 *     `__( $description )` from the loaded .po. If the .po has no
 *     msgstr for that msgid, seed an empty string so the admin form
 *     surfaces the empty slot.
 *
 * Idempotent — uses `INSERT IGNORE` against the unique
 * `(club_id, entity_type, entity_id, field, locale)` index. Re-running
 * the migration on an already-seeded database is a no-op.
 *
 * Why a new migration vs. extending 0109: 0109 seeded fr/de/es from
 * the .po but skipped en_US (because en_US was the canonical column
 * back then). The new contract makes en_US a first-class translation
 * cell, so the migration also writes en_US. Plus the migration runner
 * skips entries already in `tt_migrations`, so re-applying 0109 is not
 * an option.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0131_lookup_translation_seeds';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        $rows = $wpdb->get_results(
            "SELECT id, club_id, name, description
               FROM {$lookups_table}
              WHERE name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            Logger::info( 'migration.0131.no_lookup_rows', [] );
            return;
        }

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";

        $now     = current_time( 'mysql', true );
        $summary = [];

        // Step 1: en_US seed straight from the canonical `name`
        // (and description) column. No locale switch needed.
        $en_scanned = 0;
        $en_written = 0;
        foreach ( $rows as $row ) {
            $club_id = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            $row_id  = (int) ( $row['id'] ?? 0 );
            if ( $row_id <= 0 ) continue;

            foreach ( [ 'name', 'description' ] as $field ) {
                $raw = (string) ( $row[ $field ] ?? '' );
                if ( $raw === '' ) continue;
                $en_scanned++;
                $ok = $wpdb->query( $wpdb->prepare(
                    $sql,
                    $club_id,
                    'lookup',
                    $row_id,
                    $field,
                    'en_US',
                    $raw,
                    $now
                ) );
                if ( $ok === 1 ) $en_written++;
            }
        }
        $summary['en_US'] = [
            'status'  => 'ok',
            'scanned' => $en_scanned,
            'written' => $en_written,
        ];

        // Step 2: localized seeds. Same loop pattern as migration 0109.
        if ( function_exists( 'switch_to_locale' ) && function_exists( 'restore_previous_locale' ) ) {
            $target_locales = [ 'nl_NL', 'de_DE', 'es_ES', 'fr_FR' ];
            foreach ( $target_locales as $locale ) {
                if ( function_exists( 'unload_textdomain' ) ) {
                    unload_textdomain( 'talenttrack', true );
                }

                $switched = switch_to_locale( $locale );
                if ( ! $switched ) {
                    $summary[ $locale ] = [ 'status' => 'switch_failed' ];
                    continue;
                }

                if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
                    load_plugin_textdomain(
                        'talenttrack',
                        false,
                        dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages'
                    );
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

            // Reset textdomain so the rest of the migration runner sees
            // a coherent gettext state. Mirrors 0109's tail-reset.
            if ( function_exists( 'unload_textdomain' ) ) {
                unload_textdomain( 'talenttrack', true );
            }
            if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
                load_plugin_textdomain(
                    'talenttrack',
                    false,
                    dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages'
                );
            }
        }

        Logger::info( 'migration.0131.summary', [
            'rows_in_tt_lookups' => count( $rows ),
            'per_locale'         => $summary,
        ] );
    }
};
