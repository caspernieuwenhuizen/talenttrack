<?php
/**
 * Migration 0242 — repair the Dutch label for the `Left` foot option
 * (#3031).
 *
 * THE BUG
 *
 * A player whose preferred foot was `Left` read **"Vertrokken"** in Dutch:
 * the sense of having left the academy.
 *
 * Two migrations conspired. Migration 0086 walked every `tt_lookups` row,
 * called `__( $name, 'talenttrack' )`, and stored whatever gettext
 * returned as that row's `nl_NL` translation. The catalogue's only
 * `msgid "Left"` at the time belonged to the media-retention table's
 * departure column, so the `foot_option` row for `Left` was given
 * "Vertrokken". Migration 0151 later seeded the curated value "Links",
 * but with `INSERT IGNORE` — deliberately, so it would not overwrite
 * operator edits — and the slot was already taken.
 *
 * The source-side half is fixed: `FrontendMediaRetentionView` now uses
 * `_x( 'Left', 'date a player left the academy' )`, so the two senses are
 * separate gettext keys and no future backfill can confuse them again.
 * That does nothing for installs where the wrong value is already sitting
 * in `tt_translations`, which is what this migration is for.
 *
 * WHY IT ONLY REPAIRS KNOWN WRONG VALUES
 *
 * "Stored value differs from the curated seed" is not evidence of the
 * bug — an academy is free to rename `Left` to whatever it likes, and
 * migration 0151's `INSERT IGNORE` exists precisely to respect that. So
 * the repair is keyed on the exact strings migration 0086 could have
 * written from the wrong-sense catalogue. Anything else an install holds
 * is treated as intent and left alone.
 *
 * `Right` and `Both` had no msgid at all, so 0086 wrote nothing for them
 * and 0151 seeded them correctly. They are re-seeded below only as a gap
 * fill for installs that somehow missed 0151.
 *
 * Forward-only and idempotent: a second run matches nothing, because the
 * value it looks for is gone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    /**
     * Values migration 0086 could have written into a `foot_option` row
     * from the wrong-sense catalogue, per locale, keyed by the canonical
     * lookup name.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const WRONG_SENSE = [
        'Left' => [
            'nl_NL' => [ 'Vertrokken' ],
        ],
    ];

    public function getName(): string {
        return '0242_repair_foot_option_translations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = "{$p}tt_lookups";
        $translations_table = "{$p}tt_translations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;
        if ( ! class_exists( LookupTranslationSeeds::class ) ) return;

        $curated = LookupTranslationSeeds::map()['foot_option'] ?? [];
        if ( ! is_array( $curated ) || $curated === [] ) return;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, club_id, name
                   FROM {$lookups_table}
                  WHERE lookup_type = %s
                    AND name IS NOT NULL AND name <> ''",
                'foot_option'
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return;

        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $row_id = (int) ( $row['id'] ?? 0 );
            $name   = (string) ( $row['name'] ?? '' );
            $club   = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            if ( $row_id <= 0 || $name === '' ) continue;

            $locales = $curated[ $name ] ?? null;
            if ( ! is_array( $locales ) ) continue;

            foreach ( $locales as $locale => $value ) {
                $value  = (string) $value;
                $locale = (string) $locale;
                if ( $value === '' ) continue;

                // 1. Overwrite a value the gettext backfill got wrong.
                foreach ( self::WRONG_SENSE[ $name ][ $locale ] ?? [] as $wrong ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$translations_table}
                            SET value = %s, updated_at = %s
                          WHERE club_id = %d
                            AND entity_type = %s
                            AND entity_id = %d
                            AND field = %s
                            AND locale = %s
                            AND value = %s",
                        $value,
                        $now,
                        $club,
                        'lookup',
                        $row_id,
                        'name',
                        $locale,
                        $wrong
                    ) );
                }

                // 2. Fill the slot when nothing occupies it. INSERT IGNORE,
                //    so an operator's own label is never touched.
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations_table}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, %s, %d, %s, %s, %s, %s)",
                    $club,
                    'lookup',
                    $row_id,
                    'name',
                    $locale,
                    $value,
                    $now
                ) );
            }
        }
    }
};
