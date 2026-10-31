<?php
/**
 * Migration 0306 — re-apply `LookupTranslationSeeds` once every seed
 * migration has created its rows (#4511).
 *
 * WHY A THIRD PASS
 *
 * Migrations 0151 and 0248 apply the curated map, but only to rows that
 * already exist when they run. Vocabularies seeded by a later migration
 * (`goal_set` in 0265, the re-keyed potential bands in 0292, and anything
 * added since) depend on that migration writing its own translations, and
 * on a fresh install a row whose seed skipped that step stays English.
 * Running the map again here, after all of them, closes that gap on fresh
 * installs and on upgraded ones alike.
 *
 * It also seeds the `position` labels the map now carries. Migration 0141
 * derived them through `switch_to_locale()` + gettext at migration time,
 * which inserts nothing where the language pack is not installed yet, so
 * a Dutch install could show "GK" where it meant "Keeper".
 *
 * `INSERT IGNORE`, deliberately, as in 0248: this fills gaps and never
 * overwrites a label an academy chose for itself. Forward-only and
 * idempotent; a second run inserts nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    public function getName(): string {
        return '0306_reseed_lookup_translations_after_seeds';
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
        if ( $map === [] ) return;

        $rows = $wpdb->get_results(
            "SELECT id, club_id, lookup_type, name
               FROM {$lookups_table}
              WHERE name IS NOT NULL AND name <> ''",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

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

                $this->exec( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations_table}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, %s, %d, %s, %s, %s, %s)",
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
