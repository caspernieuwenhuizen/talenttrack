<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * SetPiecesStep — corners, free kicks, penalties and throw-ins, both ways.
 *
 * Its own step rather than a fifth box on the previous one because it is
 * the phase coaches skip when it is buried, and at youth level it decides
 * a surprising number of results.
 */
final class SetPiecesStep implements WizardStepInterface {

    public function slug(): string  { return 'set-pieces'; }
    public function label(): string { return __( 'Set pieces', 'talenttrack' ); }

    public function render( array $state ): void {
        $prep = TeamFunctionsStep::prepFor( OverallStep::activityId( $state ) );

        echo '<p class="tt-ma__hint">'
            . esc_html__( 'Ours and theirs. If nothing came of them, leave it unrated and move on.', 'talenttrack' )
            . '</p>';

        SectionStepFields::render( MatchAnalysisEnums::SECTION_SET_PIECES, $state, $prep );
    }

    public function validate( array $post, array $state ) {
        return SectionStepFields::collect( $post, [ MatchAnalysisEnums::SECTION_SET_PIECES ], $state );
    }

    public function nextStep( array $state ): ?string {
        return 'players';
    }

    public function submit( array $state ) {
        return null;
    }
}
