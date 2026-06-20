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

        // #1033 — `finalize` is the new explicit transition from
        // PENDING_REVIEW to the terminal FINALIZED state. `finish`
        // stays on the URL surface (it's the live-tap "End match"
        // route) but now lands in PENDING_REVIEW so the coach can
        // still edit goals / subs / score post-match.
        foreach ( [ 'start-half', 'end-half', 'pause', 'resume', 'score', 'substitution', 'goal-event', 'finish', 'finalize' ] as $action ) {
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

        // #1473 — starting the match (half 1 from a not-yet-started
        // execution) is gated to match day. Second-half starts and
        // idempotent re-calls of an already-started match are not
        // re-gated (an offline-queue replay must still land).
        if ( $half === 1 ) {
            global $wpdb;
            $state = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT state FROM {$wpdb->prefix}tt_match_execution WHERE id = %d AND club_id = %d",
                $exec_id, CurrentClub::id()
            ) );
            $not_started = ( $state === '' || $state === MatchExecutionState::NOT_STARTED );
            if ( $not_started && ! self::isMatchDay( absint( $r['activity_id'] ) ) ) {
                return RestResponse::error(
                    'not_match_day',
                    __( 'The match can only be started on match day.', 'talenttrack' ),
                    409
                );
            }
        }

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
        // #1033 — ending the second half lands in PENDING_REVIEW (was
        // FINISHED). The coach reviews goals / subs / score post-match
        // and then Finalize locks it.
        $repo->update( $exec_id, [
            'state' => $half === 1 ? MatchExecutionState::HALF_TIME : MatchExecutionState::PENDING_REVIEW,
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
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;
        $body = $r->get_json_params();
        $home = max( 0, min( 99, (int) ( $body['home'] ?? 0 ) ) );
        $away = max( 0, min( 99, (int) ( $body['away'] ?? 0 ) ) );
        $repo = new MatchExecutionRepository();
        $repo->update( $exec_id, [
            'home_score' => $home,
            'away_score' => $away,
        ] );
        // #1048 — score edits in PENDING_REVIEW don't affect minutes
        // arithmetic but DO need the attendance row's `minutes_played`
        // to stay current for downstream reports. Skipping recompute
        // here (a score change touches nothing in computeMinutes); the
        // sub / goal endpoints below DO recompute since they change
        // either the sub log or the implied roster shape.
        return RestResponse::success( [ 'execution_id' => $exec_id, 'home' => $home, 'away' => $away ] );
    }

    // -----------------------------------------------------------------
    // Event logs
    // -----------------------------------------------------------------

    public static function route_substitution( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;
        $body = $r->get_json_params();
        $event_uuid    = (string) ( $body['event_uuid'] ?? '' );
        $half          = (int) ( $body['half'] ?? 0 );
        $minute        = (int) ( $body['minute'] ?? 0 );
        $player_off_id = (int) ( $body['player_off'] ?? 0 );
        $player_on_id  = (int) ( $body['player_on'] ?? 0 );

        if ( $event_uuid === '' || $half < 1 || $half > 2 || $player_off_id <= 0 || $player_on_id <= 0 ) {
            return RestResponse::error( 'bad_input', __( 'Substitution payload missing required fields.', 'talenttrack' ), 400 );
        }
        $repo = new MatchExecutionRepository();
        $repo->logSubstitution( $exec_id, $event_uuid, $half, $minute, $player_off_id, $player_on_id );
        // #1048 — sub log changed → minutes need to be re-derived.
        // Only when state is PENDING_REVIEW; live writes happen too
        // frequently and the final recompute lands at end-of-second-
        // half via route_finish.
        self::recomputeIfPendingReview( $repo, $exec_id );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    public static function route_goal_event( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;
        $body = $r->get_json_params();
        $event_uuid = (string) ( $body['event_uuid'] ?? '' );
        $player_id  = (int) ( $body['player_id'] ?? 0 );
        $half       = (int) ( $body['half'] ?? 0 );
        $minute     = (int) ( $body['minute'] ?? 0 );

        if ( $event_uuid === '' || $player_id <= 0 || $half < 1 || $half > 2 ) {
            return RestResponse::error( 'bad_input', __( 'Goal-event payload missing required fields.', 'talenttrack' ), 400 );
        }
        $repo = new MatchExecutionRepository();
        $repo->logGoalEvent( $exec_id, $event_uuid, $player_id, $half, $minute );
        // #1048 — goal events don't affect minutes_played directly
        // (computeMinutes ignores goal_events), but they do affect
        // any downstream summary that mirrors the goal log. Recompute
        // call is cheap and keeps the contract uniform; if profiling
        // shows it's hot, gate this on a config switch.
        self::recomputeIfPendingReview( $repo, $exec_id );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    public static function route_goal_event_delete( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;
        $event_uuid = (string) $r['event_uuid'];
        $repo = new MatchExecutionRepository();
        $repo->reverseGoalEvent( $event_uuid );
        self::recomputeIfPendingReview( $repo, $exec_id );
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

        // #1033 — End-match now lands in PENDING_REVIEW (was FINISHED).
        // Goals / subs / score remain editable post-match; the coach
        // taps Finalize (route_finalize below) when ready to lock.
        $repo->update( $exec_id, [
            'state'                => MatchExecutionState::PENDING_REVIEW,
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

        // 3. #1048 — recompute attendance + minutes from prep + sub
        // log. The inline write block lives on the repository now so
        // PENDING_REVIEW edits + finalize can re-fire it (see
        // `MatchExecutionRepository::recomputeAttendanceAndMinutes`).
        $repo->recomputeAttendanceAndMinutes( $exec_id );

        Logger::info( 'match_execution.finish', [
            'activity_id'  => $activity_id,
            'execution_id' => $exec_id,
            'home_score'   => (int) $exec->home_score,
            'away_score'   => (int) $exec->away_score,
        ] );

        return RestResponse::success( [
            'execution_id' => $exec_id,
            'activity_id'  => $activity_id,
            'state'        => MatchExecutionState::PENDING_REVIEW,
        ] );
    }

    /**
     * #1033 — explicit "Finalize" transition. Moves a PENDING_REVIEW
     * execution to the terminal FINALIZED state. Read-only thereafter
     * (score, goal-event, substitution endpoints refuse writes once
     * the execution is FINALIZED — see `assertEditable()`).
     *
     * No-op (returns 409) if the execution is already FINALIZED or
     * not yet in PENDING_REVIEW. Attendance + minutes were already
     * written on the End-match tap (route_finish); finalize only
     * flips the state.
     */
    public static function route_finalize( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;

        global $wpdb;
        $p = $wpdb->prefix;
        $exec = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, state, activity_id FROM {$p}tt_match_execution WHERE id = %d AND club_id = %d",
            $exec_id, CurrentClub::id()
        ) );
        if ( ! $exec ) return RestResponse::error( 'not_found', __( 'Execution not found.', 'talenttrack' ), 404 );

        $current = (string) ( $exec->state ?? '' );
        if ( $current === MatchExecutionState::FINALIZED ) {
            return RestResponse::success( [
                'execution_id' => $exec_id,
                'state'        => MatchExecutionState::FINALIZED,
                'note'         => 'already_finalized',
            ] );
        }
        if ( $current !== MatchExecutionState::PENDING_REVIEW ) {
            return RestResponse::error(
                'bad_state',
                __( 'Match must end (state pending_review) before it can be finalized.', 'talenttrack' ),
                409
            );
        }

        // #1048 — belt-and-braces recompute before lock. Even though
        // every PENDING_REVIEW edit already recomputed, a fresh pass
        // here closes the window where a missed write (offline-queue
        // replay, transient DB error) leaves derived totals stale.
        $repo = new MatchExecutionRepository();
        $repo->recomputeAttendanceAndMinutes( $exec_id );

        $repo->update( $exec_id, [
            'state' => MatchExecutionState::FINALIZED,
        ] );

        Logger::info( 'match_execution.finalize', [
            'execution_id' => $exec_id,
            'activity_id'  => (int) $exec->activity_id,
        ] );

        return RestResponse::success( [
            'execution_id' => $exec_id,
            'activity_id'  => (int) $exec->activity_id,
            'state'        => MatchExecutionState::FINALIZED,
        ] );
    }

    /**
     * #1033 — guard for the write endpoints (score / substitution /
     * goal-event). The endpoints accept writes only when the execution
     * is in a `MatchExecutionState::EDITABLE` state — the live trio +
     * PENDING_REVIEW. FINALIZED refuses with HTTP 409.
     *
     * Returns null on success, or an error WP_REST_Response on refusal.
     * NOT_STARTED is implicitly tolerated — start-half hasn't happened
     * yet but the offline-queue replay path may fire a goal-event
     * before the half-start it batched against; the existing endpoints
     * accept those today and #1033 doesn't change that.
     */
    /**
     * #1048 — fire `recomputeAttendanceAndMinutes` only when the
     * execution is in PENDING_REVIEW. Live writes during the match
     * happen often; recomputing on every tap would thrash the
     * attendance table and the final pass on `route_finish` covers
     * the live trio. PENDING_REVIEW edits are the ones that need
     * the side-effect to keep derived totals fresh.
     */
    private static function recomputeIfPendingReview( MatchExecutionRepository $repo, int $exec_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $state = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT state FROM {$p}tt_match_execution WHERE id = %d AND club_id = %d",
            $exec_id, CurrentClub::id()
        ) );
        if ( $state === MatchExecutionState::PENDING_REVIEW ) {
            $repo->recomputeAttendanceAndMinutes( $exec_id );
        }
    }

    private static function assertEditable( int $exec_id ): ?\WP_REST_Response {
        global $wpdb;
        $p = $wpdb->prefix;
        $state = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT state FROM {$p}tt_match_execution WHERE id = %d AND club_id = %d",
            $exec_id, CurrentClub::id()
        ) );
        if ( $state === '' || $state === MatchExecutionState::NOT_STARTED ) {
            return null; // pre-kickoff queue replay tolerated
        }
        if ( MatchExecutionState::isEditable( $state ) ) {
            return null;
        }
        return RestResponse::error(
            'finalized',
            __( 'This match is finalized and no longer accepts edits.', 'talenttrack' ),
            409
        );
    }

    /**
     * Resolve or create the execution row for the activity. Requires a
     * Match Prep to exist (#838 hard dependency).
     *
     * @return array{0:int, 1:?\WP_REST_Response} [execution_id, error_response_or_null]
     */
    /**
     * #1473 — true when the activity's `session_date` is the server's
     * current date. The match-start transition is gated on this.
     */
    private static function isMatchDay( int $activity_id ): bool {
        if ( $activity_id <= 0 ) return false;
        global $wpdb;
        $session_date = $wpdb->get_var( $wpdb->prepare(
            "SELECT session_date FROM {$wpdb->prefix}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        if ( ! $session_date ) return false;
        // #1520 — shared rule with the view + detail-page button.
        return \TT\Domain\Vocabularies\Enums\MatchExecutionState::isMatchDay( (string) $session_date );
    }

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
