<?php
/**
 * Migration 0247 — repair lookup labels that migration 0086's gettext
 * backfill wrote in the wrong sense (#3082).
 *
 * THE BUG
 *
 * Migration 0086 walked every `tt_lookups` row, called
 * `__( $name, 'talenttrack' )`, and stored whatever gettext returned as
 * that row's translation. A bare msgid is written for wherever it first
 * appeared — usually mid-sentence prose — and carries that sense, its
 * case, and its part of speech into a slot that wanted a label.
 *
 * `foot_option` `Left` rendering as *Vertrokken* ("departed") was the
 * loud instance and migration 0242 repaired that one row. The quiet ones
 * are everywhere: `measurement_category` `Technical` picking up the
 * adjective *Technisch* where the label should read *Techniek*,
 * `task_status` `overdue` picking up a lowercase *te laat* that then
 * renders inside a status pill.
 *
 * WHAT MAKES THIS WORTH A MIGRATION RATHER THAN A LIST
 *
 * 0086 stored whatever the catalogue held **at the moment it ran on that
 * install**. The catalogue has moved many times since. Two installs that
 * migrated a month apart hold different labels for the same lookup, and
 * neither is inspectable from the source tree. Enumerating the wrong
 * values the way 0242 did would only ever cover the install the list was
 * written on, so this migration derives them instead: it asks gettext
 * the same question 0086 asked and repairs any row still holding that
 * answer.
 *
 * WHY IT ONLY REPAIRS DERIVED-WRONG VALUES
 *
 * 0242's discipline, kept exactly. "Stored value differs from the
 * curated seed" is NOT evidence of the bug — an academy is free to
 * rename anything, and migration 0151's `INSERT IGNORE` exists precisely
 * to respect that. But "stored value is character-for-character what a
 * bare `__()` returns for this name in this locale, and that is not the
 * curated value" IS evidence: nobody types that by coincidence.
 *
 * Two narrow additions to the derived set, in `KNOWN_BAD`: values the
 * curated seed itself got wrong. `goal_priority` `Medium` was seeded
 * `'Middel'` — "means / remedy / waist", not a middling priority — so
 * the bad value never came from gettext and cannot be derived from it.
 *
 * Expect this to change **nothing on a dev box** whose catalogue and
 * seeds already agree. That is the point. It matters on installs whose
 * 0086 ran against a different `.po` than the one shipping today; do not
 * read a local no-op as a broken migration.
 *
 * Forward-only and idempotent: a second run matches nothing, because the
 * value it looks for has been replaced.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    /**
     * Stored values known to be wrong that a bare `__()` will not
     * reproduce, because the seed rather than the catalogue was the
     * source of the error. Keyed lookup_type => canonical name =>
     * locale => list of bad values.
     *
     * @var array<string, array<string, array<string, list<string>>>>
     */
    private const KNOWN_BAD = [
        'goal_priority' => [
            'Medium' => [
                'nl_NL' => [ 'Middel' ],
            ],
        ],
    ];

    public function getName(): string {
        return '0247_repair_gettext_backfilled_lookup_labels';
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
        if ( ! is_array( $rows ) || $rows === [] ) return;

        // Only rows the curated map actually covers are candidates —
        // everything else has no known-good value to repair towards.
        $candidates = [];
        $names      = [];
        foreach ( $rows as $row ) {
            $type   = (string) ( $row['lookup_type'] ?? '' );
            $name   = (string) ( $row['name'] ?? '' );
            $row_id = (int) ( $row['id'] ?? 0 );
            if ( $row_id <= 0 || $type === '' || $name === '' ) continue;
            if ( ! isset( $map[ $type ][ $name ] ) || ! is_array( $map[ $type ][ $name ] ) ) continue;

            $candidates[]   = [
                'id'      => $row_id,
                'club_id' => isset( $row['club_id'] ) ? (int) $row['club_id'] : 1,
                'type'    => $type,
                'name'    => $name,
            ];
            $names[ $name ] = true;
        }
        if ( $candidates === [] ) return;

        $gettext = $this->gettextByLocale( array_keys( $names ) );
        $now     = current_time( 'mysql', true );

        foreach ( $candidates as $row ) {
            foreach ( $map[ $row['type'] ][ $row['name'] ] as $locale => $curated ) {
                $locale  = (string) $locale;
                $curated = (string) $curated;
                if ( $curated === '' ) continue;

                $wrong = self::KNOWN_BAD[ $row['type'] ][ $row['name'] ][ $locale ] ?? [];

                // The value 0086 would have written for this name in this
                // locale. Equal to the canonical name means the catalogue
                // has no entry, so 0086 wrote nothing worth repairing;
                // equal to the curated seed means it wrote the right thing.
                $derived = $gettext[ $locale ][ $row['name'] ] ?? '';
                if ( $derived !== '' && $derived !== $row['name'] && $derived !== $curated ) {
                    $wrong[] = $derived;
                }

                foreach ( array_unique( $wrong ) as $bad ) {
                    $this->exec( $wpdb->prepare(
                        "UPDATE {$translations_table}
                            SET value = %s, updated_at = %s
                          WHERE club_id = %d
                            AND entity_type = %s
                            AND entity_id = %d
                            AND field = %s
                            AND locale = %s
                            AND value = %s",
                        $curated,
                        $now,
                        $row['club_id'],
                        'lookup',
                        $row['id'],
                        'name',
                        $locale,
                        (string) $bad
                    ) );
                }

                // Fill an empty slot. INSERT IGNORE, so a label the
                // academy chose is never touched.
                $this->exec( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations_table}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, %s, %d, %s, %s, %s, %s)",
                    $row['club_id'],
                    'lookup',
                    $row['id'],
                    'name',
                    $locale,
                    $curated,
                    $now
                ) );
            }
        }
    }

    /**
     * Ask gettext, once per locale, what a bare `__( $name )` returns —
     * the same question migration 0086 asked. Switching the locale
     * reloads the plugin's textdomain, so this reads the shipped `.mo`
     * for each locale rather than the site's current one.
     *
     * Returns an empty map for a locale the site cannot switch to; the
     * caller then repairs only the `KNOWN_BAD` values for it, which is
     * the safe direction — comparing against the wrong catalogue could
     * overwrite a label an academy chose.
     *
     * @param list<string> $names
     * @return array<string, array<string, string>> locale => name => msgstr
     */
    private function gettextByLocale( array $names ): array {
        $out = [];
        if ( ! function_exists( 'switch_to_locale' ) || ! function_exists( 'restore_previous_locale' ) ) {
            return $out;
        }

        $current = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';

        foreach ( LookupTranslationSeeds::LOCALES as $locale ) {
            // `switch_to_locale()` returns false when the requested locale
            // is already active — which on a Dutch install is exactly the
            // locale that matters most. Read it in place instead of
            // treating the false as a failure.
            $switched = ( $locale !== $current ) && switch_to_locale( $locale );
            if ( ! $switched && $locale !== $current ) continue;

            $resolved = [];
            foreach ( $names as $name ) {
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
                $resolved[ $name ] = (string) __( $name, 'talenttrack' );
            }
            if ( $switched ) restore_previous_locale();
            $out[ $locale ] = $resolved;
        }

        return $out;
    }
};
