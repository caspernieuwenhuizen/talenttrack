<?php
namespace TT\Modules\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\Journey\JourneyEventSubscriber;
use TT\Infrastructure\REST\PlayerJourneyRestController;
use TT\Modules\Journey\Workflow\InjuryRecoveryDueTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * JourneyModule (#0053) — player journey aggregator.
 *
 * Owns:
 *   - Schema (migration 0037): tt_player_events + tt_player_injuries.
 *   - Subscribers: JourneyEventSubscriber wires existing module hooks
 *     (tt_evaluation_saved, tt_goal_saved, tt_pdp_verdict_signed_off,
 *     tt_player_created, tt_player_save_diff, tt_trial_started,
 *     tt_trial_decision_recorded) into journey events.
 *   - REST: /players/{id}/timeline + /players/{id}/transitions +
 *     /players/{id}/events + /players/{id}/injuries +
 *     /journey/event-types + /journey/cohort-transitions.
 *   - Workflow: injury_recovery_due template.
 *   - Capabilities: tt_view_player_medical, tt_view_player_safeguarding.
 *
 * The journey is read-side aggregation, not a rewrite — Evaluations,
 * Goals, PDP, Players, Trials all keep their own UIs and own their
 * data. This module subscribes to their hooks and projects events into
 * tt_player_events; visibility filtering happens server-side per
 * viewer caps.
 */
class JourneyModule implements ModuleInterface {

    public function getName(): string { return 'journey'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        JourneyEventSubscriber::init();
        PlayerJourneyRestController::init();

        // Register workflow template alongside the Phase 1 templates.
        // Same priority (init:5) as WorkflowModule::registerShippedTemplates
        // so the EventDispatcher (init:20) sees this template too.
        add_action( 'init', [ self::class, 'registerWorkflowTemplates' ], 5 );
    }

    public static function registerWorkflowTemplates(): void {
        WorkflowModule::registry()->register( new InjuryRecoveryDueTemplate() );
    }

    /**
     * Idempotent capability assignment.
     *
     *   tt_view_player_medical       — required to see `visibility='medical'`
     *                                  events. Granted to head_dev,
     *                                  club_admin, administrator. Coaches
     *                                  do NOT get it by default; clubs grant
     *                                  per-coach via the matrix admin UI.
     *
     *   tt_view_player_safeguarding  — required to see
     *                                  `visibility='safeguarding'` events.
     *                                  Granted only to head_dev +
     *                                  administrator by default.
     */
    public static function ensureCapabilities(): void {
        $medical      = 'tt_view_player_medical';
        $safeguarding = 'tt_view_player_safeguarding';

        $medical_roles      = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        $safeguarding_roles = [ 'administrator', 'tt_head_dev' ];

        foreach ( $medical_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $medical ) ) $role->add_cap( $medical );
        }
        foreach ( $safeguarding_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $safeguarding ) ) $role->add_cap( $safeguarding );
        }
    }
}
