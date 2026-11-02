<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 5 — sharing: only the builder, published for a role, a scheduled
 * mail, or shared with the family. Choosing it here stores the intent;
 * publishing stays its own action in the Studio.
 */
final class SharingStep implements WizardStepInterface {

    public function slug(): string  { return 'sharing'; }
    public function label(): string { return __( 'Sharing', 'talenttrack' ); }

    public function render( array $state ): void {
        StartChoices::fieldset( 'sharing', __( 'Who else gets it?', 'talenttrack' ), $state );
    }

    public function validate( array $post, array $state ) {
        $answers = StartChoices::answers( [ 'sharing' ], $post, $state );
        if ( is_wp_error( $answers ) ) return $answers;
        return StartChoices::settle( $state, $answers );
    }

    public function nextStep( array $state ): string { return 'review'; }

    /** @return array<string,mixed> */
    public function submit( array $state ): array { return []; }
}
