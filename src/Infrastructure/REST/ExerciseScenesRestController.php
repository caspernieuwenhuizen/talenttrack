<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExerciseScenesRepository;
use TT\Modules\Exercises\ExercisesRepository;

/**
 * ExerciseScenesRestController (#2501, epic #2493).
 *
 *   GET    /exercises/{id}/scenes
 *   POST   /exercises/{id}/scenes
 *   GET    /exercise-scenes/{id}
 *   PUT    /exercise-scenes/{id}
 *   DELETE /exercise-scenes/{id}
 *   POST   /exercise-scenes/{id}/primary
 *
 * The single-scene routes take `{id}`, not `{scene}`, because the body
 * field carrying the payload is called `scene` — and a URL placeholder
 * sharing a name with a body field is silently overwritten by it. A PUT
 * would 404 while the GET beside it worked, which is a confusing enough
 * failure to be worth naming here.
 *
 * ## The round-trip is the contract
 *
 * The acceptance criterion is that `scene_json` survives REST without
 * loss. It cannot be *unchanged* — the repository normalises, and that
 * is the point — so what "without loss" means here is: **what the editor
 * gets back is what the editor will draw**. Every write returns the
 * stored, normalised scene rather than an acknowledgement, so the canvas
 * can adopt the server's version instead of keeping its own hopeful copy
 * and drifting from it.
 *
 * That also makes the normalisation visible rather than surprising: drag
 * an actor off the pitch and it comes back clamped to the edge, in the
 * same request, where the coach can see it happen.
 *
 * ## Gating
 *
 * Authoring a scene is authoring the exercise, so it gates on the same
 * `tt_manage_exercises` the library form uses. Reading follows the
 * library's own read gate. No new capability: a scene is a field of an
 * exercise (#2501 D6), not a separate kind of record with its own
 * audience.
 */
final class ExerciseScenesRestController {

    private const NS = 'talenttrack/v1';

    /**
     * #3105 — `exercises` is a Pro feature. Every route on this
     * controller is wrapped; `enforceWriteRest()` decides from the verb,
     * so the reads pass through untouched and the writes answer 402.
     * #3017's third decision, made structural: what the club already has
     * stays readable, and it cannot add more.
     *
     * The feature key is a literal, not a constant, so
     * `FeatureMapGateCoverageTest` can find it.
     */
    private static function gate( callable $callback ): \Closure {
        return static function ( \WP_REST_Request $r ) use ( $callback ) {
            $blocked = \TT\Modules\License\LicenseGate::enforceWriteRest( 'exercises', $r );
            return $blocked ?? $callback( $r );
        };
    }

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    private static function canRead(): bool {
        return current_user_can( 'tt_view_activities' );
    }

    private static function canWrite(): bool {
        return current_user_can( 'tt_manage_exercises' );
    }

    private static function notFound(): \WP_REST_Response {
        return RestResponse::error( 'not_found', __( 'That scene no longer exists.', 'talenttrack' ), 404 );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/exercises/(?P<id>\d+)/scenes', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'list_scenes' ] ),
                'permission_callback' => static fn() => self::canRead(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => self::gate( [ __CLASS__, 'create_scene' ] ),
                'permission_callback' => static fn() => self::canWrite(),
                'args'                => self::createArgs(),
            ],
        ] );

        register_rest_route( self::NS, '/exercise-scenes/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => self::gate( [ __CLASS__, 'get_scene' ] ),
                'permission_callback' => static fn() => self::canRead(),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => self::gate( [ __CLASS__, 'update_scene' ] ),
                'permission_callback' => static fn() => self::canWrite(),
                'args'                => self::updateArgs(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => self::gate( [ __CLASS__, 'delete_scene' ] ),
                'permission_callback' => static fn() => self::canWrite(),
            ],
        ] );

        register_rest_route( self::NS, '/exercise-scenes/(?P<id>\d+)/primary', [
            [
                'methods'             => 'POST',
                'callback'            => self::gate( [ __CLASS__, 'set_primary' ] ),
                'permission_callback' => static fn() => self::canWrite(),
                'args'                => self::idArgs(),
            ],
        ] );
    }

    // Body contracts (#3819) -------------------------------------------

    /**
     * `POST /exercises/{id}/scenes`. Nothing is declared `required`: core
     * checks required params before the permission callback, so a required
     * key would answer an unauthorised POST with a 400 rather than the 403
     * it is owed.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function createArgs(): array {
        return [
            'id'           => [ 'type' => [ 'integer', 'string' ], 'description' => 'The exercise, from the URL. A copy in the body is accepted and ignored.' ],
            'name'         => [ 'type' => 'string', 'description' => 'What the scene is called.' ],
            'pitch_preset' => [ 'type' => 'string', 'description' => 'Which pitch the scene is drawn on.' ],
            'duration_ms'  => [ 'type' => [ 'integer', 'string' ], 'description' => 'How long the animation runs, in milliseconds.' ],
            'scene'        => [ 'type' => [ 'object', 'array' ], 'description' => 'The scene itself: the players, the ball and their movement.' ],
        ];
    }

    /**
     * `PUT /exercise-scenes/{id}`. Every field is optional and an omitted
     * one is left alone (CLAUDE.md §6), which is what lets the editor save
     * a rename without re-sending the whole drawing.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function updateArgs(): array {
        $args = self::createArgs();
        $args['id']['description'] = 'The scene, from the URL. A copy in the body is accepted and ignored.';
        return $args + [
            'sort_order' => [ 'type' => [ 'integer', 'string' ], 'description' => 'Where the scene sits among the exercise\'s scenes.' ],
        ];
    }

    /**
     * `POST /exercise-scenes/{id}/primary` acts on the scene in the URL
     * and takes no body of its own.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function idArgs(): array {
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The scene, from the URL. A copy in the body is accepted and ignored.',
        ] ];
    }

    public static function list_scenes( \WP_REST_Request $r ): \WP_REST_Response {
        $exercise_id = (int) $r['id'];
        if ( ! ( new ExercisesRepository() )->findById( $exercise_id ) ) {
            return RestResponse::error( 'not_found', __( 'That exercise no longer exists.', 'talenttrack' ), 404 );
        }

        $repo = new ExerciseScenesRepository();

        return RestResponse::success( [
            'scenes' => array_map(
                static fn( object $row ): array => self::shape( $row ),
                $repo->listForExercise( $exercise_id )
            ),
        ] );
    }

    public static function create_scene( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::createArgs() );
        if ( $refused !== null ) return $refused;

        $exercise_id = (int) $r['id'];
        if ( ! ( new ExercisesRepository() )->findById( $exercise_id ) ) {
            return RestResponse::error( 'not_found', __( 'That exercise no longer exists.', 'talenttrack' ), 404 );
        }

        $repo = new ExerciseScenesRepository();
        $id   = $repo->create( [
            'exercise_id'  => $exercise_id,
            'name'         => $r->get_param( 'name' ),
            'pitch_preset' => $r->get_param( 'pitch_preset' ),
            'duration_ms'  => $r->get_param( 'duration_ms' ),
            'scene'        => (array) $r->get_param( 'scene' ),
        ] );

        if ( $id <= 0 ) {
            return RestResponse::error( 'create_failed', __( 'The scene could not be saved.', 'talenttrack' ), 500 );
        }

        $row = $repo->findById( $id );

        return RestResponse::success( [ 'scene' => self::shape( $row ) ], 201 );
    }

    public static function get_scene( \WP_REST_Request $r ): \WP_REST_Response {
        $row = ( new ExerciseScenesRepository() )->findById( (int) $r['id'] );

        return $row ? RestResponse::success( [ 'scene' => self::shape( $row ) ] ) : self::notFound();
    }

    /**
     * Save the canvas.
     *
     * Returns the STORED scene, not the submitted one. The editor adopts
     * what comes back, so a coordinate the repository clamped shows up on
     * the canvas immediately rather than living on as a client-side
     * fiction until the next reload.
     */
    public static function update_scene( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::updateArgs() );
        if ( $refused !== null ) return $refused;

        $repo     = new ExerciseScenesRepository();
        $scene_id = (int) $r['id'];
        if ( ! $repo->findById( $scene_id ) ) return self::notFound();

        $patch = [];
        foreach ( [ 'name', 'pitch_preset', 'duration_ms', 'sort_order' ] as $key ) {
            if ( $r->get_param( $key ) !== null ) $patch[ $key ] = $r->get_param( $key );
        }
        if ( $r->get_param( 'scene' ) !== null ) {
            $patch['scene'] = (array) $r->get_param( 'scene' );
        }

        if ( ! $repo->update( $scene_id, $patch ) ) {
            return RestResponse::error( 'update_failed', __( 'The scene could not be saved.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [ 'scene' => self::shape( $repo->findById( $scene_id ) ) ] );
    }

    public static function delete_scene( \WP_REST_Request $r ): \WP_REST_Response {
        $repo = new ExerciseScenesRepository();

        return $repo->delete( (int) $r['id'] )
            ? RestResponse::success( [ 'deleted' => true ] )
            : self::notFound();
    }

    public static function set_primary( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::idArgs() );
        if ( $refused !== null ) return $refused;

        $repo = new ExerciseScenesRepository();

        if ( ! $repo->setPrimary( (int) $r['id'] ) ) return self::notFound();

        return RestResponse::success( [ 'scene' => self::shape( $repo->findById( (int) $r['id'] ) ) ] );
    }

    /**
     * The wire shape. `scene` is the decoded, normalised payload — the
     * same structure the renderer reads, so a consumer never has to
     * parse `scene_json` itself.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $row ): array {
        return [
            'id'           => (int) $row->id,
            'uuid'         => (string) $row->uuid,
            'exercise_id'  => (int) $row->exercise_id,
            'name'         => $row->name,
            'pitch_preset' => (string) $row->pitch_preset,
            'duration_ms'  => (int) $row->duration_ms,
            'sort_order'   => (int) $row->sort_order,
            'is_primary'   => (bool) $row->is_primary,
            'scene'        => ( new ExerciseScenesRepository() )->decode( $row ),
        ];
    }
}
