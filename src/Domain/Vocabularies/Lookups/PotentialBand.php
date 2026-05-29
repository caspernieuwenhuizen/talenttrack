<?php
/**
 * PotentialBand — typed constants for the five values stored on
 * `tt_player_potential.potential_band`. Backs the `potential_band`
 * lookup seeded by migration 0042 with operator-editable display
 * labels (English / Dutch / etc. via `tt_translations`). The five
 * keys themselves are the canonical contract between the
 * `PlayerStatusCalculator` (which scores each band 100 / 80 / 60 / 40
 * / 20) and every UI that captures the trainer's stated belief about
 * how high the player can reach.
 *
 * Bands, top to bottom:
 *
 *   FIRST_TEAM             — eventual first-team contributor at the club
 *   PROFESSIONAL_ELSEWHERE — pro at another club / level
 *   SEMI_PRO               — semi-professional
 *   TOP_AMATEUR            — top of the amateur pyramid
 *   RECREATIONAL           — recreational level
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->potential_band === PotentialBand::FIRST_TEAM ) { ... }
 *     [ 'potential_band' => PotentialBand::SEMI_PRO ]
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one
 * release per #988's backward-compat allowlist; see docs/rest-api.md
 * for the deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PotentialBand {

    public const FIRST_TEAM             = 'first_team';
    public const PROFESSIONAL_ELSEWHERE = 'professional_elsewhere';
    public const SEMI_PRO               = 'semi_pro';
    public const TOP_AMATEUR            = 'top_amateur';
    public const RECREATIONAL           = 'recreational';

    /** @var list<string> */
    public const ALL = [
        self::FIRST_TEAM,
        self::PROFESSIONAL_ELSEWHERE,
        self::SEMI_PRO,
        self::TOP_AMATEUR,
        self::RECREATIONAL,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
