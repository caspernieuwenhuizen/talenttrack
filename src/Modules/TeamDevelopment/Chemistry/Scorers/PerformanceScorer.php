<?php
namespace TT\Modules\TeamDevelopment\Chemistry\Scorers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Chemistry\ComponentScore;
use TT\Modules\TeamDevelopment\Chemistry\PairContext;
use TT\Modules\TeamDevelopment\Chemistry\PlayerChemistryProfile;

/**
 * PerformanceScorer — spec component 4.5 (shared match outcomes, points per
 * match, goal difference).
 *
 * v1 formula: a saturating function of how many completed games the two
 * players have appeared in together — a participation proxy that rises to
 * 100 at ~15 shared games. Neutral when they've shared none.
 *
 * Refinement (documented gap, future): blend in actual results when both
 * played — points-per-match and goal difference — once a per-match
 * lineup + result join is available. The shared-game count is the honest v1
 * signal until then; `has_data` stays false-ish via the neutral fallback so
 * the UI can flag that results aren't wired yet.
 */
final class PerformanceScorer implements ComponentScorer {

    private const GAME_SATURATION = 15.0;

    public function key(): string { return 'performance'; }

    public function score( PlayerChemistryProfile $a, PlayerChemistryProfile $b, PairContext $pair ): ComponentScore {
        if ( $pair->shared_games <= 0 ) {
            return ComponentScore::neutral( __( 'No shared games yet', 'talenttrack' ) );
        }

        $value   = min( 100.0, $pair->shared_games / self::GAME_SATURATION * 100.0 );
        $reasons = [];
        if ( $pair->shared_games >= 8 ) {
            $reasons[] = __( 'Played many games together', 'talenttrack' );
        }

        return ComponentScore::clamp( $value, true, $reasons );
    }
}
