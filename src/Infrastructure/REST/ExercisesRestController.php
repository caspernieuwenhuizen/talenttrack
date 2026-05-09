<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
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

    public static function list_exercises( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new ExercisesRepository();
        $team_id = (int) ( $r->get_param( 'team_id' ) ?? 0 );
        $rows = $team_id > 0 ? $repo->listForTeam( $team_id ) : $repo->listActive();
        return RestResponse::success( [ 'items' => array_map( [ __CLASS__, 'serialize' ], $rows ) ] );
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
        $out = [];
        if ( isset( $body['name'] ) ) $out['name'] = (string) $body['name'];
        if ( isset( $body['description'] ) ) $out['description'] = (string) $body['description'];
        if ( isset( $body['duration_minutes'] ) ) $out['duration_minutes'] = (int) $body['duration_minutes'];
        if ( isset( $body['category_id'] ) ) $out['category_id'] = (int) $body['category_id'];
        if ( isset( $body['diagram_url'] ) ) $out['diagram_url'] = (string) $body['diagram_url'];
        if ( isset( $body['visibility'] ) ) $out['visibility'] = (string) $body['visibility'];
        return $out;
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
}
