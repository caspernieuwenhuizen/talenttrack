<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * TeamFunctionsStep — the four methodology team functions, each with a
 * rating and up to four short points.
 *
 * All four on one step rather than one step each: they are the same
 * question asked four times, and a coach comparing "we attacked well but
 * lost the ball badly" wants both in view at once.
 */
final class TeamFunctionsStep implements WizardStepInterface {

    public function slug(): string  { return 'team-functions'; }
    public function label(): string { return __( 'Team functions', 'talenttrack' ); }

    public function render( array $state ): void {
        $prep = self::prepFor( OverallStep::activityId( $state ) );

        // #2836 — hint and legend share the line that introduces the phases,
        // so the glyph vocabulary is stated once for all four rather than
        // spelled out on every one of them.
        echo '<div class="tt-ma__group-head">';
        echo '<p class="tt-ma__hint">'
            . esc_html__( 'Rate what you saw per phase and add the points worth remembering. Leave a phase unrated if there is nothing to say.', 'talenttrack' )
            . '</p>';
        \TT\Modules\MatchAnalysis\Frontend\SectionRatingControl::renderLegend();
        echo '</div>';

        foreach ( OverallStep::teamFunctionKeys() as $key ) {
            SectionStepFields::render( $key, $state, $prep );
        }
    }

    public function validate( array $post, array $state ) {
        return SectionStepFields::collect( $post, OverallStep::teamFunctionKeys(), $state );
    }

    public function nextStep( array $state ): ?string {
        return 'set-pieces';
    }

    public function submit( array $state ) {
        return null;
    }

    public static function prepFor( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;

        return ( new MatchPrepRepository() )->findByActivity( $activity_id );
    }
}
