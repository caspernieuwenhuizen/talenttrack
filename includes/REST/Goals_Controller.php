<?php
namespace TT\REST;

use TT\Infrastructure\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Goals_Controller — REST endpoints for tt_goals.
 *
 * Ships in #0019 Sprint 1 as part of the FrontendAjax retirement.
 * Behaviour-identical to the old `tt_fe_save_goal`, `tt_fe_update_goal_status`,
 * and `tt_fe_delete_goal` AJAX handlers, with fail-loud DB error
 * handling bolted on (the FrontendAjax v2.6.2 style).
 *
 * Routes:
 *   POST   /talenttrack/v1/goals
 *   PUT    /talenttrack/v1/goals/{id}
 *   PATCH  /talenttrack/v1/goals/{id}/status
 *   DELETE /talenttrack/v1/goals/{id}
 */
class Goals_Controller {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/goals', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::goal_args(),
            ],
        ] );
        register_rest_route( self::NS, '/goals/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => array_merge( self::goal_args(), [ 'id' => [ 'required' => true, 'type' => 'integer' ] ] ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer' ] ],
            ],
        ] );
        register_rest_route( self::NS, '/goals/(?P<id>\d+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_status' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => [
                    'id'     => [ 'required' => true, 'type' => 'integer' ],
                    'status' => [ 'required' => true, 'type' => 'string' ],
                ],
            ],
        ] );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_goals' );
    }

    public static function create_goal( \WP_REST_Request $r ) {
        global $wpdb;

        $data = [
            'player_id'   => absint( $r['player_id'] ?? 0 ),
            'title'       => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
            'description' => sanitize_textarea_field( (string) ( $r['description'] ?? '' ) ),
            'status'      => sanitize_text_field( (string) ( $r['status'] ?? 'pending' ) ),
            'priority'    => sanitize_text_field( (string) ( $r['priority'] ?? 'medium' ) ),
            'due_date'    => ! empty( $r['due_date'] ) ? sanitize_text_field( (string) $r['due_date'] ) : null,
            'created_by'  => get_current_user_id(),
        ];

        if ( $data['player_id'] <= 0 || $data['title'] === '' ) {
            return new \WP_Error( 'rest_missing_fields', __( 'Player and title are required.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_goals', $data );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.save.failed', [ 'db_error' => $err, 'payload' => $data ] );
            return new \WP_Error(
                'rest_goal_save_failed',
                __( 'The goal could not be saved. The database rejected the operation.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $err ]
            );
        }

        return rest_ensure_response( [
            'id'      => (int) $wpdb->insert_id,
            'message' => __( 'Goal added.', 'talenttrack' ),
        ] );
    }

    public static function update_goal( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return new \WP_Error( 'rest_bad_id', __( 'Invalid goal id.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $data = [];
        foreach ( [ 'title', 'description', 'status', 'priority' ] as $k ) {
            if ( isset( $r[ $k ] ) ) {
                $data[ $k ] = $k === 'description'
                    ? sanitize_textarea_field( (string) $r[ $k ] )
                    : sanitize_text_field( (string) $r[ $k ] );
            }
        }
        if ( isset( $r['due_date'] ) ) {
            $data['due_date'] = ! empty( $r['due_date'] ) ? sanitize_text_field( (string) $r['due_date'] ) : null;
        }
        if ( ! $data ) {
            return new \WP_Error( 'rest_empty_update', __( 'No fields to update.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $ok = $wpdb->update( $wpdb->prefix . 'tt_goals', $data, [ 'id' => $goal_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.update.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return new \WP_Error(
                'rest_goal_update_failed',
                __( 'The goal could not be updated.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $err ]
            );
        }
        return rest_ensure_response( [ 'id' => $goal_id, 'message' => __( 'Goal updated.', 'talenttrack' ) ] );
    }

    public static function update_status( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return new \WP_Error( 'rest_bad_id', __( 'Invalid goal id.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $status = sanitize_text_field( (string) ( $r['status'] ?? '' ) );
        if ( $status === '' ) {
            return new \WP_Error( 'rest_missing_fields', __( 'Status is required.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $ok = $wpdb->update( $wpdb->prefix . 'tt_goals', [ 'status' => $status ], [ 'id' => $goal_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.status.update.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return new \WP_Error(
                'rest_goal_status_failed',
                __( 'Status update failed.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $err ]
            );
        }
        return rest_ensure_response( [ 'id' => $goal_id, 'status' => $status, 'message' => __( 'Status updated.', 'talenttrack' ) ] );
    }

    public static function delete_goal( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return new \WP_Error( 'rest_bad_id', __( 'Invalid goal id.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        $ok = $wpdb->delete( $wpdb->prefix . 'tt_goals', [ 'id' => $goal_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.delete.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return new \WP_Error(
                'rest_goal_delete_failed',
                __( 'Goal delete failed.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $err ]
            );
        }
        return rest_ensure_response( [ 'deleted' => true, 'id' => $goal_id ] );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function goal_args(): array {
        return [
            'player_id'   => [ 'type' => 'integer', 'required' => true ],
            'title'       => [ 'type' => 'string',  'required' => true ],
            'description' => [ 'type' => 'string' ],
            'status'      => [ 'type' => 'string' ],
            'priority'    => [ 'type' => 'string' ],
            'due_date'    => [ 'type' => 'string' ],
        ];
    }
}
