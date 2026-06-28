<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctExercisesRepository;

/**
 * VctExercisesRestController — exercise catalogue CRUD.
 *
 * Two-layer permission:
 *   layer 1 — cap: `tt_vct_plan` (read), `tt_vct_admin_library` (write)
 *   layer 2 — scope: club-implicit via CurrentClub::id() inside the repo
 *
 * Search by age + category + intensity range + theme; consumed by the
 * library admin (VCT-11) + the wizard's preview rendering. Write
 * endpoints are deferred to the seed-driven catalogue in VCT-8 — the
 * spec's POST/PATCH/DELETE endpoints are stubbed here to return 501
 * Not Implemented until the catalogue editor lands.
 */
class VctExercisesRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vct/exercises', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'search' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( self::NS, '/vct/exercises/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'find' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        // #1784 — referential-integrity permanent delete (the DELETE above
        // only archives). Cascades coaching points; clears session-block links.
        register_rest_route( self::NS, '/vct/exercises/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_permanently' ],
                // #2024 security #6 — no purge path weaker than the bin: the
                // permanent delete additionally requires tt_manage_recycle_bin
                // on top of the VCT-library admin gate the other routes use.
                'permission_callback' => static function () {
                    return self::can_admin() && current_user_can( 'tt_manage_recycle_bin' );
                },
            ],
        ] );
    }

    /** #1784 — permanently delete a VCT exercise (irreversible, fail-closed). */
    public static function delete_permanently( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid exercise id.', 'talenttrack' ), 400 );
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'vct_exercise', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Exercise not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function can_read(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_vct_plan' );
    }

    public static function can_admin(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_vct_admin_library' );
    }

    public static function search( \WP_REST_Request $r ): \WP_REST_Response {
        $age      = (int)   ( $r->get_param( 'age' ) ?? 0 );
        $category = (string)( $r->get_param( 'category' ) ?? '' );
        $i_min    = (int)   ( $r->get_param( 'intensity_min' ) ?? 1 );
        $i_max    = (int)   ( $r->get_param( 'intensity_max' ) ?? 10 );
        $md       = (string)( $r->get_param( 'md_context' ) ?? 'NONE' );
        $theme    = $r->get_param( 'tactical_theme' );

        if ( $category === '' || $age <= 0 ) {
            return RestResponse::error( 'missing_params',
                __( 'age + category are required.', 'talenttrack' ), 400 );
        }
        $rows = ( new VctExercisesRepository() )->findCandidates(
            $category, $i_min, $i_max, $age, $md,
            is_string( $theme ) && $theme !== '' ? $theme : null
        );
        return RestResponse::success( [ 'exercises' => $rows ] );
    }

    public static function find( \WP_REST_Request $r ): \WP_REST_Response {
        $row = ( new VctExercisesRepository() )->find( (int) $r->get_param( 'id' ) );
        if ( $row === null ) return RestResponse::error( 'not_found', __( 'Exercise not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'exercise' => $row ] );
    }

    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $payload = self::extractWritePayload( $r );
        $err = self::validateCreatePayload( $payload );
        if ( $err !== null ) return $err;

        $id = ( new VctExercisesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            return RestResponse::error( 'db_error', __( 'The exercise could not be saved.', 'talenttrack' ), 500 );
        }
        $row = ( new VctExercisesRepository() )->find( $id );
        return RestResponse::success( [ 'exercise' => $row ] );
    }

    public static function patch( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r->get_param( 'id' );
        $existing = ( new VctExercisesRepository() )->find( $id );
        if ( $existing === null ) return RestResponse::error( 'not_found', __( 'Exercise not found.', 'talenttrack' ), 404 );

        $payload = self::extractWritePayload( $r );
        $ok = ( new VctExercisesRepository() )->update( $id, $payload );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'The exercise could not be updated.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'exercise' => ( new VctExercisesRepository() )->find( $id ) ] );
    }

    public static function archive( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r->get_param( 'id' );
        $existing = ( new VctExercisesRepository() )->find( $id );
        if ( $existing === null ) return RestResponse::error( 'not_found', __( 'Exercise not found.', 'talenttrack' ), 404 );

        $ok = ( new VctExercisesRepository() )->archive( $id );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'The exercise could not be archived.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id, 'archived' => true ] );
    }

    /**
     * Extract + sanitise the writable fields from a REST request.
     *
     * @return array<string,mixed>
     */
    private static function extractWritePayload( \WP_REST_Request $r ): array {
        $out = [];
        foreach ( [ 'code', 'name_canonical', 'category', 'tactical_theme', 'sided_size', 'verheijen_classification', 'diagram_url' ] as $k ) {
            $v = $r->get_param( $k );
            if ( $v !== null ) $out[ $k ] = sanitize_text_field( (string) $v );
        }
        foreach ( [ 'intensity_band', 'duration_minutes_min', 'duration_minutes_max', 'players_min', 'players_max', 'age_min', 'age_max' ] as $k ) {
            $v = $r->get_param( $k );
            if ( $v !== null && $v !== '' ) $out[ $k ] = (int) $v;
        }
        foreach ( [ 'md_minus_4', 'md_minus_3', 'md_minus_2', 'md_minus_1', 'md_zero', 'md_plus_1', 'md_plus_2', 'md_none' ] as $k ) {
            $v = $r->get_param( $k );
            if ( $v !== null ) $out[ $k ] = ! empty( $v ) ? 1 : 0;
        }
        $eq = $r->get_param( 'equipment_json' );
        if ( $eq !== null ) {
            $out['equipment_json'] = is_array( $eq ) ? $eq : (string) $eq;
        }
        return $out;
    }

    /** @param array<string,mixed> $p */
    private static function validateCreatePayload( array $p ): ?\WP_REST_Response {
        foreach ( [ 'code', 'name_canonical', 'category' ] as $required ) {
            if ( empty( $p[ $required ] ) ) {
                return RestResponse::error(
                    'missing_field',
                    sprintf(
                        /* translators: %s is the required field name. */
                        __( 'Field "%s" is required.', 'talenttrack' ),
                        $required
                    ),
                    400
                );
            }
        }
        if ( isset( $p['intensity_band'] ) && ( $p['intensity_band'] < 1 || $p['intensity_band'] > 10 ) ) {
            return RestResponse::error( 'bad_intensity', __( 'intensity_band must be 1-10.', 'talenttrack' ), 400 );
        }
        if ( isset( $p['age_min'], $p['age_max'] ) && $p['age_min'] > $p['age_max'] ) {
            return RestResponse::error( 'bad_age_range', __( 'age_min cannot exceed age_max.', 'talenttrack' ), 400 );
        }
        return null;
    }
}
