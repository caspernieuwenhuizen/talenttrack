<?php
/**
 * Migration 0248 — re-apply `LookupTranslationSeeds` with the corrected
 * keys (#3117).
 *
 * WHY A SECOND MIGRATION RATHER THAN RE-RUNNING 0151
 *
 * Migration `0151_seed_lookup_translations` walks the curated map and
 * `INSERT IGNORE`s a `tt_translations` row per locale. Re-running it
 * would be safe — `INSERT IGNORE` is idempotent — but a completed
 * migration does not re-run on its own, so every install that already
 * has 0151 in `tt_migrations` would never see the corrected map. Hence
 * a new file with the same body.
 *
 * WHAT WAS WRONG WITH THE OLD MAP
 *
 * 68 of its entries matched no `tt_lookups` row, so 0151 seeded nothing
 * at all for 13 of the 20 types it claims to cover. The vocabularies had
 * been renamed underneath it: `journey_event_type` moved from Title Case
 * labels to snake_case keys, `activity_type` from `Match` to `game`,
 * `competition_type` was renamed wholesale to `game_subtype` by
 * migration 0027, `behaviour_rating_label` and `potential_band` were
 * rewritten by 0153, and `eval_category` left `tt_lookups` entirely in
 * 0008.
 *
 * **`INSERT IGNORE` against a key that matches nothing is a no-op with
 * no error**, which is why none of that surfaced. `LookupSeedMapCoverageTest`
 * now fails on a map entry that resolves to no row, so it cannot drift
 * silently again.
 *
 * WHO THIS ACTUALLY HELPS
 *
 * Mostly not Dutch installs. Migration 0086 backfilled `nl_NL` from
 * gettext, so the gap was masked there. `fr_FR` / `de_DE` / `es_ES` had
 * no equivalent backfill, so an orphaned seed meant those locales fell
 * through to raw English — 136 lookup labels covered out of 263 on a
 * reference install, rising to 172 after this runs.
 *
 * `INSERT IGNORE`, deliberately: this fills gaps and never overwrites a
 * label an academy chose for itself. Repairing a *wrong* stored value is
 * migration 0247's job and has a much narrower test for what counts as
 * damage. Forward-only and idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    public function getName(): string {
        return '0248_reseed_lookup_translations';
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
