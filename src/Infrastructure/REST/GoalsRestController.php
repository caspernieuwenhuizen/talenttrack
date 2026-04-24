<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;

/**
 * GoalsRestController — /wp-json/talenttrack/v1/goals
 *
 * #0019 Sprint 1 — replaces the legacy `tt_fe_save_goal`,
 * `tt_fe_update_goal_status`, and `tt_fe_delete_goal` admin-ajax
 * handlers. The PATCH `/goals/{id}/status` route matches the inline
 * status-select dropdown flow; the main PUT `/goals/{id}` is for
 * future edit-goal views.
 */
class GoalsRestController {

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
            ],
        ] );
        register_rest_route( self::NS, '/goals/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_goal' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/goals/(?P<id>\d+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_status' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
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
            return RestResponse::error( 'missing_fields', __( 'Player and title are required.', 'talenttrack' ), 400 );
        }

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_goals', $data );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.save.failed', [ 'db_error' => $err, 'payload' => $data ] );
            return RestResponse::error(
                'db_error',
                __( 'The goal could not be saved. The database rejected the operation.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }

        return RestResponse::success( [ 'id' => (int) $wpdb->insert_id ] );
    }

    public static function update_goal( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid goal id.', 'talenttrack' ), 400 );
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
            return RestResponse::error( 'empty_update', __( 'No fields to update.', 'talenttrack' ), 400 );
        }

        $ok = $wpdb->update( $wpdb->prefix . 'tt_goals', $data, [ 'id' => $goal_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.update.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return RestResponse::error(
                'db_error',
                __( 'The goal could not be updated.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        return RestResponse::success( [ 'id' => $goal_id ] );
    }

    public static function update_status( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid goal id.', 'talenttrack' ), 400 );
        }
        $status = sanitize_text_field( (string) ( $r['status'] ?? '' ) );
        if ( $status === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Status is required.', 'talenttrack' ), 400 );
        }
        $ok = $wpdb->update( $wpdb->prefix . 'tt_goals', [ 'status' => $status ], [ 'id' => $goal_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.status.update.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return RestResponse::error(
                'db_error',
                __( 'Status update failed.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        return RestResponse::success( [ 'id' => $goal_id, 'status' => $status ] );
    }

    public static function delete_goal( \WP_REST_Request $r ) {
        global $wpdb;
        $goal_id = absint( $r['id'] );
        if ( $goal_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid goal id.', 'talenttrack' ), 400 );
        }
        $ok = $wpdb->delete( $wpdb->prefix . 'tt_goals', [ 'id' => $goal_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'goal.delete.failed', [ 'db_error' => $err, 'goal_id' => $goal_id ] );
            return RestResponse::error(
                'db_error',
                __( 'Goal delete failed.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $goal_id ] );
    }
}
