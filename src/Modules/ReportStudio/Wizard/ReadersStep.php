<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — who reads it: the audience, and whether the subject is fixed,
 * chosen when the report is opened, or follows whoever opens it.
 */
final class ReadersStep implements WizardStepInterface {

    public function slug(): string  { return 'readers'; }
    public function label(): string { return __( 'Who reads it', 'talenttrack' ); }

    public function render( array $state ): void {
        StartChoices::fieldset( 'audience', __( 'Who is it written for?', 'talenttrack' ), $state );
        StartChoices::fieldset( 'binding', __( 'Which subject does it show?', 'talenttrack' ), $state );
    }

    public function validate( array $post, array $state ) {
        $answers = StartChoices::answers( [ 'audience', 'binding' ], $post, $state );
        if ( is_wp_error( $answers ) ) return $answers;
        return StartChoices::settle( $state, $answers );
    }

    public function nextStep( array $state ): string { return 'when'; }

    /** @return array<string,mixed> */
    public function submit( array $state ): array { return []; }
}
