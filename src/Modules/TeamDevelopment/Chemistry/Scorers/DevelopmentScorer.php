<?php
namespace TT\Modules\TeamDevelopment\Chemistry\Scorers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Chemistry\ComponentScore;
use TT\Modules\TeamDevelopment\Chemistry\PairContext;
use TT\Modules\TeamDevelopment\Chemistry\PlayerChemistryProfile;

/**
 * DevelopmentScorer — spec component 4.3 (age, maturity, potential
 * differences).
 *
 * v1 formula: players close in age and aligned in development potential pair
 * better. Age component loses 18 points per year of gap; development
 * component is 100 minus the absolute gap in the development-group average.
 * The score is the mean of whichever components are available. Neutral when
 * neither age nor development is known.
 *
 * Tuning hook: AGE_PENALTY_PER_YEAR.
 */
final class DevelopmentScorer implements ComponentScorer {

    private const AGE_PENALTY_PER_YEAR = 18.0;

    public function key(): string { return 'development'; }

    public function score( PlayerChemistryProfile $a, PlayerChemistryProfile $b, PairContext $pair ): ComponentScore {
        $parts   = [];
        $reasons = [];

        if ( $a->age !== null && $b->age !== null ) {
            $gap       = abs( $a->age - $b->age );
            $age_score = max( 0.0, 100.0 - $gap * self::AGE_PENALTY_PER_YEAR );
            $parts[]   = $age_score;
            if ( $gap <= 1.0 ) $reasons[] = __( 'Close in age', 'talenttrack' );
        }

        $ad = $a->groupAverage( 'development' );
        $bd = $b->groupAverage( 'development' );
        if ( $ad !== null && $bd !== null ) {
            $dev_score = max( 0.0, 100.0 - abs( $ad - $bd ) );
            $parts[]   = $dev_score;
            if ( abs( $ad - $bd ) <= 10.0 ) $reasons[] = __( 'Aligned development', 'talenttrack' );
        }

        if ( empty( $parts ) ) {
            return ComponentScore::neutral( __( 'No age or development data', 'talenttrack' ) );
        }

        return ComponentScore::clamp( array_sum( $parts ) / count( $parts ), true, $reasons );
    }
}
