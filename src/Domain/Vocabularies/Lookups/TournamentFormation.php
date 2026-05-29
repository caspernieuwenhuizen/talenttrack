<?php
/**
 * TournamentFormation — typed constants for the values stored in
 * `tt_tournaments.default_formation` and `tt_tournament_matches.formation`.
 * Backs the `tournament_formation` lookup (operator-editable) seeded by
 * migration 0098 in canonical hyphen-numeric form (`1-4-3-3`, `1-4-4-2`,
 * `1-3-4-3`, `1-3-5-2`, `1-4-2-3-1`, `1-2-3-2`, `1-2-3-1`, `1-1-3-1`).
 *
 * Stored values are the lookup row `name` — operators can add their own
 * formations from the lookups admin (8v8, 6v6, custom shapes) and they
 * will appear automatically next to the seeded set. The constants below
 * enumerate the canonical eight; code-side comparisons against any of
 * them should use the constant, not the literal.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $match->formation === TournamentFormation::F_4_3_3 ) { ... }
 *
 * SQL string literals (`WHERE formation = '1-4-3-3'`) stay as literals —
 * DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TournamentFormation {

    public const F_4_3_3    = '1-4-3-3';
    public const F_4_4_2    = '1-4-4-2';
    public const F_3_4_3    = '1-3-4-3';
    public const F_3_5_2    = '1-3-5-2';
    public const F_4_2_3_1  = '1-4-2-3-1';
    public const F_2_3_2    = '1-2-3-2';
    public const F_2_3_1    = '1-2-3-1';
    public const F_1_3_1    = '1-1-3-1';

    /** @var list<string> */
    public const ALL = [
        self::F_4_3_3,
        self::F_4_4_2,
        self::F_3_4_3,
        self::F_3_5_2,
        self::F_4_2_3_1,
        self::F_2_3_2,
        self::F_2_3_1,
        self::F_1_3_1,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
