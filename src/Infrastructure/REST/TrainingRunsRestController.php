<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Training\Repositories\TrainingObservationsRepository;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Modules\Training\Services\PlayerExposureAggregator;

/**
 * TrainingRunsRestController — /wp-json/talenttrack/v1/training/runs (#2496).
 *
 * A run is one execution of a plan against one activity. It is the
 * player-bearing half of the plan/run split, so the write surface here is
 * deliberately narrow: you can attach a plan, move the run through its
 * lifecycle, and record what actually happened per block. You cannot edit
 * the snapshot, because the snapshot is the only history a session has.
 *
 *   POST   /training/runs                       attach plan to activity
 *   GET    /training/runs/{id}                  run + snapshot + blocks
 *   PATCH  /training/runs/{id}                  status transition
 *   PATCH  /training/runs/{id}/blocks/{block}   actual duration / skip / note
 *   DELETE /training/runs/{id}                  detach (run + its blocks)
 *   GET    /activities/{id}/training-plan       the run on an activity, if any
 *
 * Responses use the standard `RestResponse` envelope.
 *
 * Cap gate: `tt_training_plan`, except the activity lookup which also
 * accepts `tt_view_activities` — the activity detail shows "this training
 * has a plan attached" to anyone who may see the activity at all.
 */
final class TrainingRunsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    private static function can(): bool {
        return current_user_can( 'tt_training_plan' );
    }

    private static function notFound(): \WP_REST_Response {
        return RestResponse::error( 'not_found', __( 'Training run not found.', 'talenttrack' ), 404 );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/training/runs', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'attach' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/runs/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_run' ],
                'permission_callback' => static fn() => self::can(),
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_run' ],
                'permission_callback' => static fn() => self::can(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'detach' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/runs/(?P<id>\d+)/blocks/(?P<block>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_block' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        // #2500 — observations recorded during a run. Same cap as the
        // run itself: a coach running the training is the person who
        // writes them, and the sideline view is where it happens.
        register_rest_route( self::NS, '/training/runs/(?P<id>\d+)/observations', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_observations' ],
                'permission_callback' => static fn() => self::can(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_observation' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/observations/(?P<observation>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_observation' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<id>\d+)/training-plan', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'for_activity' ],
                'permission_callback' => static fn() => current_user_can( 'tt_view_activities' ) || self::can(),
            ],
        ] );
    }

    public static function attach( \WP_REST_Request $r ): \WP_REST_Response {
        $plan_id     = (int) $r->get_param( 'plan_id' );
        $activity_id = (int) $r->get_param( 'activity_id' );
        if ( $plan_id <= 0 || $activity_id <= 0 ) {
            return RestResponse::error(
                'plan_id_and_activity_id_required',
                __( 'Both a plan and an activity are required.', 'talenttrack' ),
                400
            );
        }

        if ( ! ( new TrainingPlansRepository() )->findById( $plan_id ) ) {
            return RestResponse::error( 'plan_not_found', __( 'Training plan not found.', 'talenttrack' ), 404 );
        }

        $repo     = new TrainingPlanRunsRepository();
        $existing = $repo->findForActivity( $activity_id ) !== null;

        $run_id = $repo->attach(
            $plan_id,
            $activity_id,
            $r->get_param( 'team_id' ) !== null ? (int) $r->get_param( 'team_id' ) : null,
            (string) ( $r->get_param( 'run_date' ) ?? current_time( 'Y-m-d' ) )
        );
        if ( $run_id <= 0 ) {
            return RestResponse::error(
                'attach_failed',
                __( 'The plan could not be attached to this activity.', 'talenttrack' ),
                500
            );
        }

        // Re-attaching is idempotent rather than an error, but the caller
        // should know it got the existing run and not a fresh snapshot.
        return RestResponse::success(
            [ 'run' => self::shapeRun( $repo, $run_id ) ],
            $existing ? 200 : 201
        );
    }

    public static function get_run( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) return self::notFound();

        return RestResponse::success( [ 'run' => self::shapeRun( $repo, $id ) ] );
    }

    public static function update_run( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) return self::notFound();

        $status = $r->get_param( 'status' );
        if ( $status === null ) {
            return RestResponse::error( 'status_required', __( 'A status is required.', 'talenttrack' ), 400 );
        }
        if ( ! in_array( (string) $status, TrainingPlanRunsRepository::STATUSES, true ) ) {
            return RestResponse::error(
                'invalid_status',
                __( 'That is not a training-run status.', 'talenttrack' ),
                400,
                [ 'allowed' => TrainingPlanRunsRepository::STATUSES ]
            );
        }

        $repo->setStatus( $id, (string) $status );

        // #2500 (D17) — a coach who finishes a session and opens the
        // player file has to see the minutes there, not tomorrow. The
        // nightly rebuild still runs and still owns correctness; this is
        // the same aggregator narrowed to the players who were present,
        // recomputing them in full, so the two paths cannot disagree.
        if ( (string) $status === 'completed' ) {
            ( new PlayerExposureAggregator() )->rebuildForRun( $id );
        }

        return RestResponse::success( [ 'run' => self::shapeRun( $repo, $id ) ] );
    }

    /**
     * What has been noted during this run so far — the sideline view's
     * own list, so a coach can see what they have already written.
     */
    public static function list_observations( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) return self::notFound();

        return RestResponse::success( [
            'observations' => array_map(
                [ __CLASS__, 'shapeObservation' ],
                ( new TrainingObservationsRepository() )->listForRun( $id )
            ),
        ] );
    }

    /**
     * Record one observation about one player.
     *
     * A rating-free note is valid and is the common case on a wet
     * Tuesday; an observation carrying neither a rating nor a note is
     * not, and the repository refuses it — a blank entry on a child's
     * timeline is worse than no entry.
     */
    public static function create_observation( \WP_REST_Request $r ): \WP_REST_Response {
        $runs = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $runs->findById( $id ) ) return self::notFound();

        $player_id = (int) $r->get_param( 'player_id' );
        if ( $player_id <= 0 ) {
            return RestResponse::error(
                'player_id_required',
                __( 'An observation is about a player, so it needs one.', 'talenttrack' ),
                400
            );
        }

        $repo = new TrainingObservationsRepository();

        // #2552 — a replay from the offline queue must not become a
        // second observation. The client stamps `client_uuid` once, when
        // the coach saves; the repository returns the row that already
        // carries it. Whether this is a first save or a replay is
        // decided by asking, not by trusting the client's opinion.
        $client_uuid = (string) ( $r->get_param( 'client_uuid' ) ?? '' );
        $replayed    = $client_uuid !== '' && $repo->findByUuid( $client_uuid ) !== null;

        $observation_id = $repo->create( [
            'run_id'             => $id,
            'run_block_id'       => (int) $r->get_param( 'run_block_id' ) ?: null,
            'player_id'          => $player_id,
            'principle_id'       => (int) $r->get_param( 'principle_id' ) ?: null,
            'football_action_id' => (int) $r->get_param( 'football_action_id' ) ?: null,
            'rating'             => $r->get_param( 'rating' ),
            'note'               => $r->get_param( 'note' ),
            'client_uuid'        => $client_uuid,
        ] );

        if ( $observation_id <= 0 ) {
            return RestResponse::error(
                'empty_observation',
                __( 'An observation needs a rating, a note, or both. A rating outside the configured scale is refused rather than rounded.', 'talenttrack' ),
                400
            );
        }

        // 200 for a replay, 201 for a new row — the same distinction
        // `POST /training/runs` already makes when a plan is re-attached,
        // so a client can tell "this landed twice" from "this is new".
        return RestResponse::success(
            [ 'observation_id' => $observation_id, 'replayed' => $replayed ],
            $replayed ? 200 : 201
        );
    }

    public static function delete_observation( \WP_REST_Request $r ): \WP_REST_Response {
        $observation_id = (int) $r['observation'];

        $ok = ( new TrainingObservationsRepository() )->delete( $observation_id );
        if ( ! $ok ) {
            return RestResponse::error( 'not_found', __( 'That observation no longer exists.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( [ 'deleted' => true ] );
    }

    /** @return array<string,mixed> */
    private static function shapeObservation( object $o ): array {
        return [
            'id'           => (int) ( $o->id ?? 0 ),
            'uuid'         => (string) ( $o->uuid ?? '' ),
            'run_id'       => (int) ( $o->run_id ?? 0 ),
            'run_block_id' => isset( $o->run_block_id ) ? (int) $o->run_block_id : null,
            'player_id'    => (int) ( $o->player_id ?? 0 ),
            'principle_id' => isset( $o->principle_id ) ? (int) $o->principle_id : null,
            'rating'       => $o->rating === null ? null : (float) $o->rating,
            'note'         => $o->note ?? null,
            'created_at'   => (string) ( $o->created_at ?? '' ),
        ];
    }

    public static function update_block( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) return self::notFound();

        $block_id = (int) $r['block'];
        $belongs  = false;
        foreach ( $repo->listBlocks( $id ) as $block ) {
            if ( (int) ( $block->id ?? 0 ) === $block_id ) { $belongs = true; break; }
        }
        if ( ! $belongs ) {
            return RestResponse::error(
                'block_not_in_run',
                __( 'That block does not belong to this training run.', 'talenttrack' ),
                404
            );
        }

        $patch = [];
        foreach ( [ 'actual_duration_minutes', 'was_skipped', 'notes' ] as $key ) {
            if ( $r->get_param( $key ) !== null ) $patch[ $key ] = $r->get_param( $key );
        }

        $repo->updateBlock( $block_id, $patch );

        return RestResponse::success( [ 'run' => self::shapeRun( $repo, $id ) ] );
    }

    public static function detach( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) return self::notFound();

        $repo->delete( $id );

        return RestResponse::success( [ 'detached' => true, 'id' => $id ] );
    }

    public static function for_activity( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $run  = $repo->findForActivity( (int) $r['id'] );

        if ( ! $run ) {
            // Not an error — most activities have no plan attached.
            return RestResponse::success( [ 'run' => null ] );
        }

        return RestResponse::success( [ 'run' => self::shapeRun( $repo, (int) ( $run->id ?? 0 ) ) ] );
    }

    /**
     * @return array<string,mixed>
     */
    private static function shapeRun( TrainingPlanRunsRepository $repo, int $id ): array {
        $run = $repo->findById( $id );
        if ( ! $run ) return [];

        $blocks = [];
        foreach ( $repo->listBlocks( $id ) as $b ) {
            $blocks[] = [
                'id'                       => (int) ( $b->id ?? 0 ),
                'order_index'              => (int) ( $b->order_index ?? 0 ),
                'plan_block_id'            => isset( $b->plan_block_id ) ? (int) $b->plan_block_id : null,
                'planned_duration_minutes' => isset( $b->planned_duration_minutes ) ? (int) $b->planned_duration_minutes : null,
                'planned_block_type'       => $b->planned_block_type ?? null,
                'actual_duration_minutes'  => isset( $b->actual_duration_minutes ) ? (int) $b->actual_duration_minutes : null,
                'was_skipped'              => (bool) ( $b->was_skipped ?? false ),
                'notes'                    => $b->notes ?? null,
            ];
        }

        return [
            'id'           => (int) ( $run->id ?? 0 ),
            'uuid'         => (string) ( $run->uuid ?? '' ),
            'plan_id'      => (int) ( $run->plan_id ?? 0 ),
            'activity_id'  => (int) ( $run->activity_id ?? 0 ),
            'team_id'      => isset( $run->team_id ) ? (int) $run->team_id : null,
            'run_date'     => (string) ( $run->run_date ?? '' ),
            'status'       => (string) ( $run->status ?? 'planned' ),
            'started_at'   => $run->started_at ?? null,
            'completed_at' => $run->completed_at ?? null,
            // The immutable copy taken at attach time. Read-only by design:
            // it is the only history the session has.
            'snapshot'     => $repo->snapshot( $id ),
            'blocks'       => $blocks,
        ];
    }
}
