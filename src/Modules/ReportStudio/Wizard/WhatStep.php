<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — what, and about whom: a report or a dashboard, and its scope.
 *
 * #5023 — first, where to start: an empty report or a template the builder
 * may start. A template fixes what the report is, who it is about, who it is
 * written for and its layout; the later steps offer only the template's value
 * for those, and the report opens on a copy of its blocks.
 */
final class WhatStep implements WizardStepInterface {

    public function slug(): string  { return 'what'; }
    public function label(): string { return __( 'What and about whom', 'talenttrack' ); }

    public function render( array $state ): void {
        StartChoices::templateFieldset( $state );
        // The template is chosen on this same page, so these two stay open:
        // picking a template answers them when the page is sent.
        $free = $state;
        unset( $free['template'] );
        StartChoices::fieldset( 'kind', __( 'What are you making?', 'talenttrack' ), $free );
        StartChoices::fieldset( 'scope', __( 'Who is it about?', 'talenttrack' ), $free );
    }

    public function validate( array $post, array $state ) {
        $template = StartChoices::chosenTemplate( $post );
        if ( is_wp_error( $template ) ) return $template;

        // A template answers this step's two choices itself.
        $state['template'] = $template !== null ? $template->key : '';
        if ( $template !== null ) {
            $post['kind']  = $template->choices['kind'] ?? '';
            $post['scope'] = $template->choices['scope'] ?? '';
        }

        $answers = StartChoices::answers( [ 'kind', 'scope' ], $post, $state );
        if ( is_wp_error( $answers ) ) return $answers;

        $fixed = $template !== null ? array_diff_key( $template->choices, $answers ) : [];
        $merge = StartChoices::settle( $state, $answers + $fixed );
        // A subject chosen for another scope no longer applies.
        $scope = $answers['scope'];
        if ( $scope !== ReportContext::SCOPE_PLAYER ) $merge['player_id'] = 0;
        if ( $scope !== ReportContext::SCOPE_TEAM ) $merge['team_id'] = 0;
        if ( $scope !== ReportContext::SCOPE_GROUP ) $merge['cohort_team_ids'] = [];
        $merge['template'] = $state['template'];
        return $merge;
    }

    public function nextStep( array $state ): string { return 'readers'; }

    /** @return array<string,mixed> */
    public function submit( array $state ): array { return []; }
}
