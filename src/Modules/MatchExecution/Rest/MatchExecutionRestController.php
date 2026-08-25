<?php
namespace TT\Modules\MatchExecution\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchExecution\Repositories\TrackedEventsRepository;
use TT\Modules\MatchExecution\Services\MatchEventFeedService;
use TT\Modules\MatchExecution\Services\PitchLayoutService;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Infrastructure\Query\QueryHelpers;

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
 *   POST   /<activity_id>/goal-event     {event_uuid, player_id, half, minute,
 *                                        team, assist_player_id, is_own_goal}
 *   PATCH  /<activity_id>/goal-event/<event_uuid>  {half, minute, player_id,
 *                                        assist_player_id, is_own_goal}
 *   DELETE /<activity_id>/goal-event/<event_uuid>
 *   DELETE /<activity_id>/substitution/<event_uuid>   (#2269 undo)
 *   POST   /<activity_id>/finish
 *   POST   /<activity_id>/finalize
 *   POST   /<activity_id>/reopen        (#2271 re-open finalized)
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
        // #2271 — `reopen` transitions a FINALIZED execution back to
        // PENDING_REVIEW so any datapoint can be corrected post-finalize.
        foreach ( [ 'start-half', 'end-half', 'pause', 'resume', 'score', 'substitution', 'goal-event', 'finish', 'finalize', 'reopen' ] as $action ) {
            register_rest_route( self::NS, $base . '/' . $action, [
                [
                    'methods'             => 'POST',
                    'callback'            => [ __CLASS__, 'route_' . str_replace( '-', '_', $action ) ],
                    'permission_callback' => [ __CLASS__, 'can_edit' ],
                ],
            ] );
        }

        // #2275 — PATCH corrects a logged goal's half + minute (ours or the
        // opponent's), mirroring the substitution PATCH.
        register_rest_route( self::NS, $base . '/goal-event/(?P<event_uuid>[a-f0-9-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'route_goal_event_delete' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'route_goal_event_update' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // #2269 — undo a logged substitution by its client event_uuid.
        // #2273 — PATCH corrects the half + minute of a logged sub (coach
        // forgot to log it on time); minutes recompute follows.
        register_rest_route( self::NS, $base . '/substitution/(?P<event_uuid>[a-f0-9-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'route_substitution_delete' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'route_substitution_update' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // #1713 — read-only feed + pitch lineup for the live surface
        // (vertical positional pitch + chronological "Live verloop").
        register_rest_route( self::NS, $base . '/event-feed', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'route_event_feed' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, $base . '/pitch-lineup', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'route_pitch_lineup' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // Rebuild — per-player minute override. When an execution owns an
        // activity's minutes (see the arbiter), this is the ONLY way to
        // hand-correct a player's minutes; the manual attendance path
        // defers with a 409. {player_id, minutes|null} — null clears.
        register_rest_route( self::NS, $base . '/minutes', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'route_minutes_override' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // Rebuild — tracked development-action events. One per coach tap of
        // the +/- counter on a prep-flagged player. Distinct from goals;
        // these do not affect the score. Append-only, soft-delete on undo.
        register_rest_route( self::NS, $base . '/tracked-event', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'route_tracked_event' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, $base . '/tracked-event/(?P<event_uuid>[a-f0-9-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'route_tracked_event_delete' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_activities' );
    }
    // -----------------------------------------------------------------
    // #1713 — read endpoints (vertical pitch + chronological feed)
    // -----------------------------------------------------------------

    /**
     * GET /<activity_id>/event-feed — the merged, time-ordered list of
     * goals + substitutions with a running score. Business logic lives
     * in MatchEventFeedService; this controller only adapts the shape.
     */
    public static function route_event_feed( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        if ( $activity_id <= 0 ) {
            return RestResponse::error( 'bad_activity', __( 'Invalid activity id.', 'talenttrack' ), 400 );
        }
        $feed = ( new MatchEventFeedService() )->feedForActivity( $activity_id );
        return RestResponse::success( [
            'activity_id' => $activity_id,
            'events'      => $feed,
        ] );
    }

    /**
     * GET /<activity_id>/pitch-lineup — the first-half starting XI laid
     * out by position for the vertical pitch. Requires a Match Prep
     * (#838 hard dependency); returns an empty layout when none exists.
     */
    public static function route_pitch_lineup( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        if ( $activity_id <= 0 ) {
            return RestResponse::error( 'bad_activity', __( 'Invalid activity id.', 'talenttrack' ), 400 );
        }
        $prep = ( new MatchPrepRepository() )->findByActivity( $activity_id );
        if ( ! $prep ) {
            return RestResponse::success( [
                'activity_id' => $activity_id,
                'slots'       => [],
            ] );
        }

        $prep_repo = new MatchPrepRepository();
        $lineup    = $prep_repo->listLineup( (int) $prep->id );

        $slot_to_player = [];
        $player_ids     = [];
        foreach ( $lineup as $l ) {
            if ( (int) $l->half !== 1 ) {
                continue;
            }
            $slot = (int) $l->slot_number;
            $pid  = (int) $l->player_id;
            if ( $slot >= 1 && $slot <= 11 && $pid > 0 ) {
                $slot_to_player[ $slot ] = $pid;
                $player_ids[]            = $pid;
            }
        }

        $player_meta = self::playerMeta( $player_ids );

        $slots = ( new PitchLayoutService() )->positionedXi(
            (int) ( $prep->formation_template_id ?? 0 ),
            $slot_to_player,
            $player_meta
        );

        // Rebuild — tracked development-action map for the live surface:
        // prep-flagged players + their action label + the current
        // non-reversed tap count (server-persisted so counts survive
        // reconnect / reload).
        $tracked_map = [];
        $exec = ( new MatchExecutionRepository() )->findByActivity( $activity_id );
        $counts = $exec ? ( new TrackedEventsRepository() )->countsByPlayer( (int) $exec->id ) : [];
        foreach ( $prep_repo->listTrackedPlayers( (int) $prep->id ) as $pid => $flag ) {
            $tracked_map[ $pid ] = [
                'action_label' => (string) ( $flag['attention_text'] ?? '' ),
                'count'        => (int) ( $counts[ $pid ] ?? 0 ),
            ];
        }

        return RestResponse::success( [
            'activity_id' => $activity_id,
            'slots'       => $slots,
            'tracked'     => $tracked_map,
        ] );
    }

    /**
     * Load display name + jersey for a set of players, club-scoped.
     *
     * @param list<int> $player_ids
     * @return array<int, array{name:string, jersey:?int}>
     */
    private static function playerMeta( array $player_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
        if ( empty( $ids ) ) {
            return [];
        }
        global $wpdb;
        /** @var \wpdb $wpdb */
        $in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id IN ($in) AND club_id = %d",
            array_merge( $ids, [ CurrentClub::id() ] )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row->id ] = [
                'name'   => QueryHelpers::player_display_name( $row ),
                'jersey' => $row->jersey_number !== null ? (int) $row->jersey_number : null,
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Rebuild — minute override + tracked development-action events
    // -----------------------------------------------------------------

    /**
     * PATCH /<activity_id>/minutes {player_id, minutes|null}. Sets or clears
     * an explicit per-player minute override on the roster attendance row.
     * The override wins over the sub-log-derived minutes and survives
     * recompute (separate column). Refused once FINALIZED (re-open first).
     */
    public static function route_minutes_override( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        // No editable-state gate here: the override lives in a separate
        // column that recompute never clobbers, so it is safe to set in
        // PENDING_REVIEW and — the #2224 use case — on a FINALIZED match,
        // where the coach corrects an obviously-wrong recorded figure
        // without re-opening the whole match.

        $activity_id = absint( $r['activity_id'] );
        $body        = $r->get_json_params();
        $player_id   = (int) ( $body['player_id'] ?? 0 );
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_input', __( 'A player is required.', 'talenttrack' ), 400 );
        }

        // null (or omitted) clears the override; an int sets it. Clamp 0..200.
        $has_minutes = array_key_exists( 'minutes', (array) $body );
        $minutes = ( ! $has_minutes || $body['minutes'] === null )
            ? null
            : max( 0, min( 200, (int) $body['minutes'] ) );

        $repo = new MatchExecutionRepository();
        if ( ! $repo->setMinuteOverride( $activity_id, $player_id, $minutes ) ) {
            return RestResponse::error(
                'no_attendance_row',
                __( 'No roster row for this player to override.', 'talenttrack' ),
                409
            );
        }
        return RestResponse::success( [
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'minutes'     => $minutes,
        ] );
    }

    /**
     * POST /<activity_id>/tracked-event {event_uuid, player_id, half, minute,
     * action_label?, action_key?}. Logs one development-action tap for a
     * prep-flagged (tracked) player. Rejects a non-tracked player. The
     * action_label defaults to the player's prep attention_text.
     */
    public static function route_tracked_event( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;

        $activity_id = absint( $r['activity_id'] );
        $body        = $r->get_json_params();
        $event_uuid  = (string) ( $body['event_uuid'] ?? '' );
        $player_id   = (int) ( $body['player_id'] ?? 0 );
        $half        = (int) ( $body['half'] ?? 0 );
        $minute      = (int) ( $body['minute'] ?? 0 );
        $action_key  = isset( $body['action_key'] ) ? (string) $body['action_key'] : null;
        $action_label = (string) ( $body['action_label'] ?? '' );

        if ( $event_uuid === '' || $player_id <= 0 || $half < 1 || $half > 2 ) {
            return RestResponse::error( 'bad_input', __( 'Tracked-event payload missing required fields.', 'talenttrack' ), 400 );
        }

        $tracked_repo = new TrackedEventsRepository();
        // Idempotent replay: an already-accepted event falls through to the
        // INSERT IGNORE without re-validating (the roster/tracked set may
        // have shifted since it was first logged offline).
        if ( ! $tracked_repo->trackedEventExists( $event_uuid ) ) {
            [ $half_length ] = self::prepContext( $activity_id );
            $minute_err = self::assertMinuteInRange( $minute, $half_length );
            if ( $minute_err ) return $minute_err;

            // Verify the player is actually tracked in the prep, and resolve
            // the action label from the prep when the client didn't send one.
            $prep = ( new MatchPrepRepository() )->findByActivity( $activity_id );
            $tracked = $prep ? ( new MatchPrepRepository() )->listTrackedPlayers( (int) $prep->id ) : [];
            if ( ! isset( $tracked[ $player_id ] ) ) {
                return RestResponse::error(
                    'player_not_tracked',
                    __( 'This player is not flagged for tracking in the match plan.', 'talenttrack' ),
                    400
                );
            }
            if ( $action_label === '' ) {
                $action_label = (string) ( $tracked[ $player_id ]['attention_text'] ?? '' );
            }
        }

        $tracked_repo->logTrackedEvent( $exec_id, $event_uuid, $player_id, $half, $minute, $action_label, $action_key );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    /**
     * DELETE /<activity_id>/tracked-event/<event_uuid> — undo (soft-delete)
     * a tracked action. Refused once FINALIZED.
     */
    public static function route_tracked_event_delete( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;

        $event_uuid = (string) $r['event_uuid'];
        $tracked_repo = new TrackedEventsRepository();
        if ( ! $tracked_repo->trackedEventExists( $event_uuid ) ) {
            return RestResponse::error( 'not_found', __( 'That tracked action was not found.', 'talenttrack' ), 404 );
        }
        $tracked_repo->reverseTrackedEvent( $event_uuid );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
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
        if ( $player_off_id === $player_on_id ) {
            return RestResponse::error( 'bad_input', __( 'A player cannot be substituted for themselves.', 'talenttrack' ), 400 );
        }

        $repo = new MatchExecutionRepository();

        // #2268 — server-side validation the HTML `max` / disabled options
        // can't guarantee. Reject an out-of-range minute and a roster-
        // impossible swap (off-player not on the pitch, or on-player
        // already on it), so a crafted or fat-fingered write is refused
        // instead of silently clamped. Skip the validation for an
        // offline-queue REPLAY of an already-accepted sub (same
        // event_uuid): the pitch has moved on since it was first logged,
        // so it must fall through to the idempotent INSERT IGNORE unchanged
        // rather than fail the roster check and retry-loop in the queue.
        if ( ! $repo->substitutionExists( $event_uuid ) ) {
            [ $half_length, $starting_xi ] = self::prepContext( absint( $r['activity_id'] ) );
            $minute_err = self::assertMinuteInRange( $minute, $half_length );
            if ( $minute_err ) return $minute_err;

            $on_pitch = $repo->onPitchPlayerIds( $exec_id, $starting_xi );
            if ( ! in_array( $player_off_id, $on_pitch, true ) ) {
                return RestResponse::error(
                    'player_off_not_on_pitch',
                    __( 'The player coming off is not currently on the pitch.', 'talenttrack' ),
                    400
                );
            }
            if ( in_array( $player_on_id, $on_pitch, true ) ) {
                return RestResponse::error(
                    'player_on_already_on',
                    __( 'The player coming on is already on the pitch.', 'talenttrack' ),
                    400
                );
            }
        }

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
        // #2275 — a goal belongs to a team. Ours ('home') carry the scorer;
        // the opponent's ('away') have no tracked individual scorer.
        $team       = ( (string) ( $body['team'] ?? 'home' ) === 'away' ) ? 'away' : 'home';
        // #2856 — optional attribution. A goal with no scorer is a goal the
        // coach could not attribute in the moment, not a malformed payload.
        $assist_id   = (int) ( $body['assist_player_id'] ?? 0 );
        $is_own_goal = ! empty( $body['is_own_goal'] );

        if ( $event_uuid === '' || $half < 1 || $half > 2 ) {
            return RestResponse::error( 'bad_input', __( 'Goal-event payload missing required fields.', 'talenttrack' ), 400 );
        }
        // #2268 — reject an out-of-range minute (< 0 or > half length + 10
        // stoppage) rather than clamping a fat-fingered value.
        [ $half_length ] = self::prepContext( absint( $r['activity_id'] ) );
        $minute_err = self::assertMinuteInRange( $minute, $half_length );
        if ( $minute_err ) return $minute_err;

        $attribution_err = self::assertAttribution( absint( $r['activity_id'] ), $player_id, $assist_id );
        if ( $attribution_err ) return $attribution_err;

        $repo = new MatchExecutionRepository();
        $repo->logGoalEvent( $exec_id, $event_uuid, $player_id, $half, $minute, $team, $assist_id > 0 ? $assist_id : null, $is_own_goal );
        // #2275 — the opponent's timed goals now drive the away scoreline.
        if ( $team === 'away' ) $repo->syncAwayScoreFromGoals( $exec_id );
        // #1048 — goal events don't affect minutes_played directly
        // (computeMinutes ignores goal_events), but they do affect
        // any downstream summary that mirrors the goal log. Recompute
        // call is cheap and keeps the contract uniform; if profiling
        // shows it's hot, gate this on a config switch.
        self::recomputeIfPendingReview( $repo, $exec_id );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid, 'team' => $team ] );
    }

    /**
     * #2275 — PATCH /<activity_id>/goal-event/<event_uuid> {half, minute}.
     * Corrects the half + minute of an already-logged goal (ours or the
     * opponent's). Same guards as the substitution PATCH.
     *
     * #2856 — also corrects the attribution {player_id, assist_player_id,
     * is_own_goal}, which is how a goal saved without a scorer gets one
     * afterwards. The two halves are independent: a payload carrying only
     * `half` + `minute` leaves the attribution untouched, and one carrying
     * only attribution keys leaves the timing untouched. That keeps the
     * pre-existing minute-only PATCH from the review surface working
     * unchanged, and stops an attribution edit from silently resetting a
     * corrected minute to 0.
     */
    public static function route_goal_event_update( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;

        $event_uuid = (string) $r['event_uuid'];
        $body   = $r->get_json_params();
        $has_timing      = array_key_exists( 'half', $body ) || array_key_exists( 'minute', $body );
        $has_attribution = array_key_exists( 'player_id', $body )
            || array_key_exists( 'assist_player_id', $body )
            || array_key_exists( 'is_own_goal', $body );
        $half   = (int) ( $body['half'] ?? 0 );
        $minute = (int) ( $body['minute'] ?? 0 );

        if ( $event_uuid === '' || ( ! $has_timing && ! $has_attribution ) ) {
            return RestResponse::error( 'bad_input', __( 'Goal update payload missing required fields.', 'talenttrack' ), 400 );
        }
        if ( $has_timing && ( $half < 1 || $half > 2 ) ) {
            return RestResponse::error( 'bad_input', __( 'Goal update payload missing required fields.', 'talenttrack' ), 400 );
        }

        $repo = new MatchExecutionRepository();
        if ( ! $repo->goalEventExists( $event_uuid ) ) {
            return RestResponse::error( 'not_found', __( 'Goal not found.', 'talenttrack' ), 404 );
        }

        if ( $has_timing ) {
            [ $half_length ] = self::prepContext( absint( $r['activity_id'] ) );
            $minute_err = self::assertMinuteInRange( $minute, $half_length );
            if ( $minute_err ) return $minute_err;
        }

        $out = [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ];

        if ( $has_attribution ) {
            $existing = $repo->findGoalEvent( $event_uuid );
            if ( ! $existing ) {
                return RestResponse::error( 'not_found', __( 'Goal not found.', 'talenttrack' ), 404 );
            }
            // Absent keys keep whatever is already stored, so a partial
            // payload corrects one field without clearing its siblings.
            $player_id = array_key_exists( 'player_id', $body )
                ? (int) $body['player_id']
                : (int) ( $existing['player_id'] ?? 0 );
            $assist_id = array_key_exists( 'assist_player_id', $body )
                ? (int) $body['assist_player_id']
                : (int) ( $existing['assist_player_id'] ?? 0 );
            $is_own_goal = array_key_exists( 'is_own_goal', $body )
                ? ! empty( $body['is_own_goal'] )
                : ! empty( $existing['is_own_goal'] );

            $attribution_err = self::assertAttribution( absint( $r['activity_id'] ), $player_id, $assist_id );
            if ( $attribution_err ) return $attribution_err;

            $repo->updateGoalAttribution( $event_uuid, $player_id, $assist_id > 0 ? $assist_id : null, $is_own_goal );
            $out['player_id']        = $player_id;
            $out['assist_player_id'] = $assist_id > 0 ? $assist_id : null;
            $out['is_own_goal']      = $is_own_goal;
        }

        if ( $has_timing ) {
            $repo->updateGoalEventMinute( $event_uuid, $half, $minute );
            $out['half']   = $half;
            $out['minute'] = $minute;
        }

        self::recomputeIfPendingReview( $repo, $exec_id );
        return RestResponse::success( $out );
    }

    /**
     * #2856 — refuse an attribution that names somebody outside this match's
     * squad, or that credits the scorer with assisting themselves. `0` is a
     * valid value for both: it means "not recorded", which the live sheet
     * writes when the coach could not see who it was.
     */
    private static function assertAttribution( int $activity_id, int $player_id, int $assist_id ): ?\WP_REST_Response {
        if ( $player_id > 0 && $assist_id > 0 && $player_id === $assist_id ) {
            return RestResponse::error(
                'bad_input',
                __( 'A player cannot assist their own goal.', 'talenttrack' ),
                400
            );
        }
        if ( $player_id <= 0 && $assist_id <= 0 ) return null;

        $squad = self::squadPlayerIds( $activity_id );
        // An empty squad means the prep carries no roster at all; there is
        // nothing to validate against, so don't refuse a legitimate write.
        if ( empty( $squad ) ) return null;

        foreach ( [ $player_id, $assist_id ] as $candidate ) {
            if ( $candidate > 0 && ! in_array( $candidate, $squad, true ) ) {
                return RestResponse::error(
                    'player_not_in_squad',
                    __( 'That player is not in this match squad.', 'talenttrack' ),
                    400
                );
            }
        }
        return null;
    }

    /**
     * #2856 — every player the match prep knows about: everyone with an
     * availability row (whatever their status) plus anyone named in the
     * lineup. Deliberately wider than the pitch — a goal can be scored by
     * a player who came on, and an assist credited to one who has since
     * gone off.
     *
     * @return array<int,int>
     */
    private static function squadPlayerIds( int $activity_id ): array {
        $prep_repo = new MatchPrepRepository();
        $prep      = $prep_repo->findByActivity( $activity_id );
        if ( ! $prep ) return [];
        $prep_id = (int) $prep->id;

        $ids = [];
        $rows = array_merge(
            $prep_repo->listAvailability( $prep_id ),
            $prep_repo->listLineup( $prep_id )
        );
        foreach ( $rows as $row ) {
            $pid = (int) ( $row->player_id ?? 0 );
            if ( $pid > 0 ) $ids[] = $pid;
        }
        return array_values( array_unique( $ids ) );
    }

    public static function route_goal_event_delete( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;
        $event_uuid = (string) $r['event_uuid'];
        $repo = new MatchExecutionRepository();
        $repo->reverseGoalEvent( $event_uuid );
        // #2275 — keep the away scoreline in step if an opponent goal was removed.
        $repo->syncAwayScoreFromGoals( $exec_id );
        self::recomputeIfPendingReview( $repo, $exec_id );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    /**
     * #2269 — DELETE /<activity_id>/substitution/<event_uuid>. Soft-
     * deletes (undoes) a logged substitution. Mirrors the goal-event
     * delete: refuse once the match is FINALIZED, reverse the row, then
     * re-derive minutes so the undone sub flows back out of the persisted
     * `record_type='actual'` totals the reports read.
     */
    public static function route_substitution_delete( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;
        $event_uuid = (string) $r['event_uuid'];
        $repo = new MatchExecutionRepository();
        $repo->reverseSubstitution( $event_uuid );
        self::recomputeIfPendingReview( $repo, $exec_id );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid ] );
    }

    /**
     * #2273 — PATCH /<activity_id>/substitution/<event_uuid> {half, minute}.
     * Corrects the half + minute of an already-logged, non-reversed sub. The
     * coach fixes the *time* they came on/off (the thing they forgot to log
     * live); because minutes_played is derived from the sub log, the recompute
     * that follows updates both players' totals. Same guards as the other
     * write endpoints: refuse once FINALIZED, validate the minute range.
     */
    public static function route_substitution_update( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $finalized_err = self::assertEditable( $exec_id );
        if ( $finalized_err ) return $finalized_err;

        $event_uuid = (string) $r['event_uuid'];
        $body   = $r->get_json_params();
        $half   = (int) ( $body['half'] ?? 0 );
        $minute = (int) ( $body['minute'] ?? 0 );

        if ( $event_uuid === '' || $half < 1 || $half > 2 ) {
            return RestResponse::error( 'bad_input', __( 'Substitution update payload missing required fields.', 'talenttrack' ), 400 );
        }

        $repo = new MatchExecutionRepository();
        if ( ! $repo->substitutionExists( $event_uuid ) ) {
            return RestResponse::error( 'not_found', __( 'Substitution not found.', 'talenttrack' ), 404 );
        }

        [ $half_length ] = self::prepContext( absint( $r['activity_id'] ) );
        $minute_err = self::assertMinuteInRange( $minute, $half_length );
        if ( $minute_err ) return $minute_err;

        $repo->updateSubstitutionMinute( $event_uuid, $half, $minute );
        self::recomputeIfPendingReview( $repo, $exec_id );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'event_uuid' => $event_uuid, 'half' => $half, 'minute' => $minute ] );
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
     * #2271 — re-open a FINALIZED execution back to PENDING_REVIEW so the
     * coach can correct any datapoint (score, subs, goals, minutes) after
     * finalizing. Finalize stays an intentional lock, but never a dead-end.
     *
     * Cap-gated on the same `tt_edit_activities` capability as every other
     * write here (head-coach per #2222). Audit-logged via AuditService so
     * the re-open is traceable. Re-runs `recomputeAttendanceAndMinutes`
     * on the way back so the derived minutes stay consistent, and any
     * subsequent correction re-fires it too (recomputeIfPendingReview).
     *
     * Returns 409 when the execution is not FINALIZED (nothing to re-open).
     */
    public static function route_reopen( \WP_REST_Request $r ): \WP_REST_Response {
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
        if ( $current !== MatchExecutionState::FINALIZED ) {
            return RestResponse::error(
                'bad_state',
                __( 'Only a finalized match can be re-opened for corrections.', 'talenttrack' ),
                409
            );
        }

        $repo = new MatchExecutionRepository();
        if ( ! $repo->reopenForCorrections( $exec_id ) ) {
            return RestResponse::error( 'db_error', __( 'Could not re-open the match.', 'talenttrack' ), 500 );
        }

        // Re-derive minutes so a re-opened match starts from a consistent
        // baseline; every subsequent correction re-fires this too.
        $repo->recomputeAttendanceAndMinutes( $exec_id );

        ( new \TT\Infrastructure\Audit\AuditService() )->record(
            'match_execution.reopened',
            'match_execution',
            $exec_id,
            [ 'activity_id' => (int) $exec->activity_id ]
        );

        Logger::info( 'match_execution.reopen', [
            'execution_id' => $exec_id,
            'activity_id'  => (int) $exec->activity_id,
        ] );

        return RestResponse::success( [
            'execution_id' => $exec_id,
            'activity_id'  => (int) $exec->activity_id,
            'state'        => MatchExecutionState::PENDING_REVIEW,
        ] );
    }

    /**
     * #2268 — half length + first-half starting XI for the activity's
     * match prep, used to validate substitution rosters + minute ranges.
     * Returns [ half_length_minutes, list<int> starting_xi_half1 ]. A
     * missing prep yields the locked default half length + an empty XI.
     *
     * @return array{0:int, 1:list<int>}
     */
    private static function prepContext( int $activity_id ): array {
        $prep_repo = new MatchPrepRepository();
        $prep      = $prep_repo->findByActivity( $activity_id );
        if ( ! $prep ) {
            return [ 35, [] ];
        }
        $half_length = (int) $prep->half_length_minutes;
        if ( $half_length <= 0 ) $half_length = 35;

        $starting_xi = [];
        foreach ( $prep_repo->listLineup( (int) $prep->id ) as $l ) {
            if ( (int) $l->half === 1 ) {
                $pid = (int) $l->player_id;
                if ( $pid > 0 ) $starting_xi[] = $pid;
            }
        }
        return [ $half_length, $starting_xi ];
    }

    /**
     * #2268 — reject a minute outside [0, half_length + 10]. The +10 is
     * the locked stoppage allowance shared with the late-event form's
     * client hint. Returns null when in range, an HTTP 400 otherwise.
     */
    private static function assertMinuteInRange( int $minute, int $half_length ): ?\WP_REST_Response {
        $max = $half_length + 10;
        if ( $minute < 0 || $minute > $max ) {
            return RestResponse::error(
                'minute_out_of_range',
                sprintf(
                    /* translators: %d: highest accepted minute (half length + 10 stoppage) */
                    __( 'Minute must be between 0 and %d.', 'talenttrack' ),
                    $max
                ),
                400
            );
        }
        return null;
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
