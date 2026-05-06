<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Workflow\Templates\LogProspectTemplate;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\WorkflowModule;

/**
 * REST surface for the #0081 prospects entity (child 2 — chain entry
 * point only).
 *
 * Routes:
 *
 *   POST /talenttrack/v1/prospects/log     dispatch the LogProspect
 *                                          chain for the current user.
 *
 * Subsequent stages (parent confirmation, test-training outcome
 * recording, trial-group review) are handled entirely by `TaskEngine`
 * chain spawning — no bespoke orchestration. PR 2b adds a public
 * (no-login) signed-token endpoint for the parent-confirmation stage.
 *
 * The chain entry point exists as a REST route — and not just an
 * inline form button — so the future `OnboardingPipelineWidget`
 * (child 3) and any external integration (PR 2b's public endpoint
 * being the first such integration) consume the same code path.
 */
class ProspectsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/prospects/log', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'log_prospect' ],
            'permission_callback' => [ self::class, 'can_log' ],
        ] );
    }

    public static function can_log(): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_prospects' );
    }

    /**
     * Start the chain. Dispatches a `LogProspectTemplate` task assigned
     * to the calling user; the task's form (`LogProspectForm`) writes
     * the actual `tt_prospects` row on submit. This is intentionally
     * thin — the surface lives at the form, not the REST endpoint.
     *
     * The endpoint exists so the "+ New prospect" button on the future
     * pipeline widget (and the future Onboarding Pipeline standalone
     * view) has a stable, capability-gated entry into the chain.
     *
     * Response payload echoes the new task ID + the canonical task-
     * detail URL so the caller can redirect the scout straight into
     * the form. No prospect row exists yet at this point — the row is
     * only created when the form is submitted.
     */
    public static function log_prospect( \WP_REST_Request $r ) {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) {
            return RestResponse::error( 'not_logged_in', __( 'You must be logged in to log a prospect.', 'talenttrack' ), 401 );
        }

        $context = new TaskContext(
            null, null, null, null, null, null, null, null,
            [ 'initiated_by' => $uid ]
        );
        $task_ids = WorkflowModule::engine()->dispatch( LogProspectTemplate::KEY, $context );

        if ( empty( $task_ids ) ) {
            return RestResponse::error(
                'dispatch_failed',
                __( 'Could not start the prospect-logging chain. Check the workflow templates are enabled.', 'talenttrack' ),
                500
            );
        }

        $task_id = (int) $task_ids[0];
        return RestResponse::success( [
            'task_id'      => $task_id,
            'redirect_url' => add_query_arg(
                [
                    'tt_view'  => 'my-tasks',
                    'task_id'  => $task_id,
                ],
                home_url( '/' )
            ),
        ], 201 );
    }
}
