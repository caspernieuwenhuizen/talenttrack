<?php
namespace TT\REST;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sessions_Controller — REST endpoints for tt_sessions + tt_attendance.
 *
 * #0019 Sprint 1 introduces this alongside an expanded Goals_Controller
 * and enriched Evaluations_Controller to replace the legacy
 * FrontendAjax / includes/Frontend/Ajax shims. Every endpoint
 * replicates the fail-loud error handling that the newer
 * src/Shared/Frontend/FrontendAjax shim brought in during v2.6.2 —
 * $wpdb insert/update return values are checked, failures land in the
 * Logger with structured context, and the client gets a WP_Error-style
 * response with the DB error visible in `detail` rather than a silent
 * success.
 *
 * Routes:
 *   POST   /talenttrack/v1/sessions
 *   PUT    /talenttrack/v1/sessions/{id}
 *   DELETE /talenttrack/v1/sessions/{id}
 *
 * Attendance is a nested sub-resource handled inline on create/update
 * because the existing UI posts the full attendance matrix with the
 * session form. A future sprint may break it out.
 */
class Sessions_Controller {

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
                'args'                => self::session_args(),
            ],
        ] );
        register_rest_route( self::NS, '/sessions/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => array_merge( self::session_args(), [ 'id' => [ 'required' => true, 'type' => 'integer' ] ] ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_session' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer' ] ],
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

        $ok = $wpdb->insert( "{$p}tt_sessions", $data );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.save.failed', [ 'db_error' => $err, 'payload' => $data ] );
            return new \WP_Error(
                'rest_session_save_failed',
                __( 'The session could not be saved. The database rejected the operation.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $err ]
            );
        }
        $session_id = (int) $wpdb->insert_id;

        $att_failures = self::write_attendance( $session_id, self::attendance_from_request( $r ) );
        if ( $att_failures ) {
            Logger::error( 'session.attendance.save.failed', [ 'session_id' => $session_id, 'failures' => $att_failures ] );
            return new \WP_Error(
                'rest_session_attendance_failed',
                __( 'The session was saved, but some attendance rows could not be stored.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $att_failures[0]['db_error'] ?? '', 'session_id' => $session_id ]
            );
        }

        return rest_ensure_response( [
            'id'      => $session_id,
            'message' => __( 'Session saved.', 'talenttrack' ),
        ] );
    }

    public static function update_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $session_id = absint( $r['id'] );
        if ( $session_id <= 0 ) {
            return new \WP_Error( 'rest_bad_id', __( 'Invalid session id.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $data = self::extract( $r );
        unset( $data['coach_id'] ); // preserve original coach
        $ok = $wpdb->update( "{$p}tt_sessions", $data, [ 'id' => $session_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.update.failed', [ 'db_error' => $err, 'session_id' => $session_id ] );
            return new \WP_Error(
                'rest_session_update_failed',
                __( 'The session could not be updated. The database rejected the operation.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $err ]
            );
        }

        if ( self::request_has_attendance( $r ) ) {
            $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $session_id ] );
            $att_failures = self::write_attendance( $session_id, self::attendance_from_request( $r ) );
            if ( $att_failures ) {
                Logger::error( 'session.attendance.update.failed', [ 'session_id' => $session_id, 'failures' => $att_failures ] );
                return new \WP_Error(
                    'rest_session_attendance_update_failed',
                    __( 'The session was updated, but some attendance rows could not be stored.', 'talenttrack' ),
                    [ 'status' => 500, 'detail' => $att_failures[0]['db_error'] ?? '', 'session_id' => $session_id ]
                );
            }
        }

        return rest_ensure_response( [ 'id' => $session_id, 'message' => __( 'Session updated.', 'talenttrack' ) ] );
    }

    public static function delete_session( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;

        $session_id = absint( $r['id'] );
        if ( $session_id <= 0 ) {
            return new \WP_Error( 'rest_bad_id', __( 'Invalid session id.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $session_id ] );
        $ok = $wpdb->delete( "{$p}tt_sessions", [ 'id' => $session_id ] );
        if ( $ok === false ) {
            $err = (string) $wpdb->last_error;
            Logger::error( 'session.delete.failed', [ 'db_error' => $err, 'session_id' => $session_id ] );
            return new \WP_Error(
                'rest_session_delete_failed',
                __( 'The session could not be deleted.', 'talenttrack' ),
                [ 'status' => 500, 'detail' => $err ]
            );
        }

        return rest_ensure_response( [ 'deleted' => true, 'id' => $session_id ] );
    }

    /* ═══ Helpers ═══ */

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

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function session_args(): array {
        return [
            'title'        => [ 'type' => 'string', 'required' => true ],
            'session_date' => [ 'type' => 'string', 'required' => true ],
            'team_id'      => [ 'type' => 'integer' ],
            'location'     => [ 'type' => 'string' ],
            'notes'        => [ 'type' => 'string' ],
            'attendance'   => [ 'type' => 'object' ],
        ];
    }
}
