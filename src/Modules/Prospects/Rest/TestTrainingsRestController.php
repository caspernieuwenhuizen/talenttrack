<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Domain\ArrangeTestTrainingService;
use TT\Modules\Prospects\Repositories\TestTrainingsRepository;

/**
 * TestTrainingsRestController (v3.110.113) — /wp-json/talenttrack/v1/test-trainings
 *
 * POST endpoint backing the `+ New test training` action card on the
 * HoD dashboard. Mirrors the field surface in `TestTrainingsRepository::create()`:
 *
 *   - date                (DATETIME, required)
 *   - location            (string, optional)
 *   - age_group_lookup_id (int,    optional)
 *   - coach_user_id       (int,    defaults to current user)
 *   - notes               (string, optional)
 *   - prospect_id         (int,    optional — #3932)
 *
 * `prospect_id` is the one field that is not a column: there is no
 * prospect column on `tt_test_trainings`. Passing it records that the
 * prospect was invited to this session, through the same completed
 * `invite_to_test_training` task the workflow route writes, so the direct
 * route and the task route leave one shape of record rather than two.
 *
 * Read endpoint deliberately omitted — list rendering still goes
 * through the `onboarding-pipeline` view's existing surfaces. Pure
 * create surface for the dashboard CTA.
 */
class TestTrainingsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/test-trainings', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::createArgs(),
            ],
        ] );

        // #1784 — referential-integrity permanent delete; clears any
        // workflow-task link, then removes the session.
        register_rest_route( self::NS, '/test-trainings/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_permanently' ],
                // #2024 security #6 — re-gate onto tt_manage_recycle_bin: no
                // purge path weaker than the bin's own purge.
                'permission_callback' => static function () { return current_user_can( 'tt_manage_recycle_bin' ); },
            ],
        ] );
    }

    /**
     * What `POST /test-trainings` takes (#3818). Mirrors the repository's
     * create surface exactly; a key outside it is refused by name rather
     * than dropped, which is what a caller sending `age_group` or `coach`
     * used to get back as a quietly-empty session.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function createArgs(): array {
        return [
            'date' => [
                'type'        => 'string',
                'required'    => true,
                'description' => 'When the test training is, YYYY-MM-DD or YYYY-MM-DD HH:MM:SS. A bare date is stored at 18:00.',
            ],
            'location' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Where it is held.',
            ],
            'age_group_lookup_id' => [
                'type'        => [ 'integer', 'null' ],
                'description' => 'The age group it targets. Omit for a mixed-age test training.',
            ],
            'coach_user_id' => [
                'type'        => [ 'integer', 'null' ],
                'description' => 'The coach running it. Defaults to the caller.',
            ],
            'notes' => [
                'type'        => [ 'string', 'null' ],
                'description' => 'Logistics, what to bring, contact instructions.',
            ],
            // #3932 — the prospect this session is being arranged for.
            // Optional: a test training with nobody attached to it yet is
            // an ordinary thing to create.
            'prospect_id' => [
                'type'        => [ 'integer', 'null' ],
                'description' => 'The prospect being invited. Requires tt_invite_prospects and sight of that prospect; omit to create an unattached session.',
            ],
        ];
    }

    /** #1784 — permanently delete a test training (irreversible). Gated by tt_edit_settings. */
    public static function delete_permanently( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid test training id.', 'talenttrack' ), 400 );
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'test_training', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Test training not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function can_edit(): bool {
        $uid = get_current_user_id();
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_prospects' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_prospects' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_settings' );
    }

    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $bad = BaseController::checkBody( $r, self::createArgs() );
        if ( $bad ) return $bad;

        $date = sanitize_text_field( (string) ( $r['date'] ?? '' ) );
        if ( $date === '' ) {
            return RestResponse::error( 'missing_fields',
                __( 'Date is required.', 'talenttrack' ), 400 );
        }

        // Normalise the date — accept either DATE (Y-m-d) or DATETIME
        // (Y-m-d H:i:s) from the form; persist as DATETIME (the
        // repository column type).
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date .= ' 18:00:00'; // sensible default time if the form omitted it
        }

        // #3932 — asked before the session is written, not after. A
        // refusal that arrives once the row exists leaves an orphan
        // behind every attempt, and the consent block in particular is
        // about a child who should not have been invited at all.
        $prospect_id = isset( $r['prospect_id'] ) ? (int) $r['prospect_id'] : 0;
        if ( $prospect_id > 0 ) {
            $refusal = ArrangeTestTrainingService::refusalFor( get_current_user_id(), $prospect_id );
            if ( $refusal !== null ) {
                return RestResponse::error(
                    (string) $refusal->get_error_code(),
                    (string) $refusal->get_error_message(),
                    $refusal->get_error_code() === 'no_consent' ? 409 : 403
                );
            }
        }

        $payload = [
            'date'                => $date,
            'location'            => isset( $r['location'] )
                ? sanitize_text_field( (string) $r['location'] )
                : null,
            'age_group_lookup_id' => isset( $r['age_group_lookup_id'] ) && (int) $r['age_group_lookup_id'] > 0
                ? (int) $r['age_group_lookup_id']
                : null,
            'coach_user_id'       => isset( $r['coach_user_id'] ) && (int) $r['coach_user_id'] > 0
                ? (int) $r['coach_user_id']
                : get_current_user_id(),
            'notes'               => isset( $r['notes'] )
                ? sanitize_textarea_field( (string) $r['notes'] )
                : null,
        ];

        $id = ( new TestTrainingsRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'test_training.create.failed', [ 'payload' => $payload ] );
            return RestResponse::error( 'db_error',
                __( 'The test training could not be saved.', 'talenttrack' ), 500 );
        }

        $task_id = 0;
        if ( $prospect_id > 0 ) {
            $linked = ArrangeTestTrainingService::link( get_current_user_id(), $prospect_id, $id );
            if ( $linked instanceof \WP_Error ) {
                // The session is real and the caller should know it was
                // saved; what failed is the link, and saying so is more
                // use than a 500 over a row that exists.
                Logger::error( 'test_training.link_prospect.failed', [
                    'test_training_id' => $id,
                    'prospect_id'      => $prospect_id,
                    'code'             => $linked->get_error_code(),
                ] );
                return RestResponse::error(
                    'link_failed',
                    (string) $linked->get_error_message(),
                    409
                );
            }
            $task_id = (int) $linked;
        }

        return RestResponse::success( [
            'id'          => $id,
            'prospect_id' => $prospect_id > 0 ? $prospect_id : null,
            'task_id'     => $task_id > 0 ? $task_id : null,
        ] );
    }
}
