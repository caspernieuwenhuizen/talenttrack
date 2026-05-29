<?php
namespace TT\Modules\MatchExecution\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchExecutionRestController (#847) — live-match REST surface.
 *
 * Endpoints (all under `/talenttrack/v1/match-execution/`):
 *   POST   /<activity_id>/start-half     {half}
 *   POST   /<activity_id>/end-half       {half}
 *   POST   /<activity_id>/pause          {half}
 *   POST   /<activity_id>/resume         {half, pause_seconds}
 *   POST   /<activity_id>/score          {home, away}
 *   POST   /<activity_id>/substitution   {event_uuid, half, minute, player_off, player_on}
 *   POST   /<activity_id>/goal-event     {event_uuid, player_id, half, minute}
 *   DELETE /<activity_id>/goal-event/<event_uuid>
 *   POST   /<activity_id>/finish
 *
 * Idempotent endpoints take a client-generated `event_uuid` so the
 * offline-queue flush can replay without double-inserting.
 *
 * Cap: tt_edit_activities (existing).
 */
class MatchExecutionRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $base = '/match-execution/(?P<activity_id>\d+)';

        foreach ( [ 'start-half', 'end-half', 'pause', 'resume', 'score', 'substitution', 'goal-event', 'finish' ] as $action ) {
            register_rest_route( self::NS, $base . '/' . $action, [
                [
                    'methods'             => 'POST',
                    'callback'            => [ __CLASS__, 'route_' . str_replace( '-', '_', $action ) ],
                    'permission_callback' => [ __CLASS__, 'can_edit' ],
                ],
            ] );
        }

        register_rest_route( self::NS, $base . '/goal-event/(?P<event_uuid>[a-f0-9-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'route_goal_event_delete' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_activities' );
    }

    // -----------------------------------------------------------------
    // Half lifecycle
    // -----------------------------------------------------------------

    public static function route_start_half( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $half = (int) $r->get_json_params()['half'] ?? 1;
        if ( $half !== 1 && $half !== 2 ) return RestResponse::error( 'bad_half', __( 'Half must be 1 or 2.', 'talenttrack' ), 400 );

        $repo = new MatchExecutionRepository();
        $col  = $half === 1 ? 'first_half_started_at' : 'second_half_started_at';
        $next_state = $half === 1 ? MatchExecutionState::FIRST_HALF : MatchExecutionState::SECOND_HALF;
        $repo->update( $exec_id, [
            'state' => $next_state,
            $col    => current_time( 'mysql', true ),
        ] );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'state' => $next_state ] );
    }

    public static function route_end_half( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $half = (int) $r->get_json_params()['half'] ?? 1;
        if ( $half !== 1 && $half !== 2 ) return RestResponse::error( 'bad_half', __( 'Half must be 1 or 2.', 'talenttrack' ), 400 );

        $repo = new MatchExecutionRepository();
        $col  = $half === 1 ? 'first_half_ended_at' : 'second_half_ended_at';
        $repo->update( $exec_id, [
            'state' => $half === 1 ? MatchExecutionState::HALF_TIME : MatchExecutionState::FINISHED,
            $col    => current_time( 'mysql', true ),
        ] );
        return RestResponse::success( [ 'execution_id' => $exec_id ] );
    }

    public static function route_pause( \WP_REST_Request $r ): \WP_REST_Response {
        // Pause/resume accounting is computed client-side and posted on
        // resume; the pause endpoint just records the intent.
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        return RestResponse::success( [ 'execution_id' => $exec_id, 'paused_at' => current_time( 'mysql', true ) ] );
    }

    public static function route_resume( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $body = $r->get_json_params();
        $half = (int) ( $body['half'] ?? 1 );
        $pause_seconds = max( 0, (int) ( $body['pause_seconds'] ?? 0 ) );
        $col = $half === 1 ? 'first_half_pause_seconds' : 'second_half_pause_seconds';

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_match_execution
                SET {$col} = {$col} + %d
              WHERE id = %d AND club_id = %d",
            $pause_seconds, $exec_id, CurrentClub::id()
        ) );
        return RestResponse::success( [ 'execution_id' => $exec_id ] );
    }

    public static function route_score( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $body = $r->get_json_params();
        $home = max( 0, min( 99, (int) ( $body['home'] ?? 0 ) ) );
        $away = max( 0, min( 99, (int) ( $body['away'] ?? 0 ) ) );
        ( new MatchExecutionRepository() )->update( $exec_id, [
            'home_score' => $home,
            'away_score' => $away,
        ] );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'home' => $home, 'away' => $away ] );
    }

    // -----------------------------------------------------------------
    // Event logs
    // -----------------------------------------------------------------

    public static function route_substitution( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $body = $r->get_json_params();
        $event_uuid    = (string) ( $body['event_uuid'] ?? '' );
        $half          = (int) ( $body['half'] ?? 0 );
        $minute        = (int) ( $body['minute'] ?? 0 );
        $player_off_id = (int) ( $body['player_off'] ?? 0 );
        $player_on_id  = (int) ( $body['player_on'] ?? 0 );

        if ( $event_uuid === '' || $half < 1 || $half > 2 || $player_off_id <= 0 || $player_on_id <= 0 ) {
            return RestResponse::error( 'bad_input', __( 'Substitution payload missing required fields.', 'talenttrack' ), 400 );
        }
        ( new MatchExecutionRepository() )->logSubstitution( $exec_id, $event_uuid, $half, $minute, $player_off_id, $player_on_id );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    public static function route_goal_event( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $body = $r->get_json_params();
        $event_uuid = (string) ( $body['event_uuid'] ?? '' );
        $player_id  = (int) ( $body['player_id'] ?? 0 );
        $half       = (int) ( $body['half'] ?? 0 );
        $minute     = (int) ( $body['minute'] ?? 0 );

        if ( $event_uuid === '' || $player_id <= 0 || $half < 1 || $half > 2 ) {
            return RestResponse::error( 'bad_input', __( 'Goal-event payload missing required fields.', 'talenttrack' ), 400 );
        }
        ( new MatchExecutionRepository() )->logGoalEvent( $exec_id, $event_uuid, $player_id, $half, $minute );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    public static function route_goal_event_delete( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $event_uuid = (string) $r['event_uuid'];
        ( new MatchExecutionRepository() )->reverseGoalEvent( $event_uuid );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    // -----------------------------------------------------------------
    // End-of-match auto-flow
    // -----------------------------------------------------------------

    public static function route_finish( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;

        global $wpdb;
        $p = $wpdb->prefix;

        $repo = new MatchExecutionRepository();
        $exec = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_match_execution WHERE id = %d AND club_id = %d",
            $exec_id, CurrentClub::id()
        ) );
        if ( ! $exec ) return RestResponse::error( 'not_found', __( 'Execution not found.', 'talenttrack' ), 404 );

        $activity_id = (int) $exec->activity_id;

        // 1. Mark execution finished + capture second-half end.
        $repo->update( $exec_id, [
            'state'                => MatchExecutionState::FINISHED,
            'second_half_ended_at' => current_time( 'mysql', true ),
        ] );

        // 2. Flip the activity to completed.
        $wpdb->update(
            "{$p}tt_activities",
            [
                'activity_status_key' => 'completed',
                'plan_state'          => 'completed',
                'home_score'          => (int) $exec->home_score,
                'away_score'          => (int) $exec->away_score,
            ],
            [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ]
        );

        // 3. Write attendance + minutes from the prep snapshot + the
        // substitution log.
        $prep = ( new MatchPrepRepository() )->findByActivity( $activity_id );
        if ( $prep ) {
            $prep_id = (int) $prep->id;
            $avail   = ( new MatchPrepRepository() )->listAvailability( $prep_id );
            $lineup  = ( new MatchPrepRepository() )->listLineup( $prep_id );

            $starting_xi_half1 = [];
            $starting_xi_half2 = [];
            foreach ( $lineup as $l ) {
                if ( (int) $l->half === 1 ) $starting_xi_half1[] = (int) $l->player_id;
                if ( (int) $l->half === 2 ) $starting_xi_half2[] = (int) $l->player_id;
            }

            $minutes_map = $repo->computeMinutes(
                $exec_id,
                $starting_xi_half1,
                $starting_xi_half2,
                (int) $prep->half_length_minutes,
                (int) $prep->half_length_minutes
            );

            foreach ( $avail as $a ) {
                $pid    = (int) $a->player_id;
                $status = (string) $a->status;
                if ( strcasecmp( $status, 'Present' ) === 0 ) {
                    $status = 'Present';
                }
                $minutes = $minutes_map[ $pid ] ?? 0;
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_attendance
                      WHERE activity_id = %d AND player_id = %d AND club_id = %d LIMIT 1",
                    $activity_id, $pid, CurrentClub::id()
                ) );
                if ( $existing ) {
                    $wpdb->update( "{$p}tt_attendance", [
                        'status'          => $status,
                        'minutes_played'  => $minutes,
                    ], [ 'id' => (int) $existing ] );
                } else {
                    $wpdb->insert( "{$p}tt_attendance", [
                        'club_id'        => CurrentClub::id(),
                        'activity_id'    => $activity_id,
                        'player_id'      => $pid,
                        'status'         => $status,
                        'minutes_played' => $minutes,
                    ] );
                }
            }
        }

        Logger::info( 'match_execution.finish', [
            'activity_id'  => $activity_id,
            'execution_id' => $exec_id,
            'home_score'   => (int) $exec->home_score,
            'away_score'   => (int) $exec->away_score,
        ] );

        return RestResponse::success( [
            'execution_id' => $exec_id,
            'activity_id'  => $activity_id,
            'state'        => MatchExecutionState::FINISHED,
        ] );
    }

    /**
     * Resolve or create the execution row for the activity. Requires a
     * Match Prep to exist (#838 hard dependency).
     *
     * @return array{0:int, 1:?\WP_REST_Response} [execution_id, error_response_or_null]
     */
    private static function ensureExecution( \WP_REST_Request $r ): array {
        $activity_id = absint( $r['activity_id'] );
        if ( $activity_id <= 0 ) {
            return [ 0, RestResponse::error( 'bad_activity', __( 'Invalid activity id.', 'talenttrack' ), 400 ) ];
        }
        $prep = ( new MatchPrepRepository() )->findByActivity( $activity_id );
        if ( ! $prep ) {
            return [ 0, RestResponse::error( 'no_prep', __( 'Plan this match first before running execution.', 'talenttrack' ), 409 ) ];
        }
        $exec_id = ( new MatchExecutionRepository() )->ensureForActivity( $activity_id, (int) $prep->id );
        if ( $exec_id <= 0 ) {
            return [ 0, RestResponse::error( 'db_error', __( 'Could not create the execution row.', 'talenttrack' ), 500 ) ];
        }
        return [ $exec_id, null ];
    }
}
