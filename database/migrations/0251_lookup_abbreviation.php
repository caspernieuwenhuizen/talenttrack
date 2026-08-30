<?php
/**
 * Migration: 0251_lookup_abbreviation
 *
 * #3246 — `abbreviation` on `tt_lookups`, and the canonical position
 * codes seeded into it.
 *
 * WHAT THE SHORT CODE ACTUALLY WAS
 *
 * Nothing. "Keeper GK" on the lookups list and the `CB` / `CDM` chips on
 * the player form were both printing `tt_lookups.name` — the internal
 * key, a stable database identifier that is deliberately immutable and
 * deliberately language-neutral. The eleven seeded positions got away
 * with it because their keys happen to be football codes. A position an
 * academy adds itself does not: the pilot install had two rows rendering
 * as `linker_middenvelder` and `rechter_middenvelder` on the most-used
 * screen in the plugin.
 *
 * WHY A COLUMN AND A TRANSLATION ROW
 *
 * Same shape as `name`: the column is the canonical value and the
 * backstop, `tt_translations` holds the per-locale ones. That is what
 * lets a Dutch install read `K` where an English one reads `GK` without
 * touching the identifier either of them joins on.
 *
 * VARCHAR(16), not (4) — the ceiling is there to stop an operator
 * pasting a sentence, not to enforce a house style, and a locale that
 * abbreviates less aggressively than English should not hit a wall.
 *
 * ENGLISH IS SEEDED; DUTCH IS NOT
 *
 * The eleven canonical rows get their existing key copied into the
 * column and into an `en_US` translation row, so nothing an academy sees
 * today changes. No `nl_NL` values are seeded, on purpose: the Dutch
 * shorthand collides with itself — *linksback* and *linksbuiten* both
 * want `LB` — and guessing wrong would be worse than an English code an
 * operator can plainly see needs replacing. The field is theirs to fill.
 *
 * `INSERT IGNORE` on the translation rows and a NULL check on the
 * column: this fills gaps, never overwrites a code an academy chose.
 * Idempotent, and an install whose positions were renamed away from the
 * canonical keys simply matches nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /**
     * Canonical position keys — the same list `LookupCanonicalSeeds`
     * seeds. Each is its own abbreviation, which is the whole reason
     * the raw key passed for one this long.
     *
     * @var list<string>
     */
    private const POSITION_CODES = [ 'GK', 'CB', 'LB', 'RB', 'CDM', 'CM', 'CAM', 'LW', 'RW', 'ST', 'CF' ];

    public function getName(): string {
        return '0251_lookup_abbreviation';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups = "{$p}tt_lookups";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$lookups} LIKE %s", 'abbreviation' ) );
        if ( empty( $exists ) ) {
            $this->exec( "ALTER TABLE {$lookups} ADD COLUMN abbreviation VARCHAR(16) NULL DEFAULT NULL AFTER description" );
        }

        $placeholders = implode( ', ', array_fill( 0, count( self::POSITION_CODES ), '%s' ) );

        // Column: copy the key across where the operator has not already
        // set something. NULL check, not empty-string — a row an academy
        // deliberately blanked stays blank.
        $this->exec( $wpdb->prepare(
            "UPDATE {$lookups}
                SET abbreviation = name
              WHERE lookup_type = 'position'
                AND abbreviation IS NULL
                AND name IN ({$placeholders})",
            ...self::POSITION_CODES
        ) );

        $translations = "{$p}tt_translations";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) !== $translations ) return;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, club_id, name
               FROM {$lookups}
              WHERE lookup_type = 'position'
                AND name IN ({$placeholders})",
            ...self::POSITION_CODES
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) return;

        $now = current_time( 'mysql', true );

        foreach ( $rows as $row ) {
            $row_id = (int) ( $row['id'] ?? 0 );
            $name   = (string) ( $row['name'] ?? '' );
            $club   = isset( $row['club_id'] ) ? (int) $row['club_id'] : 1;
            if ( $row_id <= 0 || $name === '' ) continue;

            $this->exec( $wpdb->prepare(
                "INSERT IGNORE INTO {$translations}
                   (club_id, entity_type, entity_id, field, locale, value, updated_at)
                 VALUES (%d, %s, %d, %s, %s, %s, %s)",
                $club,
                'lookup',
                $row_id,
                'abbreviation',
                'en_US',
                $name,
                $now
            ) );
        }
    }
};
