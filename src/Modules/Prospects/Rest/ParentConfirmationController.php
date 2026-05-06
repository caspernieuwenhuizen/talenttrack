<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Templates\ConfirmTestTrainingTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * ParentConfirmationController (#0081 child 2b) — public, no-login
 * endpoint for the parent's "yes I'll come" / "no, can't make it"
 * link in their invitation email.
 *
 * Routes:
 *
 *   GET  /talenttrack/v1/prospects/confirm
 *        ?task_id=N&outcome=confirmed|declined&token=abc123
 *
 * Permission: open. The signed token is the only authentication —
 * derived from the workflow task ID, the prospect ID on the task,
 * the prospect's `tt_prospects.uuid`, and a wp_salt for the
 * deterministic HMAC. A parent who clicks the email link succeeds;
 * anyone without the token fails. The token is single-use in
 * spirit (the task transitions to `completed` after first valid hit;
 * subsequent hits return a 200 with the existing decision rather
 * than re-completing).
 *
 * Why GET, not POST: parents click a link in an email. Links are
 * GETs. The endpoint is idempotent on the workflow side
 * (TaskEngine::complete is a no-op on already-completed tasks),
 * which keeps GET safe.
 */
final class ParentConfirmationController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/prospects/confirm', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'confirm' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Build the signed token a public link should carry. Stored in
     * the invitation email body. Reproducing the token requires
     * (task_id, prospect_id, prospect_uuid, AUTH_KEY) so a leaked
     * task id alone is not enough.
     */
    public static function tokenFor( int $task_id, int $prospect_id, string $prospect_uuid ): string {
        $payload = $task_id . '|' . $prospect_id . '|' . $prospect_uuid;
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    public static function confirm( \WP_REST_Request $r ) {
        $task_id = (int) $r->get_param( 'task_id' );
        $outcome = (string) $r->get_param( 'outcome' );
        $token   = (string) $r->get_param( 'token' );

        if ( $task_id <= 0 || $token === '' ) {
            return RestResponse::error( 'invalid_link', __( 'This confirmation link is invalid.', 'talenttrack' ), 400 );
        }
        if ( ! in_array( $outcome, [ 'confirmed', 'declined' ], true ) ) {
            return RestResponse::error( 'invalid_outcome', __( 'This confirmation link does not specify a valid outcome.', 'talenttrack' ), 400 );
        }

        $tasks = new TasksRepository();
        $task  = $tasks->find( $task_id );
        if ( $task === null ) {
            return RestResponse::error( 'task_not_found', __( 'This confirmation link points at a task that no longer exists.', 'talenttrack' ), 404 );
        }
        if ( (string) ( $task['template_key'] ?? '' ) !== ConfirmTestTrainingTemplate::KEY ) {
            return RestResponse::error( 'wrong_template', __( 'This confirmation link does not match a parent-confirmation task.', 'talenttrack' ), 400 );
        }

        $prospect_id   = (int) ( $task['prospect_id'] ?? 0 );
        $prospect_uuid = self::prospectUuid( $prospect_id );
        $expected      = self::tokenFor( $task_id, $prospect_id, $prospect_uuid );
        if ( ! hash_equals( $expected, $token ) ) {
            return RestResponse::error( 'invalid_token', __( 'This confirmation link is no longer valid.', 'talenttrack' ), 403 );
        }

        // Idempotent: if the task is already completed, return a 200
        // with the existing decision rather than re-completing.
        if ( ( (string) ( $task['status'] ?? '' ) ) === 'completed' ) {
            $existing = json_decode( (string) ( $task['response_json'] ?? '' ), true );
            return RestResponse::success( [
                'task_id' => $task_id,
                'outcome' => is_array( $existing ) ? ( $existing['outcome'] ?? '' ) : '',
                'already_completed' => true,
            ] );
        }

        $ok = WorkflowModule::engine()->complete( $task_id, [
            'outcome' => $outcome,
            'notes'   => '',
            // surface that the parent self-recorded so the audit
            // trail can distinguish from HoD's manual entry.
            'recorded_via' => 'parent_link',
        ] );
        if ( ! $ok ) {
            return RestResponse::error( 'complete_failed', __( 'Could not record your response. Please try again.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [
            'task_id' => $task_id,
            'outcome' => $outcome,
        ] );
    }

    private static function prospectUuid( int $prospect_id ): string {
        if ( $prospect_id <= 0 ) return '';
        global $wpdb;
        $uuid = $wpdb->get_var( $wpdb->prepare(
            "SELECT uuid FROM {$wpdb->prefix}tt_prospects WHERE id = %d LIMIT 1",
            $prospect_id
        ) );
        return is_string( $uuid ) ? $uuid : '';
    }
}
