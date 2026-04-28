<?php
namespace TT\Modules\Trials;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Trials\Reminders\TrialReminderScheduler;
use TT\Modules\Trials\Rest\TrialsRestController;

/**
 * TrialsModule (#0017) — youth-academy trial workflow.
 *
 * The module captures everything between "we want to look at this kid"
 * and "here's the letter we sent". Cases hold the structure (player,
 * track, dates, status, decision); staff inputs aggregate evaluator
 * opinions; the decision tab triggers letter generation through the
 * existing `PlayerReportRenderer` audience system from #0014 Sprint 4.
 *
 * Six sprints bundled in one PR. The shared schema lives in migration
 * 0036; the renderer integration extends `AudienceType`; the dashboard
 * dispatcher routes four new view slugs (trials / trial-case /
 * trial-staff-input / trial-parent-meeting / trial-tracks-editor /
 * trial-letter-templates-editor).
 */
class TrialsModule implements ModuleInterface {

    public function getName(): string { return 'trials'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        TrialsRestController::init();
        TrialReminderScheduler::init();
    }

    /**
     * Idempotent capability assignment.
     *
     *   tt_manage_trials       — open / extend / decide / archive cases,
     *                            assign staff, edit tracks + letters.
     *                            Granted: head_dev, club_admin, administrator.
     *   tt_submit_trial_input  — write input on an assigned case.
     *                            Granted: head_dev, club_admin, coach, administrator.
     *                            (Per-case scoping enforced in the view.)
     *   tt_view_trial_synthesis — read the case execution + aggregation tabs.
     *                            Granted to managers + assigned coaches at
     *                            cap level; the per-case visibility check
     *                            sits in TrialCaseAccessPolicy.
     */
    public static function ensureCapabilities(): void {
        $manage  = 'tt_manage_trials';
        $submit  = 'tt_submit_trial_input';
        $view    = 'tt_view_trial_synthesis';

        $manage_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        $submit_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach' ];
        $view_roles   = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach' ];

        foreach ( $manage_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $manage ) ) $role->add_cap( $manage );
        }
        foreach ( $submit_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $submit ) ) $role->add_cap( $submit );
        }
        foreach ( $view_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $view ) ) $role->add_cap( $view );
        }
    }
}
