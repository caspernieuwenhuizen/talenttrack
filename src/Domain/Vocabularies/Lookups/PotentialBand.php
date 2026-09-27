<?php
/**
 * PotentialBand — typed constants for the five values stored on
 * `tt_player_potential.potential_band`.
 *
 * The bands say where a player stands **against their age group and the
 * academy pathway**, not how far they will go as an adult. A youth academy
 * decides keep, push up a year, give time or release; it does not decide a
 * career ceiling, and asking a coach for one below the age where it means
 * anything produced guesses (#3981).
 *
 * The keys, their order and the score each carries are fixed here and in
 * `PlayerStatusCalculator`. The **display label** is the `potential_band`
 * lookup's, which an academy may rename per language through the lookup
 * admin; renaming a band never moves a player's status.
 *
 * Bands, best first:
 *
 *   EXCEPTIONAL — well beyond the age group; a candidate to play up
 *   AHEAD       — ahead of the age group
 *   ON_TRACK    — where the pathway expects them to be
 *   NEEDS_TIME  — behind for now, with a reason to give them time
 *   BELOW_LEVEL — below the level the academy works at
 *
 * `LEGACY` maps the five adult-ceiling keys the bands replaced, rank for
 * rank. The REST write path accepts them for one release and stores the
 * mapped key; see docs/rest-api.md for the deprecation.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PotentialBand {

    public const EXCEPTIONAL = 'exceptional';
    public const AHEAD       = 'ahead';
    public const ON_TRACK    = 'on_track';
    public const NEEDS_TIME  = 'needs_time';
    public const BELOW_LEVEL = 'below_level';

    /** @var list<string> */
    public const ALL = [
        self::EXCEPTIONAL,
        self::AHEAD,
        self::ON_TRACK,
        self::NEEDS_TIME,
        self::BELOW_LEVEL,
    ];

    /**
     * The retired adult-ceiling keys, mapped by rank to the pathway keys.
     *
     * @var array<string,string>
     */
    public const LEGACY = [
        'first_team'             => self::EXCEPTIONAL,
        'professional_elsewhere' => self::AHEAD,
        'semi_pro'               => self::ON_TRACK,
        'top_amateur'            => self::NEEDS_TIME,
        'recreational'           => self::BELOW_LEVEL,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }

    /**
     * The current key for a value, mapping a retired key to its successor.
     * Anything else is returned unchanged, so a caller still validates it.
     */
    public static function normalise( string $value ): string {
        return self::LEGACY[ $value ] ?? $value;
    }
}
