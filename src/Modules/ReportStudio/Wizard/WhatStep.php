<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — what, and about whom: a report or a dashboard, and its scope.
 */
final class WhatStep implements WizardStepInterface {

    public function slug(): string  { return 'what'; }
    public function label(): string { return __( 'What and about whom', 'talenttrack' ); }

    public function render( array $state ): void {
        StartChoices::fieldset( 'kind', __( 'What are you making?', 'talenttrack' ), $state );
        StartChoices::fieldset( 'scope', __( 'Who is it about?', 'talenttrack' ), $state );
    }

    public function validate( array $post, array $state ) {
        $answers = StartChoices::answers( [ 'kind', 'scope' ], $post, $state );
        if ( is_wp_error( $answers ) ) return $answers;

        $merge = StartChoices::settle( $state, $answers );
        // A subject chosen for another scope no longer applies.
        $scope = $answers['scope'];
        if ( $scope !== ReportContext::SCOPE_PLAYER ) $merge['player_id'] = 0;
        if ( $scope !== ReportContext::SCOPE_TEAM ) $merge['team_id'] = 0;
        if ( $scope !== ReportContext::SCOPE_GROUP ) $merge['cohort_team_ids'] = [];
        return $merge;
    }

    public function nextStep( array $state ): ?string { return 'subject'; }

    /** @return array<string,mixed> */
    public function submit( array $state ): array { return []; }
}
