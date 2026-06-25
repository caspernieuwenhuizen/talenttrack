<?php
namespace TT\Modules\TeamDevelopment\Chemistry\Scorers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Chemistry\ComponentScore;
use TT\Modules\TeamDevelopment\Chemistry\PairContext;
use TT\Modules\TeamDevelopment\Chemistry\PlayerChemistryProfile;

/**
 * FamiliarityScorer — spec component 4.2 (shared participation, minutes,
 * appearances, tenure overlap).
 *
 * v1 formula: a saturating blend of how often the two players have trained
 * together (shared completed-activity attendance) and how long they've been
 * teammates (tenure overlap). Sessions saturate at ~30, tenure at one year;
 * weighted 60/40. Neutral when there's no shared history yet.
 *
 * Tuning hooks: SESSION_SATURATION / TENURE_SATURATION_DAYS and the blend.
 */
final class FamiliarityScorer implements ComponentScorer {

    private const SESSION_SATURATION      = 30.0;
    private const TENURE_SATURATION_DAYS  = 365.0;
    private const SESSION_WEIGHT          = 0.6;
    private const TENURE_WEIGHT           = 0.4;

    public function key(): string { return 'familiarity'; }

    public function score( PlayerChemistryProfile $a, PlayerChemistryProfile $b, PairContext $pair ): ComponentScore {
        if ( $pair->shared_sessions <= 0 && $pair->tenure_overlap_days <= 0 ) {
            return ComponentScore::neutral( __( 'No shared history yet', 'talenttrack' ) );
        }

        $sessions = min( 100.0, $pair->shared_sessions / self::SESSION_SATURATION * 100.0 );
        $tenure   = min( 100.0, $pair->tenure_overlap_days / self::TENURE_SATURATION_DAYS * 100.0 );
        $value    = self::SESSION_WEIGHT * $sessions + self::TENURE_WEIGHT * $tenure;

        $reasons = [];
        if ( $pair->shared_sessions >= 15 ) $reasons[] = __( 'Trained together often', 'talenttrack' );
        if ( $pair->tenure_overlap_days >= 180 ) $reasons[] = __( 'Long shared tenure', 'talenttrack' );

        return ComponentScore::clamp( $value, true, $reasons );
    }
}
