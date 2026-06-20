<?php
/**
 * Migration 0163 — backfill Dutch (and best-effort other-locale) display
 * labels for the age_group lookup vocabulary into tt_translations (#1528).
 *
 * Migration 0151 already seeded lookup translations from
 * LookupTranslationSeeds::map(), but at the time the age_group section only
 * carried Senior — the U-codes (U7…U23) had no Dutch value, so they
 * rendered as the raw English "U14" on nl_NL sites. The map now maps
 * U7…U21/U23 → O7…O21/O23 for nl_NL; 0151 won't re-run, so this migration
 * applies the new entries on existing installs.
 *
 * INSERT IGNORE on the (club_id, entity_type, entity_id, field, locale)
 * unique key makes it non-clobbering: a club's manual edit (e.g. an
 * existing U19 → O19 set via the lookup-label admin) already has a row and
 * is left untouched. Only the age_group slice of the map is processed.
 * Forward-only + idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    public function getName(): string {
        return '0163_seed_age_group_dutch_labels';
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
        $age = $map['age_group'] ?? null;
        if ( ! is_array( $age ) || empty( $age ) ) return;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, club_id, name
               FROM {$lookups_table}
              WHERE lookup_type = %s AND name IS NOT NULL AND name <> ''",
            'age_group'
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$translations_table}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";
        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $name   = (string) ( $row['name'] ?? '' );
            $row_id = (int) ( $row['id'] ?? 0 );
            $club   = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            if ( $row_id <= 0 || $name === '' ) continue;

            $locales = $age[ $name ] ?? null;
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
