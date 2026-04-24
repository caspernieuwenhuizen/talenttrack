<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;

/**
 * SessionsRestController — /wp-json/talenttrack/v1/sessions
 *
 * #0019 Sprint 1 — replaces the legacy `tt_fe_save_session` admin-ajax
 * path. Attendance is a nested sub-resource handled inline on create
 * and update because the UI posts the full attendance matrix with the
 * session form. Fail-loud: every $wpdb write return value is checked
 * and failures land in the Logger.
 */
class SessionsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/sessions', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/sessions/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_sessions' );
    }

    public static function create_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $data = self::extract( $r );
        $data['coach_id'] = get_current_user_id();

        if ( $data['title'] === '' || $data['session_date'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'Title and date are required.', 'talenttrack' ), 400 );
        }

        $ok = $wpdb->insert( "{$p}tt_sessions", $data );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.save.failed', [ 'db_error' => $err, 'payload' => $data ] );
            return RestResponse::error(
                'db_error',
                __( 'The session could not be saved. The database rejected the operation.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }
        $session_id = (int) $wpdb->insert_id;

        $att_failures = self::write_attendance( $session_id, self::attendance_from_request( $r ) );
        if ( $att_failures ) {
            Logger::error( 'session.attendance.save.failed', [ 'session_id' => $session_id, 'failures' => $att_failures ] );
            return RestResponse::error(
                'partial_save',
                __( 'The session was saved, but some attendance rows could not be stored.', 'talenttrack' ),
                500,
                [ 'session_id' => $session_id, 'failures' => $att_failures ]
            );
        }

        return RestResponse::success( [ 'id' => $session_id ] );
    }

    public static function update_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $session_id = absint( $r['id'] );
        if ( $session_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid session id.', 'talenttrack' ), 400 );
        }

        $data = self::extract( $r );
        // Preserve original coach on update.
        unset( $data['coach_id'] );

        $ok = $wpdb->update( "{$p}tt_sessions", $data, [ 'id' => $session_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.update.failed', [ 'db_error' => $err, 'session_id' => $session_id ] );
            return RestResponse::error(
                'db_error',
                __( 'The session could not be updated. The database rejected the operation.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }

        if ( self::request_has_attendance( $r ) ) {
            $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $session_id ] );
            $att_failures = self::write_attendance( $session_id, self::attendance_from_request( $r ) );
            if ( $att_failures ) {
                Logger::error( 'session.attendance.update.failed', [ 'session_id' => $session_id, 'failures' => $att_failures ] );
                return RestResponse::error(
                    'partial_save',
                    __( 'The session was updated, but some attendance rows could not be stored.', 'talenttrack' ),
                    500,
                    [ 'session_id' => $session_id, 'failures' => $att_failures ]
                );
            }
        }

        return RestResponse::success( [ 'id' => $session_id ] );
    }

    public static function delete_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $session_id = absint( $r['id'] );
        if ( $session_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid session id.', 'talenttrack' ), 400 );
        }

        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $session_id ] );
        $ok = $wpdb->delete( "{$p}tt_sessions", [ 'id' => $session_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.delete.failed', [ 'db_error' => $err, 'session_id' => $session_id ] );
            return RestResponse::error(
                'db_error',
                __( 'The session could not be deleted.', 'talenttrack' ),
                500,
                [ 'db_error' => $err ]
            );
        }

        return RestResponse::success( [ 'deleted' => true, 'id' => $session_id ] );
    }

    /**
     * @return array<string, mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        return [
            'title'        => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
            'session_date' => sanitize_text_field( (string) ( $r['session_date'] ?? '' ) ),
            'team_id'      => absint( $r['team_id'] ?? 0 ),
            'coach_id'     => get_current_user_id(),
            'location'     => sanitize_text_field( (string) ( $r['location'] ?? '' ) ),
            'notes'        => sanitize_textarea_field( (string) ( $r['notes'] ?? '' ) ),
        ];
    }

    /**
     * Accept attendance under either `attendance` (new name) or the
     * legacy `att` key that the pre-REST form used.
     *
     * @return array<int, array{status:string, notes:string}>
     */
    private static function attendance_from_request( \WP_REST_Request $r ): array {
        $raw = $r['attendance'] ?? $r['att'] ?? [];
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $player_id => $fields ) {
            if ( ! is_array( $fields ) ) continue;
            $pid = absint( $player_id );
            if ( $pid <= 0 ) continue;
            $out[ $pid ] = [
                'status' => sanitize_text_field( (string) ( $fields['status'] ?? 'Present' ) ),
                'notes'  => sanitize_text_field( (string) ( $fields['notes'] ?? '' ) ),
            ];
        }
        return $out;
    }

    private static function request_has_attendance( \WP_REST_Request $r ): bool {
        return isset( $r['attendance'] ) || isset( $r['att'] );
    }

    /**
     * @param array<int, array{status:string, notes:string}> $rows
     * @return array<int, array{player_id:int, db_error:string}>
     */
    private static function write_attendance( int $session_id, array $rows ): array {
        if ( ! $rows ) return [];
        global $wpdb; $p = $wpdb->prefix;
        $failures = [];
        foreach ( $rows as $pid => $fields ) {
            $ok = $wpdb->insert( "{$p}tt_attendance", [
                'session_id' => $session_id,
                'player_id'  => (int) $pid,
                'status'     => $fields['status'],
                'notes'      => $fields['notes'],
            ] );
            if ( $ok === false ) {
                $failures[] = [ 'player_id' => (int) $pid, 'db_error' => (string) $wpdb->last_error ];
            }
        }
        return $failures;
    }
}
