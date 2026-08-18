<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

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
            return new \WP_REST_Response( [ 'error' => 'plan_id_and_activity_id_required' ], 400 );
        }

        if ( ! ( new TrainingPlansRepository() )->findById( $plan_id ) ) {
            return new \WP_REST_Response( [ 'error' => 'plan_not_found' ], 404 );
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
            return new \WP_REST_Response( [ 'error' => 'attach_failed' ], 500 );
        }

        // Re-attaching is idempotent rather than an error, but the caller
        // should know it got the existing run and not a fresh snapshot.
        return new \WP_REST_Response(
            [ 'run' => self::shapeRun( $repo, $run_id ) ],
            $existing ? 200 : 201
        );
    }

    public static function get_run( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) {
            return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }

        return new \WP_REST_Response( [ 'run' => self::shapeRun( $repo, $id ) ], 200 );
    }

    public static function update_run( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) {
            return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }

        $status = $r->get_param( 'status' );
        if ( $status === null ) {
            return new \WP_REST_Response( [ 'error' => 'status_required' ], 400 );
        }
        if ( ! in_array( (string) $status, TrainingPlanRunsRepository::STATUSES, true ) ) {
            return new \WP_REST_Response( [
                'error'   => 'invalid_status',
                'allowed' => TrainingPlanRunsRepository::STATUSES,
            ], 400 );
        }

        $repo->setStatus( $id, (string) $status );

        return new \WP_REST_Response( [ 'run' => self::shapeRun( $repo, $id ) ], 200 );
    }

    public static function update_block( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) {
            return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }

        $block_id = (int) $r['block'];
        $belongs  = false;
        foreach ( $repo->listBlocks( $id ) as $block ) {
            if ( (int) ( $block->id ?? 0 ) === $block_id ) { $belongs = true; break; }
        }
        if ( ! $belongs ) {
            return new \WP_REST_Response( [ 'error' => 'block_not_in_run' ], 404 );
        }

        $patch = [];
        foreach ( [ 'actual_duration_minutes', 'was_skipped', 'notes' ] as $key ) {
            if ( $r->get_param( $key ) !== null ) $patch[ $key ] = $r->get_param( $key );
        }

        $repo->updateBlock( $block_id, $patch );

        return new \WP_REST_Response( [ 'run' => self::shapeRun( $repo, $id ) ], 200 );
    }

    public static function detach( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) {
            return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }

        $repo->delete( $id );

        return new \WP_REST_Response( [ 'detached' => true, 'id' => $id ], 200 );
    }

    public static function for_activity( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlanRunsRepository();
        $run  = $repo->findForActivity( (int) $r['id'] );

        if ( ! $run ) {
            // Not an error — most activities have no plan attached.
            return new \WP_REST_Response( [ 'run' => null ], 200 );
        }

        return new \WP_REST_Response( [ 'run' => self::shapeRun( $repo, (int) ( $run->id ?? 0 ) ) ], 200 );
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
