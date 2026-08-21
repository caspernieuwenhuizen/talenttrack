<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Exercises\ExercisesRepository;

/**
 * ExercisesRestController — /wp-json/talenttrack/v1/exercises (#0016 Sprint 2b).
 *
 * REST surface on `tt_exercises`. Wraps `ExercisesRepository`'s
 * versioning + visibility model so future SaaS frontends + the
 * Sprint 4 photo-capture review wizard call into a stable REST
 * shape rather than direct PHP repository access.
 *
 *   GET    /exercises                    list active exercises
 *                                         (optional ?team_id=N applies
 *                                         the visibility rules via
 *                                         listForTeam())
 *   GET    /exercises/{id}               fetch a single row by id
 *   GET    /exercises/categories         list `tt_exercise_categories`
 *   POST   /exercises                    create a new exercise
 *   PUT    /exercises/{id}               edit (creates a new version
 *                                         per the pinning model)
 *   DELETE /exercises/{id}               archive (soft-delete)
 *
 * Cap gate: `tt_manage_exercises` for write paths;
 * `tt_view_activities` for read paths (coaches need to see the
 * library when planning sessions).
 */
final class ExercisesRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    /**
     * Who may decide what the whole club trains from (#2493 D9).
     *
     * NOT `tt_manage_exercises` at global scope: that cap is seeded
     * `rcd global` to both coach personas as well as the head of
     * development, so a global-scope check on it would let any coach
     * promote their own drill — which is the exact decision the queue
     * exists to route to someone else.
     *
     * `tt_edit_methodology` is the discriminator that fits both the
     * permission model and the meaning: it resolves to
     * `methodology:change`, held by the head of development and the
     * academy admin only, and the club-wide exercise library is part of
     * the academy's coaching framework.
     */
    private static function canPromote(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_edit_methodology' );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/exercises', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_exercises' ],
                'permission_callback' => static fn() => current_user_can( 'tt_view_activities' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_exercise' ],
                'permission_callback' => static fn() => current_user_can( 'tt_manage_exercises' ),
            ],
        ] );
        register_rest_route( self::NS, '/exercises/categories', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_categories' ],
                'permission_callback' => static fn() => current_user_can( 'tt_view_activities' ),
            ],
        ] );
        register_rest_route( self::NS, '/exercises/promotion-queue', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_promotion_queue' ],
                'permission_callback' => static fn() => self::canPromote(),
            ],
        ] );
        register_rest_route( self::NS, '/exercises/(?P<id>\d+)/promote', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'promote_exercise' ],
                'permission_callback' => static fn() => self::canPromote(),
            ],
        ] );
        register_rest_route( self::NS, '/exercises/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_exercise' ],
                'permission_callback' => static fn() => current_user_can( 'tt_view_activities' ),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_exercise' ],
                'permission_callback' => static fn() => current_user_can( 'tt_manage_exercises' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_exercise' ],
                'permission_callback' => static fn() => current_user_can( 'tt_manage_exercises' ),
            ],
        ] );
    }

    /**
     * List exercises.
     *
     * Three callers, three shapes, one route:
     *
     *   ?team_id=N        the picker — visibility rules applied, `items`
     *   ?browse=1         the library screen — search / filters / pager,
     *                     `rows` + `total` + `page` + `per_page`
     *   (neither)         everything active, `items`
     *
     * `items` is kept on the first and third because the picker and the
     * existing API consumers already read it; the library screen is a new
     * caller, so it gets the shape `FrontendListTable` actually reads
     * rather than an adapter in between.
     */
    public static function list_exercises( \WP_REST_Request $r ): \WP_REST_Response {
        $repo    = new ExercisesRepository();
        $team_id = (int) ( $r->get_param( 'team_id' ) ?? 0 );

        if ( ! $r->get_param( 'browse' ) ) {
            $rows = $team_id > 0 ? $repo->listForTeam( $team_id ) : $repo->listActive();
            return RestResponse::success( [ 'items' => array_map( [ __CLASS__, 'serialize' ], $rows ) ] );
        }

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        // #2625 — `filter[archived]` is canonical; `filter[status]` is a
        // deprecated alias kept for one release. The repository arg keeps its
        // own `status` name; only the request param is renamed.
        $args   = [ 'status' => (string) ( $filter['archived'] ?? ( $filter['status'] ?? 'active' ) ) ];

        foreach ( [ 'category_id', 'principle_id', 'intensity_band', 'players' ] as $key ) {
            $value = $r->get_param( $key ) ?? ( $filter[ $key ] ?? null );
            if ( $value !== null && $value !== '' ) $args[ $key ] = (int) $value;
        }
        foreach ( [ 'tactical_theme', 'visibility', 'source' ] as $key ) {
            $value = $r->get_param( $key ) ?? ( $filter[ $key ] ?? null );
            if ( $value !== null && $value !== '' ) $args[ $key ] = (string) $value;
        }

        $search = (string) ( $r->get_param( 'search' ) ?? '' );
        if ( $search !== '' ) $args['search'] = $search;

        $orderby = (string) ( $r->get_param( 'orderby' ) ?? '' );
        if ( $orderby !== '' ) {
            $args['orderby'] = $orderby;
            $args['order']   = strtolower( (string) $r->get_param( 'order' ) ) === 'desc' ? 'desc' : 'asc';
        }

        $total    = $repo->countBrowse( $args );
        $per_page = max( 1, min( 200, (int) ( $r->get_param( 'per_page' ) ?? 25 ) ) );
        $page     = max( 1, (int) ( $r->get_param( 'page' ) ?? 1 ) );

        $args['limit']  = $per_page;
        $args['offset'] = ( $page - 1 ) * $per_page;

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'serializeRow' ], $repo->browse( $args ) ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    /**
     * The head-of-development promotion queue (#2493 D9) — team-scoped
     * exercises awaiting a decision on whether the whole club gets them.
     */
    public static function list_promotion_queue( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new ExercisesRepository();

        return RestResponse::success( [
            'rows'  => array_map( [ __CLASS__, 'serializeRow' ], $repo->promotionQueue() ),
            'total' => $repo->countPromotionQueue(),
        ] );
    }

    /**
     * Make a team-scoped exercise club-wide.
     *
     * Gated on `tt_manage_exercises` at GLOBAL scope: a coach may author
     * for their own team, but deciding what the whole club trains from is
     * the head of development's call.
     */
    public static function promote_exercise( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid exercise id.', 'talenttrack' ), 400 );

        $repo = new ExercisesRepository();
        $row  = $repo->findById( $id );
        if ( ! $row ) return RestResponse::error( 'not_found', __( 'Exercise not found.', 'talenttrack' ), 404 );

        if ( (string) ( $row->visibility ?? '' ) !== 'team' ) {
            return RestResponse::error(
                'not_team_scoped',
                __( 'Only a team exercise can be made club-wide.', 'talenttrack' ),
                409
            );
        }

        if ( ! $repo->promote( $id ) ) {
            return RestResponse::error( 'promote_failed', __( 'The exercise could not be made club-wide.', 'talenttrack' ), 500 );
        }

        Logger::info( 'exercises.promoted', [
            'exercise_id' => $id,
            'club_id'     => CurrentClub::id(),
            'by_user'     => get_current_user_id(),
        ] );

        return RestResponse::success( [ 'promoted' => true, 'id' => $id ] );
    }

    public static function list_categories( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new ExercisesRepository();
        return RestResponse::success( [ 'items' => $repo->listCategories() ] );
    }

    public static function get_exercise( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new ExercisesRepository() )->findById( $id );
        if ( ! $row ) return RestResponse::error( 'not_found', __( 'Exercise not found.', 'talenttrack' ), 404 );
        return RestResponse::success( self::serialize( $row ) );
    }

    public static function create_exercise( \WP_REST_Request $r ): \WP_REST_Response {
        $payload = self::extractPayload( $r );
        if ( empty( $payload['name'] ) ) {
            return RestResponse::error( 'missing_fields', __( 'A name is required.', 'talenttrack' ), 400 );
        }
        // D9 — a new drill belongs to its author's team until someone who
        // curates the methodology says the whole club should have it. The
        // repository's own default predates that decision, so the choice
        // is made here rather than left to it.
        if ( ! isset( $payload['visibility'] ) ) {
            $payload['visibility'] = 'team';
        }
        if ( ! isset( $payload['author_user_id'] ) ) {
            $payload['author_user_id'] = get_current_user_id();
        }
        $id = ( new ExercisesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'rest.exercises.create.failed', [ 'club_id' => CurrentClub::id() ] );
            return RestResponse::error( 'db_error', __( 'The exercise could not be created.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update_exercise( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid exercise id.', 'talenttrack' ), 400 );
        $payload = self::extractPayload( $r );
        $new_id  = ( new ExercisesRepository() )->editAsNewVersion( $id, $payload );
        if ( $new_id <= 0 ) {
            return RestResponse::error( 'edit_failed', __( 'The exercise could not be updated.', 'talenttrack' ), 500 );
        }
        // Returns the NEW version's id; callers that want to keep
        // referencing the prior version (e.g. activities pinned to it)
        // ignore this and stick with the old id. New activities should
        // pick up the new id from `superseded_by_id` resolution.
        return RestResponse::success( [ 'id' => $new_id, 'previous_id' => $id ] );
    }

    public static function archive_exercise( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid exercise id.', 'talenttrack' ), 400 );
        $ok = ( new ExercisesRepository() )->archive( $id );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'The exercise could not be archived.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'archived' => true, 'id' => $id ] );
    }

    /**
     * @return array<string,mixed>
     */
    private static function extractPayload( \WP_REST_Request $r ): array {
        $body = $r->get_json_params();
        if ( ! is_array( $body ) ) $body = [];
        // The library form posts as form-encoded rather than JSON, so fall
        // back to the request params when there is no JSON body.
        if ( ! $body ) $body = $r->get_params();

        $out = [];
        if ( isset( $body['name'] ) ) $out['name'] = (string) $body['name'];
        if ( isset( $body['description'] ) ) $out['description'] = (string) $body['description'];
        if ( isset( $body['duration_minutes'] ) ) $out['duration_minutes'] = (int) $body['duration_minutes'];
        if ( isset( $body['category_id'] ) ) $out['category_id'] = (int) $body['category_id'];
        if ( isset( $body['diagram_url'] ) ) $out['diagram_url'] = (string) $body['diagram_url'];
        if ( isset( $body['visibility'] ) ) $out['visibility'] = self::validVisibility( (string) $body['visibility'] );

        // Merged VCT attributes (migration 0212). All optional — an
        // exercise without an age window and an intensity band simply
        // stays out of VCT session generation, which is correct rather
        // than merely convenient: it cannot be judged age-safe.
        foreach ( [ 'intensity_band', 'duration_minutes_min', 'duration_minutes_max',
                    'players_min', 'players_max', 'age_min', 'age_max' ] as $key ) {
            if ( isset( $body[ $key ] ) && $body[ $key ] !== '' ) $out[ $key ] = (int) $body[ $key ];
        }
        foreach ( [ 'code', 'tactical_theme', 'pitch_preset' ] as $key ) {
            if ( isset( $body[ $key ] ) ) {
                $value       = trim( (string) $body[ $key ] );
                $out[ $key ] = $value === '' ? null : $value;
            }
        }

        return $out;
    }

    /**
     * A coach authoring a new drill gets team visibility by default
     * (#2493 D9) — usable in their own plans straight away, and in the
     * head of development's queue for a club-wide decision. Only someone
     * who may curate the methodology can set 'club' directly.
     */
    private static function validVisibility( string $value ): string {
        if ( ! in_array( $value, [ 'club', 'team', 'private' ], true ) ) return 'team';
        if ( $value === 'club' && ! self::canPromote() ) return 'team';
        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    private static function serialize( object $row ): array {
        return [
            'id'               => (int) ( $row->id ?? 0 ),
            'uuid'             => (string) ( $row->uuid ?? '' ),
            'name'             => (string) ( $row->name ?? '' ),
            'description'      => (string) ( $row->description ?? '' ),
            'duration_minutes' => (int) ( $row->duration_minutes ?? 0 ),
            'category_id'      => $row->category_id ? (int) $row->category_id : null,
            'diagram_url'      => $row->diagram_url ? (string) $row->diagram_url : null,
            'visibility'       => (string) ( $row->visibility ?? 'club' ),
            'version'          => (int) ( $row->version ?? 1 ),
            'archived'         => $row->archived_at !== null,
        ];
    }

    /**
     * A library-list row: the serialized exercise plus the merged VCT
     * attributes and the display-ready fields the list table renders
     * straight into cells.
     *
     * @return array<string,mixed>
     */
    private static function serializeRow( object $row ): array {
        $out = self::serialize( $row );

        $out['code']           = $row->code ?? null;
        $out['category_key']   = $row->category_key ?? null;
        $out['tactical_theme'] = $row->tactical_theme ?? null;
        $out['intensity_band'] = isset( $row->intensity_band ) ? (int) $row->intensity_band : null;
        $out['players_min']    = isset( $row->players_min ) ? (int) $row->players_min : null;
        $out['players_max']    = isset( $row->players_max ) ? (int) $row->players_max : null;
        $out['source']         = (string) ( $row->source ?? 'club' );
        $out['archived_at']    = $row->archived_at ?? null;
        $out['used_in_plans']  = isset( $row->used_in_plans ) ? (int) $row->used_in_plans : null;

        $out['detail_url'] = add_query_arg(
            [ 'tt_view' => 'exercises', 'id' => $out['id'] ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );

        // Where the row came from, as one readable label. Shipped and
        // VCT-imported content is not club-authored and cannot be edited
        // in place, so the list has to say so rather than offering an
        // edit action that will not work.
        $out['origin_label'] = self::originLabel( $out['source'] );

        $out['players_label'] = ( $out['players_min'] !== null && $out['players_max'] !== null )
            ? sprintf(
                /* translators: 1: smallest group size, 2: largest group size. */
                __( '%1$d–%2$d players', 'talenttrack' ),
                $out['players_min'],
                $out['players_max']
            )
            : '';

        $out['visibility_label'] = self::visibilityLabel( $out['visibility'] );

        return $out;
    }

    private static function originLabel( string $source ): string {
        switch ( $source ) {
            case 'vct':     return __( 'From VCT', 'talenttrack' );
            case 'shipped': return __( 'Built in', 'talenttrack' );
        }
        return __( 'Club', 'talenttrack' );
    }

    private static function visibilityLabel( string $visibility ): string {
        switch ( $visibility ) {
            case 'team':    return __( 'One team', 'talenttrack' );
            case 'private': return __( 'Only me', 'talenttrack' );
        }
        return __( 'Whole club', 'talenttrack' );
    }
}
