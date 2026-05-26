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
                'callback'            => [ __CLASS__, 'notImplemented' ],
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
                'callback'            => [ __CLASS__, 'notImplemented' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'notImplemented' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
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

    public static function notImplemented(): \WP_REST_Response {
        return RestResponse::error(
            'not_implemented',
            __( 'Catalogue write endpoints land in a follow-up ship (VCT-8).', 'talenttrack' ),
            501
        );
    }
}
