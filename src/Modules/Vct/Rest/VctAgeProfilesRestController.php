<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;

/**
 * VctAgeProfilesRestController — per-club workload envelope per age.
 *
 *   GET   /vct/age-profiles
 *   PATCH /vct/age-profiles/{id}
 *
 * Read: `tt_vct_plan` (coaches need to know the ceiling).
 * Write: `tt_vct_admin_library` (HoD/admin only).
 */
class VctAgeProfilesRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vct/age-profiles', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'listAll' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
        ] );

        register_rest_route( self::NS, '/vct/age-profiles/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch' ],
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

    public static function listAll(): \WP_REST_Response {
        return RestResponse::success( [ 'profiles' => ( new VctAgeProfilesRepository() )->listAll() ] );
    }

    public static function patch( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r->get_param( 'id' );
        $patch = [];
        foreach ( [
            'session_minutes_max', 'intensity_band_max', 'md_logic_enabled',
            'min_recovery_hours_between_high', 'growth_spurt_load_reduction_pct',
            'weekly_load_envelope',
        ] as $key ) {
            $val = $r->get_param( $key );
            if ( $val !== null && $val !== '' ) $patch[ $key ] = (int) $val;
        }
        $mult = $r->get_param( 'match_load_multiplier_per_minute' );
        if ( $mult !== null && $mult !== '' ) $patch['match_load_multiplier_per_minute'] = (float) $mult;

        if ( ! $patch ) return RestResponse::success( [ 'changed' => false ] );

        $ok = ( new VctAgeProfilesRepository() )->update( $id, $patch );
        if ( ! $ok ) return RestResponse::error( 'db_error', __( 'The age profile could not be saved.', 'talenttrack' ), 500 );
        return RestResponse::success( [ 'changed' => true ] );
    }
}
