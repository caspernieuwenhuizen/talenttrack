<?php
/**
 * Migration 0151 — seed tt_translations for lookup display labels across
 * nl_NL / fr_FR / de_DE / es_ES from the curated LookupTranslationSeeds map
 * (#1442).
 *
 * For every tt_lookups row whose (lookup_type, name) has a curated
 * translation, INSERT IGNORE a tt_translations row per locale. INSERT IGNORE
 * on the unique key means it fills gaps without overwriting operator edits or
 * earlier backfills (0086/0106/0109/0141/0142). Forward-only + idempotent.
 *
 * en_US is never written: the canonical English value is tt_lookups.name, the
 * resolver's fallback. Locale-invariant codes (age-group U-codes, positions,
 * UEFA cert grades) carry no map entry and are skipped.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    public function getName(): string {
        return '0151_seed_lookup_translations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;
        if ( ! class_exists( LookupTranslationSeeds::class ) ) return;

        $map = LookupTranslationSeeds::map();
        if ( empty( $map ) ) return;

        $rows = $wpdb->get_results(
            "SELECT id, club_id, lookup_type, name
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
            $type   = (string) ( $row['lookup_type'] ?? '' );
            $name   = (string) ( $row['name'] ?? '' );
            $row_id = (int) ( $row['id'] ?? 0 );
            $club   = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            if ( $row_id <= 0 || $type === '' || $name === '' ) continue;

            $locales = $map[ $type ][ $name ] ?? null;
            if ( ! is_array( $locales ) ) continue;

            foreach ( $locales as $locale => $value ) {
                $value = (string) $value;
                if ( $value === '' ) continue;
                $wpdb->query( $wpdb->prepare(
                    $sql,
                    $club,
                    'lookup',
                    $row_id,
                    'name',
                    (string) $locale,
                    $value,
                    $now
                ) );
            }
        }
    }
};
