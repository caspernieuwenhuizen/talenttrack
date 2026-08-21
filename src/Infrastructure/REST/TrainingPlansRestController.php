<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExercisesRepository;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Modules\Training\Services\PlanCoverageService;
use TT\Modules\Training\Services\SquadSizeEstimator;
use TT\Modules\Training\Services\TrainingPlanComposer;
use TT\Shared\Frontend\Components\RecordLink;

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

        register_rest_route( self::NS, '/training/plans/generate', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'generate_plan' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/plans/suggest', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'suggest_inputs' ],
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

        register_rest_route( self::NS, '/training/plans/(?P<id>\d+)/coverage', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'plan_coverage' ],
                'permission_callback' => static fn() => self::can(),
            ],
        ] );

        register_rest_route( self::NS, '/training/plans/(?P<id>\d+)/exercise-options', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'exercise_options' ],
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

    /**
     * List plans.
     *
     * Speaks two vocabularies on purpose. A direct API caller passes
     * `team_id` / `is_template` / `limit` / `offset`; `FrontendListTable`
     * passes `page` / `per_page` / `search` / `orderby` / `order` and its
     * filters nested under `filter[...]`. Both land on the same repository
     * args, and the response uses the `rows` + `total` + `page` +
     * `per_page` shape the list-table hydrator reads.
     */
    public static function list_plans( \WP_REST_Request $r ): \WP_REST_Response {
        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        $args   = [];

        $team_id = $r->get_param( 'team_id' ) ?? ( $filter['team_id'] ?? null );
        if ( $team_id !== null && $team_id !== '' ) {
            $args['team_id'] = (int) $team_id;
        }

        $is_template = $r->get_param( 'is_template' ) ?? ( $filter['is_template'] ?? null );
        if ( $is_template !== null && $is_template !== '' ) {
            $args['is_template'] = (bool) $is_template;
        }

        $theme_key = $r->get_param( 'theme_key' ) ?? ( $filter['theme_key'] ?? null );
        if ( $theme_key !== null && $theme_key !== '' ) {
            $args['theme_key'] = (string) $theme_key;
        }

        // The list-table's record-state pill: active (default) / archived / all.
        $status = (string) ( $filter['status'] ?? 'active' );
        if ( $status === 'archived' ) {
            $args['archived_only'] = true;
        } elseif ( $status === 'all' ) {
            $args['include_archived'] = true;
        }
        if ( $r->get_param( 'include_archived' ) ) {
            $args['include_archived'] = true;
        }

        $search = (string) ( $r->get_param( 'search' ) ?? '' );
        if ( $search !== '' ) $args['search'] = $search;

        $orderby = (string) ( $r->get_param( 'orderby' ) ?? '' );
        if ( $orderby !== '' ) {
            $args['orderby'] = $orderby;
            $args['order']   = strtolower( (string) $r->get_param( 'order' ) ) === 'asc' ? 'asc' : 'desc';
        }

        $repo  = new TrainingPlansRepository();
        $total = $repo->countPlans( $args );

        $per_page = (int) ( $r->get_param( 'per_page' ) ?? $r->get_param( 'limit' ) ?? 25 );
        $per_page = max( 1, min( 200, $per_page ) );
        $page     = max( 1, (int) ( $r->get_param( 'page' ) ?? 1 ) );

        $args['limit']  = $per_page;
        $args['offset'] = $r->get_param( 'offset' ) !== null
            ? max( 0, (int) $r->get_param( 'offset' ) )
            : ( $page - 1 ) * $per_page;

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'shapeRow' ], $repo->listPlans( $args ) ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
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

    /**
     * Draft a plan (#2497).
     *
     * `preview=1` composes without saving — the wizard's proposal step
     * renders that, so a coach can regenerate or swap before anything is
     * written. Without it the draft is persisted and the new plan
     * returned.
     *
     * A blocking warning means the pipeline could not produce a session
     * respecting a hard rule (an unusable age profile, a missing
     * template, an intensity ceiling that cannot be met). Nothing is
     * persisted and the caller gets 400 with the structured reasons —
     * the same envelope `POST /vct/sessions/generate` uses, because it is
     * the same engine underneath.
     */
    public static function generate_plan( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r->get_param( 'team_id' );
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'team_id_required', __( 'Pick a team to plan for.', 'talenttrack' ), 400 );
        }

        $payload = [
            'team_id'                    => $team_id,
            'season_id'                  => (int) ( $r->get_param( 'season_id' ) ?? 0 ),
            'age_group'                  => (string) ( $r->get_param( 'age_group' ) ?? 'U13' ),
            'session_date'               => (string) ( $r->get_param( 'session_date' ) ?? '' ),
            'tactical_theme'             => $r->get_param( 'tactical_theme' ),
            'start_time'                 => $r->get_param( 'start_time' ),
            'requested_duration_minutes' => $r->get_param( 'requested_duration_minutes' ),
        ];

        // The wizard sends the squad the coach confirmed; without it the
        // composer falls back to the team's active roster (D14).
        $roster = $r->get_param( 'roster_player_ids' );
        if ( is_array( $roster ) ) $payload['roster_player_ids'] = $roster;

        $composer = new TrainingPlanComposer();

        if ( $r->get_param( 'preview' ) ) {
            $draft = $composer->preview( $payload );
            if ( $draft['blocked'] ) {
                return RestResponse::error(
                    'cannot_compose',
                    __( 'This training cannot be drafted as asked.', 'talenttrack' ),
                    400,
                    [ 'reasons' => $draft['warnings'] ]
                );
            }
            return RestResponse::success( [
                'blocks'   => $draft['blocks'],
                'warnings' => $draft['warnings'],
                'coverage' => $draft['coverage'],
            ] );
        }

        $result = $composer->generate( $payload );
        if ( $result['plan_id'] === null ) {
            return RestResponse::error(
                'cannot_compose',
                __( 'This training cannot be drafted as asked.', 'talenttrack' ),
                400,
                [ 'reasons' => $result['warnings'] ]
            );
        }

        $plans = new TrainingPlansRepository();
        $plan  = self::shapePlan( $plans->findById( (int) $result['plan_id'] ) );
        $plan['blocks'] = array_map(
            [ __CLASS__, 'shapeBlock' ],
            ( new TrainingPlanBlocksRepository() )->listForPlan( (int) $result['plan_id'] )
        );

        return RestResponse::success( [
            'plan'     => $plan,
            'warnings' => $result['warnings'],
            'coverage' => $result['coverage'],
        ], 201 );
    }

    /**
     * What the wizard prefills its fields with, so the first screen is
     * already answered rather than blank: the expected turnout derived
     * from recent attendance, and where that number came from.
     */
    public static function suggest_inputs( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r->get_param( 'team_id' );
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'team_id_required', __( 'Pick a team to plan for.', 'talenttrack' ), 400 );
        }

        $estimator = new SquadSizeEstimator();
        $suggest   = $estimator->suggest( $team_id );

        return RestResponse::success( [
            'squad_size'        => $suggest['value'],
            'squad_size_source' => $suggest['source'],
            'roster_player_ids' => $estimator->rosterFor( $team_id ),
        ] );
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

    /**
     * Who this plan works on, by name. The builder's side panel re-reads
     * this after every save, so a coach sees the consequence of a swap
     * immediately rather than at the end.
     */
    public static function plan_coverage( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( ! ( new TrainingPlansRepository() )->findById( $id ) ) return self::notFound();

        return RestResponse::success( [ 'coverage' => ( new PlanCoverageService() )->forPlan( $id ) ] );
    }

    /**
     * The picker's list, sorted by how many of this team's open goals
     * each drill would serve.
     *
     * The sort key travels with each row as `players_served`, because a
     * ranked list whose ranking the user cannot see is just an arbitrary
     * order they have to trust (#2498 acceptance: "the picker's sort is
     * open-goal coverage, and says so").
     */
    public static function exercise_options( \WP_REST_Request $r ): \WP_REST_Response {
        $plans = new TrainingPlansRepository();
        $id    = (int) $r['id'];
        $plan  = $plans->findById( $id );
        if ( ! $plan ) return self::notFound();

        $exercises = new ExercisesRepository();
        $rows      = $exercises->browse( [
            'search'         => (string) ( $r->get_param( 'search' ) ?? '' ),
            'tactical_theme' => (string) ( $r->get_param( 'tactical_theme' ) ?? '' ),
            'limit'          => 50,
        ] );

        $roster = ( new SquadSizeEstimator() )->rosterFor( (int) ( $plan->team_id ?? 0 ) );
        $served = ( new PlanCoverageService() )->playersServedByExercise(
            array_map( static fn( $row ): int => (int) $row->id, $rows ),
            $roster
        );

        $options = array_map(
            static function ( object $row ) use ( $served ): array {
                return [
                    'id'               => (int) $row->id,
                    'name'             => (string) ( $row->name ?? '' ),
                    'duration_minutes' => (int) ( $row->duration_minutes ?? 0 ),
                    'intensity_band'   => isset( $row->intensity_band ) ? (int) $row->intensity_band : null,
                    'players_min'      => isset( $row->players_min ) ? (int) $row->players_min : null,
                    'players_max'      => isset( $row->players_max ) ? (int) $row->players_max : null,
                    'tactical_theme'   => $row->tactical_theme ?? null,
                    'players_served'   => (int) ( $served[ (int) $row->id ] ?? 0 ),
                ];
            },
            $rows
        );

        // Most useful first, then the shortest — a coach filling a
        // fifteen-minute slot should not scroll past hour-long games.
        usort( $options, static function ( array $a, array $b ): int {
            if ( $a['players_served'] !== $b['players_served'] ) {
                return $b['players_served'] <=> $a['players_served'];
            }
            return $a['duration_minutes'] <=> $b['duration_minutes'];
        } );

        return RestResponse::success( [
            'options'     => $options,
            'roster_size' => count( $roster ),
        ] );
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
     * A list row. The plan shape plus the display-ready fields the list
     * table renders straight into cells, and the `detail_url` that makes
     * the whole row clickable.
     *
     * @return array<string,mixed>
     */
    private static function shapeRow( ?object $p ): array {
        $row = self::shapePlan( $p );
        if ( ! $row ) return [];

        $row['detail_url'] = add_query_arg(
            [ 'tt_view' => 'training-plan', 'id' => $row['id'] ],
            RecordLink::dashboardUrl()
        );

        /* translators: %d is a number of minutes. */
        $row['duration_label'] = sprintf(
            _n( '%d minute', '%d minutes', $row['total_duration_minutes'], 'talenttrack' ),
            $row['total_duration_minutes']
        );

        $row['kind_label'] = $row['is_template']
            ? __( 'Template', 'talenttrack' )
            : __( 'Plan', 'talenttrack' );

        // Only archived rows carry it, so the list table's `show_if` gate
        // can put Restore / Delete-permanently on exactly those rows.
        $row['archived_at'] = $row['archived'] ? ( $p->archived_at ?? null ) : null;

        return $row;
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
