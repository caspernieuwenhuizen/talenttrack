<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * TrainingPlansRestController — /wp-json/talenttrack/v1/training/plans (#2496).
 *
 * The REST surface is the contract; the rendered PHP views that follow in
 * later waves are one consumer of it, not the source of truth (CLAUDE.md
 * §4). Everything a plan can do is reachable here before any screen exists.
 *
 *   GET    /training/plans                 list (team_id / is_template /
 *                                          theme_key / include_archived)
 *   POST   /training/plans                 create
 *   GET    /training/plans/{id}            fetch, with blocks + principles
 *   PATCH  /training/plans/{id}            partial update
 *   DELETE /training/plans/{id}            archive (soft-delete)
 *   POST   /training/plans/{id}/duplicate  copy, optionally as a template
 *   GET    /training/plans/{id}/blocks     ordered blocks
 *   PUT    /training/plans/{id}/blocks     bulk replace — the builder's and
 *                                          the generator's commit target
 *
 * Responses use the standard `RestResponse` envelope. The list returns
 * `items` + `total` specifically so `FrontendListTable` can consume it
 * without an adapter — its hydrator reads `data.items` and `data.total`.
 *
 * Cap gate: `tt_training_plan` throughout. Reads and writes share it
 * because a plan carries no player data — a user who may see a team's
 * plans may edit them; the interesting boundary is the team scope, which
 * the matrix resolves.
 */
final class TrainingPlansRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    private static function can(): bool {
        return current_user_can( 'tt_training_plan' );
    }

    private static function notFound(): \WP_REST_Response {
        return RestResponse::error( 'not_found', __( 'Training plan not found.', 'talenttrack' ), 404 );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/training/plans', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_plans' ],
                'permission_callback' => static fn() => self::can(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_plan' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/plans/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_plan' ],
                'permission_callback' => static fn() => self::can(),
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_plan' ],
                'permission_callback' => static fn() => self::can(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_plan' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/plans/(?P<id>\d+)/duplicate', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'duplicate_plan' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/plans/(?P<id>\d+)/blocks', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_blocks' ],
                'permission_callback' => static fn() => self::can(),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'replace_blocks' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );
    }

    public static function list_plans( \WP_REST_Request $r ): \WP_REST_Response {
        $args = [];
        if ( $r->get_param( 'team_id' ) !== null )     $args['team_id']          = (int) $r->get_param( 'team_id' );
        if ( $r->get_param( 'is_template' ) !== null ) $args['is_template']      = (bool) $r->get_param( 'is_template' );
        if ( $r->get_param( 'theme_key' ) !== null )   $args['theme_key']        = (string) $r->get_param( 'theme_key' );
        if ( $r->get_param( 'include_archived' ) )     $args['include_archived'] = true;

        $repo  = new TrainingPlansRepository();
        $total = $repo->countPlans( $args );

        if ( $r->get_param( 'limit' ) !== null )  $args['limit']  = (int) $r->get_param( 'limit' );
        if ( $r->get_param( 'offset' ) !== null ) $args['offset'] = (int) $r->get_param( 'offset' );

        return RestResponse::success( [
            'items' => array_map( [ __CLASS__, 'shapePlan' ], $repo->listPlans( $args ) ),
            'total' => $total,
        ] );
    }

    public static function create_plan( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlansRepository();

        $payload = self::planPayload( $r );
        if ( trim( (string) ( $payload['title'] ?? '' ) ) === '' ) {
            return RestResponse::error( 'title_required', __( 'A training plan needs a title.', 'talenttrack' ), 400 );
        }
        $payload['author_user_id'] = get_current_user_id() ?: null;

        $id = $repo->create( $payload );
        if ( $id <= 0 ) {
            return RestResponse::error( 'create_failed', __( 'The training plan could not be created.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [ 'plan' => self::shapePlan( $repo->findById( $id ) ) ], 201 );
    }

    public static function get_plan( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlansRepository();
        $plan = $repo->findById( (int) $r['id'] );
        if ( ! $plan ) return self::notFound();

        $plan_id           = (int) ( $plan->id ?? 0 );
        $out               = self::shapePlan( $plan );
        $out['blocks']     = array_map(
            [ __CLASS__, 'shapeBlock' ],
            ( new TrainingPlanBlocksRepository() )->listForPlan( $plan_id )
        );
        $out['principles'] = $repo->listPrincipleIds( $plan_id );

        return RestResponse::success( [ 'plan' => $out ] );
    }

    public static function update_plan( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlansRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) return self::notFound();

        $repo->update( $id, self::planPayload( $r, true ) );

        return RestResponse::success( [ 'plan' => self::shapePlan( $repo->findById( $id ) ) ] );
    }

    public static function archive_plan( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlansRepository();
        $id   = (int) $r['id'];
        if ( ! $repo->findById( $id ) ) return self::notFound();

        // Soft-delete only. The plan's runs are deliberately untouched —
        // a plan going away must not take a session that happened with it.
        $repo->archive( $id );

        return RestResponse::success( [ 'archived' => true, 'id' => $id ] );
    }

    public static function duplicate_plan( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new TrainingPlansRepository();

        $new_id = $repo->duplicate(
            (int) $r['id'],
            $r->get_param( 'title' ) !== null ? (string) $r->get_param( 'title' ) : null,
            $r->get_param( 'team_id' ) !== null ? (int) $r->get_param( 'team_id' ) : null,
            (bool) $r->get_param( 'as_template' )
        );
        if ( $new_id <= 0 ) return self::notFound();

        return RestResponse::success( [ 'plan' => self::shapePlan( $repo->findById( $new_id ) ) ], 201 );
    }

    public static function list_blocks( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( ! ( new TrainingPlansRepository() )->findById( $id ) ) return self::notFound();

        return RestResponse::success( [
            'blocks' => array_map(
                [ __CLASS__, 'shapeBlock' ],
                ( new TrainingPlanBlocksRepository() )->listForPlan( $id )
            ),
        ] );
    }

    /**
     * Bulk replace. The caller hands over the whole desired block list
     * rather than a diff, which is what makes the builder's save and the
     * generator's output the same operation.
     */
    public static function replace_blocks( \WP_REST_Request $r ): \WP_REST_Response {
        $plans = new TrainingPlansRepository();
        $id    = (int) $r['id'];
        if ( ! $plans->findById( $id ) ) return self::notFound();

        $blocks = $r->get_param( 'blocks' );
        if ( ! is_array( $blocks ) ) {
            return RestResponse::error( 'blocks_required', __( 'Send a blocks array, even an empty one.', 'talenttrack' ), 400 );
        }

        $repo = new TrainingPlanBlocksRepository();
        if ( ! $repo->replaceAll( $id, $blocks ) ) {
            return RestResponse::error( 'replace_failed', __( 'The blocks could not be saved.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [
            'blocks' => array_map( [ __CLASS__, 'shapeBlock' ], $repo->listForPlan( $id ) ),
            'plan'   => self::shapePlan( $plans->findById( $id ) ),
        ] );
    }

    /**
     * @return array<string,mixed>
     */
    private static function planPayload( \WP_REST_Request $r, bool $partial = false ): array {
        $keys = [
            'title', 'notes', 'team_id', 'age_group_key', 'season_id', 'theme_key',
            'intensity_target', 'is_template', 'visibility', 'source',
        ];

        $out = [];
        foreach ( $keys as $key ) {
            if ( $r->get_param( $key ) !== null || ( ! $partial && $key === 'title' ) ) {
                $out[ $key ] = $r->get_param( $key );
            }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function shapePlan( ?object $p ): array {
        if ( ! $p ) return [];
        return [
            'id'                     => (int) ( $p->id ?? 0 ),
            'uuid'                   => (string) ( $p->uuid ?? '' ),
            'title'                  => (string) ( $p->title ?? '' ),
            'notes'                  => isset( $p->notes ) ? (string) $p->notes : null,
            'team_id'                => isset( $p->team_id ) ? (int) $p->team_id : null,
            'age_group_key'          => $p->age_group_key ?? null,
            'season_id'              => isset( $p->season_id ) ? (int) $p->season_id : null,
            'theme_key'              => $p->theme_key ?? null,
            'total_duration_minutes' => (int) ( $p->total_duration_minutes ?? 0 ),
            'intensity_target'       => isset( $p->intensity_target ) ? (int) $p->intensity_target : null,
            'is_template'            => (bool) ( $p->is_template ?? false ),
            'visibility'             => (string) ( $p->visibility ?? 'club' ),
            'source'                 => (string) ( $p->source ?? 'manual' ),
            'author_user_id'         => isset( $p->author_user_id ) ? (int) $p->author_user_id : null,
            'archived'               => isset( $p->archived_at ),
            'created_at'             => (string) ( $p->created_at ?? '' ),
            'updated_at'             => (string) ( $p->updated_at ?? '' ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function shapeBlock( object $b ): array {
        return [
            'id'               => (int) ( $b->id ?? 0 ),
            'uuid'             => (string) ( $b->uuid ?? '' ),
            'order_index'      => (int) ( $b->order_index ?? 0 ),
            'block_type'       => (string) ( $b->block_type ?? 'main' ),
            'exercise_id'      => isset( $b->exercise_id ) ? (int) $b->exercise_id : null,
            'exercise_name'    => $b->exercise_name ?? null,
            'diagram_url'      => $b->exercise_diagram_url ?? null,
            'title_override'   => $b->title_override ?? null,
            'organisation'     => $b->organisation ?? null,
            'coaching_points'  => $b->coaching_points ?? null,
            'duration_minutes' => (int) ( $b->duration_minutes ?? 0 ),
            'intensity_band'   => isset( $b->intensity_band ) ? (int) $b->intensity_band : null,
            'players_min'      => isset( $b->players_min ) ? (int) $b->players_min : null,
            'players_max'      => isset( $b->players_max ) ? (int) $b->players_max : null,
        ];
    }
}
