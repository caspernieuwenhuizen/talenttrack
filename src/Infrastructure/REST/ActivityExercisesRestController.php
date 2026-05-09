<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Exercises\ActivityExercisesRepository;

/**
 * ActivityExercisesRestController — REST surface on the
 * `tt_activity_exercises` linkage table (#0016 Sprint 2b).
 *
 *   GET    /activities/{activity_id}/exercises               list
 *   POST   /activities/{activity_id}/exercises               append
 *   PUT    /activities/{activity_id}/exercises/{id}          patch
 *   DELETE /activities/{activity_id}/exercises/{id}          remove
 *   POST   /activities/{activity_id}/exercises/replace       bulk replace
 *
 * The bulk-replace path is the Sprint 4 review-wizard's commit
 * target: the AI-extracted, operator-reviewed exercise list lands
 * via one POST call rather than N appends.
 *
 *   GET    /exercises/{exercise_id}/activities               history view
 *
 * Returns every activity that linked the exercise; the per-activity
 * response carries `activity_title`, `activity_date`, `activity_team_id`
 * for the calling UI to render directly.
 *
 * Cap: `tt_edit_activities` for write paths (coaches who can edit
 * an activity can edit its exercise list); `tt_view_activities` for
 * reads.
 */
final class ActivityExercisesRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/exercises', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_for_activity' ],
                'permission_callback' => static fn() => current_user_can( 'tt_view_activities' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'append' ],
                'permission_callback' => static fn() => current_user_can( 'tt_edit_activities' ),
            ],
        ] );
        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/exercises/replace', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'replace' ],
                'permission_callback' => static fn() => current_user_can( 'tt_edit_activities' ),
            ],
        ] );
        register_rest_route( self::NS, '/activities/(?P<activity_id>\d+)/exercises/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update' ],
                'permission_callback' => static fn() => current_user_can( 'tt_edit_activities' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete' ],
                'permission_callback' => static fn() => current_user_can( 'tt_edit_activities' ),
            ],
        ] );
        register_rest_route( self::NS, '/exercises/(?P<exercise_id>\d+)/activities', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_for_exercise' ],
                'permission_callback' => static fn() => current_user_can( 'tt_view_activities' ),
            ],
        ] );
    }

    public static function list_for_activity( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $rows        = ( new ActivityExercisesRepository() )->listForActivity( $activity_id );
        return RestResponse::success( [ 'items' => array_map( [ __CLASS__, 'serialize' ], $rows ) ] );
    }

    public static function list_for_exercise( \WP_REST_Request $r ): \WP_REST_Response {
        $exercise_id = absint( $r['exercise_id'] );
        $rows        = ( new ActivityExercisesRepository() )->listForExercise( $exercise_id );
        return RestResponse::success( [ 'items' => array_map( [ __CLASS__, 'serialize' ], $rows ) ] );
    }

    public static function append( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $body        = $r->get_json_params();
        if ( ! is_array( $body ) ) $body = [];
        $exercise_id = absint( $body['exercise_id'] ?? 0 );
        if ( $exercise_id <= 0 ) {
            return RestResponse::error( 'missing_exercise', __( 'An exercise_id is required.', 'talenttrack' ), 400 );
        }
        $id = ( new ActivityExercisesRepository() )->append( $activity_id, $exercise_id, [
            'actual_duration_minutes' => $body['actual_duration_minutes'] ?? null,
            'notes'                   => $body['notes'] ?? null,
            'is_draft'                => ! empty( $body['is_draft'] ),
        ] );
        if ( $id <= 0 ) {
            Logger::error( 'rest.activity_exercise.append.failed', [
                'activity_id' => $activity_id,
                'exercise_id' => $exercise_id,
            ] );
            return RestResponse::error( 'db_error', __( 'The exercise could not be linked.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $body = $r->get_json_params();
        if ( ! is_array( $body ) ) $body = [];
        $patch = [];
        if ( isset( $body['order_index'] ) ) $patch['order_index'] = (int) $body['order_index'];
        if ( array_key_exists( 'actual_duration_minutes', $body ) ) $patch['actual_duration_minutes'] = $body['actual_duration_minutes'];
        if ( array_key_exists( 'notes', $body ) ) $patch['notes'] = $body['notes'];
        if ( array_key_exists( 'is_draft', $body ) ) $patch['is_draft'] = (bool) $body['is_draft'];
        if ( empty( $patch ) ) {
            return RestResponse::error( 'no_changes', __( 'No fields to update.', 'talenttrack' ), 400 );
        }
        $ok = ( new ActivityExercisesRepository() )->update( $id, $patch );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'The exercise link could not be updated.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'updated' => true, 'id' => $id ] );
    }

    public static function delete( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        $ok = ( new ActivityExercisesRepository() )->delete( $id );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'The exercise link could not be removed.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function replace( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        $body        = $r->get_json_params();
        $rows        = is_array( $body['exercises'] ?? null ) ? $body['exercises'] : [];
        // Sanitize each row's payload before handing to the repository.
        $clean = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $exercise_id = absint( $row['exercise_id'] ?? 0 );
            if ( $exercise_id <= 0 ) continue;
            $clean[] = [
                'exercise_id'             => $exercise_id,
                'actual_duration_minutes' => isset( $row['actual_duration_minutes'] ) ? (int) $row['actual_duration_minutes'] : null,
                'notes'                   => isset( $row['notes'] ) ? (string) $row['notes'] : null,
                'is_draft'                => ! empty( $row['is_draft'] ),
            ];
        }
        $ok = ( new ActivityExercisesRepository() )->replaceExercisesForActivity( $activity_id, $clean );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'The exercise list could not be replaced.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'replaced' => true, 'count' => count( $clean ) ] );
    }

    /**
     * @return array<string,mixed>
     */
    private static function serialize( object $row ): array {
        return [
            'id'                      => (int) ( $row->id ?? 0 ),
            'activity_id'             => (int) ( $row->activity_id ?? 0 ),
            'exercise_id'             => (int) ( $row->exercise_id ?? 0 ),
            'order_index'             => (int) ( $row->order_index ?? 0 ),
            'actual_duration_minutes' => $row->actual_duration_minutes !== null ? (int) $row->actual_duration_minutes : null,
            'notes'                   => $row->notes !== null ? (string) $row->notes : null,
            'is_draft'                => ! empty( $row->is_draft ),
            'exercise_name'           => $row->exercise_name ?? null,
            'exercise_planned_duration' => $row->exercise_planned_duration ?? null,
            'exercise_diagram_url'    => $row->exercise_diagram_url ?? null,
            'activity_title'          => $row->activity_title ?? null,
            'activity_date'           => $row->activity_date ?? null,
            'activity_team_id'        => isset( $row->activity_team_id ) ? (int) $row->activity_team_id : null,
        ];
    }
}
