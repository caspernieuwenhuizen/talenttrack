<?php
/**
 * BehaviourRating — typed constants for the 1..5 scale stored on
 * `tt_player_behaviour_ratings.rating` (DECIMAL(3,1) column, but the
 * seeded lookup row set is the integer-keyed 1..5 vocabulary). Backs
 * the `behaviour_rating_label` lookup seeded by migration 0042 with
 * display labels:
 *
 *   1 — Concerning
 *   2 — Below expectations
 *   3 — Acceptable
 *   4 — Strong
 *   5 — Exemplary
 *
 * The DECIMAL column accepts non-integer values (e.g. 3.5) when a
 * coach captures a between-tier judgement; the five constants below
 * are the canonical anchor points each label maps to.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( (string) $row->rating === BehaviourRating::EXEMPLARY ) { ... }
 *     in_array( (string) $row->rating, BehaviourRating::ALL, true );
 *
 * SQL string / numeric literals stay as literals — DB is the source
 * of truth and the column is numeric.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class BehaviourRating {

    public const CONCERNING          = '1';
    public const BELOW_EXPECTATIONS  = '2';
    public const ACCEPTABLE          = '3';
    public const STRONG              = '4';
    public const EXEMPLARY           = '5';

    /** @var list<string> */
    public const ALL = [
        self::CONCERNING,
        self::BELOW_EXPECTATIONS,
        self::ACCEPTABLE,
        self::STRONG,
        self::EXEMPLARY,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
