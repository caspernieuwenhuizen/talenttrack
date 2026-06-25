<?php
namespace TT\Modules\TeamDevelopment\Chemistry\Scorers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Chemistry\ComponentScore;
use TT\Modules\TeamDevelopment\Chemistry\PairContext;
use TT\Modules\TeamDevelopment\Chemistry\PlayerChemistryProfile;

/**
 * BehaviourScorer — spec component 4.4 (behaviour group + team orientation).
 *
 * v1 formula: the pair's mutual behaviour quality — the mean of each
 * player's behaviour-group average, with team-orientation given extra pull
 * (two team-first players chemistry-bond more than two merely disciplined
 * ones). Neutral when neither player has behaviour recorded.
 */
final class BehaviourScorer implements ComponentScorer {

    public function key(): string { return 'behaviour'; }

    public function score( PlayerChemistryProfile $a, PlayerChemistryProfile $b, PairContext $pair ): ComponentScore {
        $aBlend = $this->playerBehaviour( $a );
        $bBlend = $this->playerBehaviour( $b );

        if ( $aBlend === null && $bBlend === null ) {
            return ComponentScore::neutral( __( 'No behaviour recorded yet', 'talenttrack' ) );
        }
        $value = $aBlend !== null && $bBlend !== null ? ( $aBlend + $bBlend ) / 2 : ( $aBlend ?? $bBlend );

        $reasons = [];
        if ( (float) $value >= 70.0 ) {
            $reasons[] = __( 'Strong shared behaviour', 'talenttrack' );
        }

        return ComponentScore::clamp( (float) $value, true, $reasons );
    }

    /**
     * A player's behaviour blend: the group average with team_orientation
     * weighted double when present.
     */
    private function playerBehaviour( PlayerChemistryProfile $p ): ?float {
        $avg = $p->groupAverage( 'behaviour' );
        if ( $avg === null ) return null;
        $team = $p->attr( 'behaviour', 'team_orientation' );
        if ( $team === null ) return $avg;
        // 2× pull toward team_orientation.
        return ( $avg + 2 * (float) $team ) / 3;
    }
}
