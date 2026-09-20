<?php
namespace TT\Modules\MatchExecution\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Domain\MatchClock;
use TT\Modules\MatchExecution\Domain\MatchRegisterGap;
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
 *   POST   /<activity_id>/end-half       {half, at?: now|scheduled}  (#3667 — clamped to half length + 10)
 *   GET    /<activity_id>/clock          (#3667 — clock + overrun + started_by)
 *   POST   /<activity_id>/pause          {half}
 *   POST   /<activity_id>/resume         {half}  (#3553 — pause length measured server-side)
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

    /**
     * #3105 — `match_execution` is a Pro feature. Every route is wrapped;
     * `enforceWriteRest()` decides from the verb, so the read routes
     * (`event-feed`, `pitch-lineup`, `clock`) pass through and every tap that logs
     * something answers 402. An out-of-plan club can still read back the
     * matches it ran (#3017's third decision); it cannot run another.
     *
     * The feature key is a literal, not a constant, so
     * `FeatureMapGateCoverageTest` can find it.
     */
    private static function gate( callable $callback ): \Closure {
        return static function ( \WP_REST_Request $r ) use ( $callback ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceWriteRest( 'match_execution', $r );
            return $blocked ?? $callback( $r );
        };
    }

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
        foreach ( [ 'start-half', 'end-half', 'pause', 'resume', 'substitution', 'goal-event', 'finish', 'finalize', 'reopen' ] as $action ) {
            /** @var callable $handler — resolved from the action name above. */
            $handler = [ __CLASS__, 'route_' . str_replace( '-', '_', $action ) ];
            $route   = [
                'methods'             => 'POST',
                'callback'            => self::gate( $handler ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ];
            if ( $action === 'end-half' ) {
                $route['args'] = self::endHalfArgs();
            }
            register_rest_route( self::NS, $base . '/' . $action, [ $route ] );
        }

        // #2275 — PATCH corrects a logged goal's half + minute (ours or the
        // opponent's), mirroring the substitution PATCH.
        register_rest_route( self::NS, $base . '/goal-event/(?P<event_uuid>[a-f0-9-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'route_goal_event_delete' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => self::gate( [ __CLASS__, 'route_goal_event_update' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // #2269 — undo a logged substitution by its client event_uuid.
        // #2273 — PATCH corrects the half + minute of a logged sub (coach
        // forgot to log it on time); minutes recompute follows.
        register_rest_route( self::NS, $base . '/substitution/(?P<event_uuid>[a-f0-9-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'route_substitution_delete' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => self::gate( [ __CLASS__, 'route_substitution_update' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // #1713 — read-only feed + pitch lineup for the live surface
        // (vertical positional pitch + chronological "Live verloop").
        register_rest_route( self::NS, $base . '/event-feed', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'route_event_feed' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // #3667 — the clock as the server has it, with the overrun flag and
        // who started the match: what the live screen boots from.
        register_rest_route( self::NS, $base . '/clock', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'route_clock' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, $base . '/pitch-lineup', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'route_pitch_lineup' ] ),
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
                'callback'            => self::gate( [ __CLASS__, 'route_minutes_override' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        // Rebuild — tracked development-action events. One per coach tap of
        // the +/- counter on a prep-flagged player. Distinct from goals;
        // these do not affect the score. Append-only, soft-delete on undo.
        register_rest_route( self::NS, $base . '/tracked-event', [
            [
                'methods'             => 'POST',
                'callback'            => self::gate( [ __CLASS__, 'route_tracked_event' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, $base . '/tracked-event/(?P<event_uuid>[a-f0-9-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'route_tracked_event_delete' ] ),
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    /**
     * #3151 — the capability answers "does this user run matches?", which
     * every coach does club-wide. The activity's team answers "whose
     * matches?", which is the question every route on this controller was
     * actually asking. Both, through the one helper the match-day views
     * share (`ActivityTeamScope`).
     *
     * A refusal here is 403: the capability model said no. 402 is reserved
     * for the plan (#3104).
     */
    public static function can_edit( \WP_REST_Request $r ): bool {
        if ( ! current_user_can( 'tt_edit_activities' ) ) return false;
        return \TT\Modules\Authorization\ActivityTeamScope::coversActivity(
            get_current_user_id(),
            absint( $r['activity_id'] )
        );
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
     * GET /<activity_id>/clock — the match clock (#3553) plus the #3667
     * readout: `overrun`, `limit_seconds`, `started_at`, `started_by`.
     * `clock` is null until the match has an execution row.
     */
    public static function route_clock( \WP_REST_Request $r ): \WP_REST_Response {
        $activity_id = absint( $r['activity_id'] );
        if ( $activity_id <= 0 ) {
            return RestResponse::error( 'bad_activity', __( 'Invalid activity id.', 'talenttrack' ), 400 );
        }
        return RestResponse::success( [
            'activity_id' => $activity_id,
            'clock'       => self::clockFor( $r ),
        ] );
    }

    /**
     * GET /<activity_id>/pitch-lineup — the line-up laid out by position
     * for the vertical pitch: the first-half starting XI with every logged
     * substitution applied (#3554), plus the ids of everyone on the pitch.
     * Requires a Match Prep (#838 hard dependency); returns an empty layout
     * when none exists.
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
                'on_pitch'    => [],
            ] );
        }

        $prep_repo = new MatchPrepRepository();
        $lineup    = $prep_repo->listLineup( (int) $prep->id );

        $slots_by_half = [ 1 => [], 2 => [] ];
        $xi_half1      = [];
        $xi_half2      = [];
        foreach ( $lineup as $l ) {
            $half = (int) $l->half;
            if ( $half !== 1 && $half !== 2 ) {
                continue;
            }
            $slot = (int) $l->slot_number;
            $pid  = (int) $l->player_id;
            if ( $pid <= 0 ) continue;
            if ( $half === 1 ) $xi_half1[] = $pid; else $xi_half2[] = $pid;
            if ( $slot >= 1 && $slot <= 11 ) {
                $slots_by_half[ $half ][ $slot ] = $pid;
            }
        }
        $slot_to_player = $slots_by_half[1];

        // #3554 — the line-up as it stands now, not as it was at kickoff:
        // every logged substitution is applied, so a client redrawing the
        // pitch after a sub shows who is actually on it.
        // #3849 — "now" includes which half it is: a second-half line-up
        // takes the pitch at the interval, and only its own half's
        // substitutions act on it.
        $exec_repo = new MatchExecutionRepository();
        $exec      = $exec_repo->findByActivity( $activity_id );
        $on_pitch  = $xi_half1;
        if ( $exec ) {
            $exec_id        = (int) ( $exec->id ?? 0 );
            $half_reached   = MatchExecutionState::halfReached( (string) ( $exec->state ?? '' ) );
            $subs           = $exec_repo->listSubstitutions( $exec_id );
            $slot_to_player = PitchLayoutService::pitchAtHalf( $slots_by_half[1], $slots_by_half[2], $subs, $half_reached );
            $on_pitch       = $exec_repo->onPitchPlayerIds( $exec_id, $xi_half1, $xi_half2, $half_reached );
        }

        $player_meta = self::playerMeta( array_values( $slot_to_player ) );

        $slots = ( new PitchLayoutService() )->positionedXi(
            (int) ( $prep->formation_template_id ?? 0 ),
            $slot_to_player,
            $player_meta,
            ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->activityTeamId( $activity_id )
        );

        // Rebuild — tracked development-action map for the live surface:
        // prep-flagged players + their action label + the current
        // non-reversed tap count (server-persisted so counts survive
        // reconnect / reload).
        $tracked_map = [];
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
            // #3554 — every player on the pitch now, slotted or not.
            'on_pitch'    => $on_pitch,
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
            'state'           => $next_state,
            $col              => current_time( 'mysql', true ),
            // #3553 — a half starts with its clock running.
            'clock_paused_at' => null,
        ] );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'state' => $next_state, 'clock' => self::clockFor( $r ) ] );
    }

    /**
     * #3667 — the body of `end-half`. `at: scheduled` ends the half at
     * exactly its length: the recovery for a half somebody started and
     * left running.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function endHalfArgs(): array {
        return [
            'half' => [
                'description' => __( 'The half being ended: 1 or 2.', 'talenttrack' ),
                'type'        => 'integer',
                'enum'        => [ 1, 2 ],
            ],
            'at'   => [
                'description' => __( 'When the half ended. "now" (the default) is the moment of the request, never later than the half length plus 10 minutes. "scheduled" ends it at exactly the half length.', 'talenttrack' ),
                'type'        => 'string',
                'enum'        => [ 'now', 'scheduled' ],
                'default'     => 'now',
            ],
        ];
    }

    public static function route_end_half( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $half = (int) $r->get_json_params()['half'] ?? 1;
        if ( $half !== 1 && $half !== 2 ) return RestResponse::error( 'bad_half', __( 'Half must be 1 or 2.', 'talenttrack' ), 400 );
        $activity_id = absint( $r['activity_id'] );

        // #3553 — a half ended while paused: the paused stretch counts as
        // paused, so half time shows the clock where it actually stopped.
        self::closePause( $exec_id, $activity_id );

        $repo = new MatchExecutionRepository();
        $col  = $half === 1 ? 'first_half_ended_at' : 'second_half_ended_at';
        // #1033 — ending the second half lands in PENDING_REVIEW (was
        // FINISHED). The coach reviews goals / subs / score post-match
        // and then Finalize locks it.
        // #3667 — never later than the half length + stoppage, so a half
        // left running for hours does not freeze the clock at 593:55.
        $repo->update( $exec_id, [
            'state' => $half === 1 ? MatchExecutionState::HALF_TIME : MatchExecutionState::PENDING_REVIEW,
            $col    => self::endedAtFor( $activity_id, $half, (string) $r['at'] === 'scheduled' ),
        ] );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'clock' => self::clockFor( $r ) ] );
    }

    /**
     * #3667 — the UTC datetime to stamp as a half's end, clamped to the
     * half length + stoppage (or the half length exactly, `$scheduled`).
     * Falls back to now when the half never started. Call it after
     * `closePause()`, so an open pause is already folded into the total.
     */
    private static function endedAtFor( int $activity_id, int $half, bool $scheduled ): string {
        $exec = ( new MatchExecutionRepository() )->findByActivity( $activity_id );
        if ( ! $exec ) return current_time( 'mysql', true );
        [ $half_length ] = self::prepContext( $activity_id );
        $ended = MatchClock::endOfHalf( $exec, $half, $half_length, $scheduled );
        return $ended === null ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s', $ended );
    }

    /**
     * #3553 — the server keeps the clock. `pause` stamps `clock_paused_at`;
     * `resume` folds the gap into the running half's pause total and clears
     * it. A reload then comes back to the same clock, running or paused.
     * Idempotent: pausing a paused clock keeps the first stamp, resuming a
     * running one is a no-op, so an offline-queue replay cannot double-count.
     *
     * The client sends the clock it showed at the tap (`elapsed_seconds`),
     * and the pause is stamped at that match moment rather than at the
     * moment the request arrived — otherwise a slow network, or a pause
     * queued offline and replayed minutes later, would leave the clock
     * running on the server while it stood still on the touchline. It is a
     * position inside the half, not a wall-clock time, so the phone's clock
     * being off does not matter; it is clamped to "now" and to the half.
     */
    public static function route_pause( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;

        $repo = new MatchExecutionRepository();
        $exec = $repo->findByActivity( absint( $r['activity_id'] ) );
        if ( $exec
            && in_array( (string) ( $exec->state ?? '' ), [ MatchExecutionState::FIRST_HALF, MatchExecutionState::SECOND_HALF ], true )
            && MatchClock::toUnix( $exec->clock_paused_at ?? null ) === null
        ) {
            $now      = time();
            $paused   = $now;
            $body     = (array) $r->get_json_params();
            $reported = isset( $body['elapsed_seconds'] ) && is_numeric( $body['elapsed_seconds'] ) ? (int) $body['elapsed_seconds'] : null;
            $prefix   = (string) ( $exec->state ?? '' ) === MatchExecutionState::SECOND_HALF ? 'second_half' : 'first_half';
            $started  = MatchClock::toUnix( $exec->{$prefix . '_started_at'} ?? null );
            if ( $reported !== null && $reported >= 0 && $started !== null ) {
                $paused = min( $now, $started + (int) ( $exec->{$prefix . '_pause_seconds'} ?? 0 ) + $reported );
                $paused = max( $started, $paused );
            }
            $repo->update( $exec_id, [ 'clock_paused_at' => gmdate( 'Y-m-d H:i:s', $paused ) ] );
        }
        return RestResponse::success( [ 'execution_id' => $exec_id, 'clock' => self::clockFor( $r ) ] );
    }

    /**
     * #3553 — the pause length is measured here, from `clock_paused_at` up
     * to now. The client may send `pause_seconds`, how long the pause lasted
     * on its own screen; it can only shorten the measured gap, never lengthen
     * it, which takes the network's delay (or an offline queue's) out of the
     * figure without letting a client stretch a pause the server never saw.
     */
    public static function route_resume( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;
        $body     = (array) $r->get_json_params();
        $reported = isset( $body['pause_seconds'] ) && is_numeric( $body['pause_seconds'] ) ? max( 0, (int) $body['pause_seconds'] ) : null;
        self::closePause( $exec_id, absint( $r['activity_id'] ), $reported );
        return RestResponse::success( [ 'execution_id' => $exec_id, 'clock' => self::clockFor( $r ) ] );
    }

    /**
     * Fold an open pause into the current half's pause total and clear it.
     * No-op when the clock is not paused. `$reported_seconds`, when given,
     * caps the gap (see route_resume).
     */
    private static function closePause( int $exec_id, int $activity_id, ?int $reported_seconds = null ): void {
        $exec = ( new MatchExecutionRepository() )->findByActivity( $activity_id );
        if ( ! $exec ) return;
        $paused_at = MatchClock::toUnix( $exec->clock_paused_at ?? null );
        if ( $paused_at === null ) return;

        $gap = max( 0, time() - $paused_at );
        if ( $reported_seconds !== null ) {
            $gap = min( $gap, $reported_seconds );
        }
        $col = (string) ( $exec->state ?? '' ) === MatchExecutionState::SECOND_HALF ? 'second_half_pause_seconds' : 'first_half_pause_seconds';

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_match_execution
                SET {$col} = {$col} + %d, clock_paused_at = NULL
              WHERE id = %d AND club_id = %d",
            $gap, $exec_id, CurrentClub::id()
        ) );
    }

    /**
     * The clock as it stands after a write, for the response. #3667 adds
     * whether the half has overrun, the limit, when the running half began
     * and who started the match.
     *
     * @return array<string, mixed>|null
     */
    private static function clockFor( \WP_REST_Request $r ): ?array {
        $activity_id = absint( $r['activity_id'] );
        $exec        = ( new MatchExecutionRepository() )->findByActivity( $activity_id );
        if ( ! $exec ) return null;
        [ $half_length ] = self::prepContext( $activity_id );
        return MatchClock::readout( $exec, $half_length );
    }

    // #2857 — `POST /score` is gone. It wrote a scoreline directly onto the
    // execution row, which is the write this epic exists to remove: a number
    // with no event behind it, drifting away from the goal log beside it. The
    // score is now derived from the goal events (syncScoresFromGoals), so
    // there is nothing left for the endpoint to do that would not be a way to
    // reintroduce the drift. The stepper that called it is replaced by the
    // goal sheet; nothing else in the plugin ever called it.

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
            [ $half_length, $xi_half1, $xi_half2 ] = self::prepContext( absint( $r['activity_id'] ) );
            $minute_err = self::assertMinuteInRange( $minute, $half_length );
            if ( $minute_err ) return $minute_err;

            // #3849 — judged at the substitution's own half and minute, not
            // at the final whistle. A forgotten first-half swap added after
            // the second half is a question about the first half, and on a
            // match with a second-half line-up the whistle answer was wrong
            // in both directions: the player coming off was never "on" and
            // the player coming on always was.
            $on_pitch = $repo->onPitchPlayerIds( $exec_id, $xi_half1, $xi_half2, $half, $minute );
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
        // #2857 — the goal log is the scoreline, on both sides.
        $repo->syncScoresFromGoals( $exec_id );
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

        // #2857 — a correction cannot change which team a goal counts for, so
        // this is a no-op today. It is here so every goal-event write path
        // ends the same way: if a later slice ever lets a goal switch sides,
        // it will not also have to remember to re-derive the score.
        $repo->syncScoresFromGoals( $exec_id );
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
        // #2857 — undoing a goal is how a goal is removed, so the scoreline
        // has to follow it down.
        $repo->syncScoresFromGoals( $exec_id );
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

        // #3667 — the live screen sends `end-half` and `finish` together,
        // in either order. When the second half already has an end (the
        // end-half landed first, possibly at the scheduled length), keep
        // it; otherwise stamp now, clamped to the half length + stoppage
        // like every other end of a half.
        $was_live = (string) $exec->state === MatchExecutionState::SECOND_HALF;
        $ended_at = MatchClock::toUnix( $exec->second_half_ended_at ?? null ) !== null && ! $was_live
            ? (string) $exec->second_half_ended_at
            : null;
        if ( $ended_at === null ) {
            self::closePause( $exec_id, $activity_id );
            $ended_at = self::endedAtFor( $activity_id, 2, false );
        }

        // #1033 — End-match now lands in PENDING_REVIEW (was FINISHED).
        // Goals / subs / score remain editable post-match; the coach
        // taps Finalize (route_finalize below) when ready to lock.
        $repo->update( $exec_id, [
            'state'                => MatchExecutionState::PENDING_REVIEW,
            'second_half_ended_at' => $ended_at,
        ] );

        // 2. Flip the activity to completed.
        self::completeActivityForMatch( $activity_id, (int) $exec->home_score, (int) $exec->away_score );

        // 3. #1048 — recompute attendance + minutes from prep + sub
        // log. The inline write block lives on the repository now so
        // PENDING_REVIEW edits + finalize can re-fire it (see
        // `MatchExecutionRepository::recomputeAttendanceAndMinutes`).
        $outcome = $repo->recomputeAttendanceAndMinutes( $exec_id );

        Logger::info( 'match_execution.finish', [
            'activity_id'      => $activity_id,
            'execution_id'     => $exec_id,
            'home_score'       => (int) $exec->home_score,
            'away_score'       => (int) $exec->away_score,
            'attendance_rows'  => $outcome->rowsWritten(),
            'recompute_reason' => $outcome->reason(),
        ] );

        // 4. #3445 — the match genuinely finished, so it stays completed:
        // refusing the final whistle would strand a coach on a touchline
        // with a played match and nowhere to put it. What it must not do
        // is close quietly. `MatchRegisterGap` reads the register back
        // out of the database, so the payload and the notice the coach
        // lands on cannot disagree with what was actually written.
        $payload = [
            'execution_id'        => $exec_id,
            'activity_id'         => $activity_id,
            'state'               => MatchExecutionState::PENDING_REVIEW,
            'attendance_recorded' => $outcome->isRecorded(),
            'attendance_rows'     => $outcome->rowsWritten(),
        ];

        $gap = MatchRegisterGap::forActivity( $activity_id );
        if ( $gap ) {
            $payload['attendance_recorded'] = false;
            $payload['attendance_gap']      = $gap->toArray();
        }

        return RestResponse::success( $payload );
    }

    /**
     * #3861 — the activity a played match belongs to is completed, and
     * carries the match's score.
     *
     * Idempotent, and called from both ends of the post-match flow: the
     * final whistle writes it, and finalize asserts it again. Finalize used
     * to leave the activity alone, so an activity reopened for a correction
     * had no way back to `completed` — the final whistle is the only other
     * writer and it is unreachable once the match is over. Asserting it here
     * makes finalize the repair as well as the lock.
     */
    private static function completeActivityForMatch( int $activity_id, int $home_score, int $away_score ): void {
        if ( $activity_id <= 0 ) return;
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->update(
            "{$p}tt_activities",
            [
                'activity_status_key' => 'completed',
                'plan_state'          => 'completed',
                'home_score'          => $home_score,
                'away_score'          => $away_score,
            ],
            [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * #1033 — explicit "Finalize" transition. Moves a PENDING_REVIEW
     * execution to the terminal FINALIZED state. Read-only thereafter
     * (score, goal-event, substitution endpoints refuse writes once
     * the execution is FINALIZED — see `assertEditable()`).
     *
     * No-op (returns 409) if the execution is not yet in PENDING_REVIEW.
     * Attendance + minutes were already written on the End-match tap
     * (route_finish); finalize re-derives them, flips the state, and
     * (#3861) asserts the activity's completed status — including on an
     * already-finalized match, so re-finalizing repairs an activity that
     * drifted out of `completed` rather than reporting nothing to do.
     */
    public static function route_finalize( \WP_REST_Request $r ): \WP_REST_Response {
        [ $exec_id, $err ] = self::ensureExecution( $r );
        if ( $err ) return $err;

        global $wpdb;
        $p = $wpdb->prefix;
        $exec = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, state, activity_id, home_score, away_score FROM {$p}tt_match_execution WHERE id = %d AND club_id = %d",
            $exec_id, CurrentClub::id()
        ) );
        if ( ! $exec ) return RestResponse::error( 'not_found', __( 'Execution not found.', 'talenttrack' ), 404 );

        $current = (string) ( $exec->state ?? '' );
        if ( $current === MatchExecutionState::FINALIZED ) {
            self::completeActivityForMatch(
                (int) $exec->activity_id,
                (int) $exec->home_score,
                (int) $exec->away_score
            );
            return RestResponse::success( [
                'execution_id' => $exec_id,
                'activity_id'  => (int) $exec->activity_id,
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
        //
        // #3445 — the outcome is reported, not acted on: finalize only
        // locks a state the match already reached on the final whistle,
        // and that is where the gap was surfaced. Repeating the notice
        // here would tell the coach something the screen already says.
        $repo    = new MatchExecutionRepository();
        $outcome = $repo->recomputeAttendanceAndMinutes( $exec_id );

        $repo->update( $exec_id, [
            'state' => MatchExecutionState::FINALIZED,
        ] );

        // #3861 — the match is played and locked, so its activity says so.
        self::completeActivityForMatch(
            (int) $exec->activity_id,
            (int) $exec->home_score,
            (int) $exec->away_score
        );

        Logger::info( 'match_execution.finalize', [
            'execution_id'     => $exec_id,
            'activity_id'      => (int) $exec->activity_id,
            'attendance_rows'  => $outcome->rowsWritten(),
            'recompute_reason' => $outcome->reason(),
        ] );

        return RestResponse::success( [
            'execution_id'        => $exec_id,
            'activity_id'         => (int) $exec->activity_id,
            'state'               => MatchExecutionState::FINALIZED,
            'attendance_recorded' => $outcome->isRecorded(),
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
        //
        // #3445 — the outcome is deliberately not surfaced here. Re-open
        // hands the coach back the editing surface, which carries the
        // register-gap notice on render; a second message on the way in
        // would say the same thing twice.
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
     * #2268 — half length + starting XI per half for the activity's match
     * prep, used to validate substitution rosters + minute ranges. Returns
     * [ half_length_minutes, list<int> half 1, list<int> half 2 ]. A
     * missing prep yields the locked default half length + empty XIs.
     *
     * #3849 — the second half was read nowhere here, which is how the
     * roster check came to judge every substitution against the first-half
     * XI. Both halves are returned because both are needed to say who is
     * on the pitch at a given point.
     *
     * @return array{0:int, 1:list<int>, 2:list<int>}
     */
    private static function prepContext( int $activity_id ): array {
        $prep_repo = new MatchPrepRepository();
        $prep      = $prep_repo->findByActivity( $activity_id );
        if ( ! $prep ) {
            return [ 35, [], [] ];
        }
        $half_length = (int) $prep->half_length_minutes;
        if ( $half_length <= 0 ) $half_length = 35;

        $xi_half1 = [];
        $xi_half2 = [];
        foreach ( $prep_repo->listLineup( (int) $prep->id ) as $l ) {
            $pid  = (int) $l->player_id;
            $half = (int) $l->half;
            if ( $pid <= 0 ) continue;
            if ( $half === 1 ) $xi_half1[] = $pid;
            if ( $half === 2 ) $xi_half2[] = $pid;
        }
        return [ $half_length, $xi_half1, $xi_half2 ];
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
     *
     * #3445 — the outcome is not returned to the caller on purpose.
     * These are per-tap live writes (a goal, a sub, a score nudge); the
     * recompute is their side effect, not their subject, and the gap is
     * already logged by the repository and rendered by the view. The
     * one call site that does report it is the final whistle, where the
     * coach is deciding whether the match is done.
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
