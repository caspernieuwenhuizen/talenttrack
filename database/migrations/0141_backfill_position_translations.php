<?php
/**
 * Migration 0141 — Backfill `tt_translations` for `tt_lookups`
 * positions across nl_NL / fr_FR / de_DE / es_ES (#902).
 *
 * Migrations 0086, 0106, 0109 ran `__($row['name'], 'talenttrack')`
 * on each lookup row. For positions that meant `__('GK')`, `__('CB')`
 * — codes the .po files have no msgids for — so the guard skipped
 * the INSERT and `tt_translations` stayed empty. Pilot ran into the
 * gap on the operator-facing Lookups admin (every position row
 * showed an empty translation cell).
 *
 * This migration drives the long form through gettext instead:
 *
 *   GK → 'Goalkeeper' (via LabelTranslator::positionLongForm)
 *      → switch_to_locale('nl_NL') → __('Goalkeeper') → 'Keeper'
 *      → INSERT IGNORE row in tt_translations
 *
 * Forward-only + idempotent — INSERT IGNORE on the unique key, so
 * re-running on installs that already have translations is a no-op.
 *
 * Same locale set as 0106/0109. Locales beyond es_ES that depend on
 * this surface have not shipped, so adding them here would be dead
 * code.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Query\LabelTranslator;

return new class extends Migration {

    public function getName(): string {
        return '0141_backfill_position_translations';
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
            "SELECT id, club_id, name
               FROM {$lookups_table}
              WHERE lookup_type = 'position'
                AND name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";
        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $club_id  = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            $row_id   = (int) ( $row['id'] ?? 0 );
            $code     = (string) ( $row['name'] ?? '' );
            if ( $row_id <= 0 || $code === '' ) continue;

            $longform = LabelTranslator::positionLongForm( $code );
            if ( $longform === '' || $longform === $code ) continue;

            foreach ( [ 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ] as $loc ) {
                $switched = function_exists( 'switch_to_locale' ) ? switch_to_locale( $loc ) : false;

                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- delegated dynamic key resolved from LabelTranslator::positionLongForm()'s static switch.
                $translated = (string) __( $longform, 'talenttrack' );

                if ( $switched && function_exists( 'restore_previous_locale' ) ) {
                    restore_previous_locale();
                }
                if ( $translated === '' || $translated === $longform ) continue;

                $wpdb->query( $wpdb->prepare(
                    $sql,
                    $club_id,
                    'lookup',
                    $row_id,
                    'name',
                    $loc,
                    $translated,
                    $now
                ) );
            }
        }
    }
};
