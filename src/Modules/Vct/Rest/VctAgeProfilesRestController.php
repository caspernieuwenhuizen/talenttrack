<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Services\AgeProfileAdminService;

/**
 * VctAgeProfilesRestController — per-club workload envelope per age.
 *
 *   GET    /vct/age-profiles
 *   POST   /vct/age-profiles
 *   PATCH  /vct/age-profiles/{id}
 *   DELETE /vct/age-profiles/{id}
 *
 * Read: `tt_vct_plan` (coaches need to know the ceiling).
 * Write: `tt_vct_admin_config` (HoD/admin only) — these ceilings govern
 * how hard minors are worked, which is why they are not a general admin
 * capability.
 *
 * #2601 — POST and DELETE close the gap that made the generator
 * unusable outside U10-U14: five profiles shipped seeded and there was
 * no path, in the UI or the API, to add a sixth. Both delegate to
 * `AgeProfileAdminService` so the REST route and the configuration view
 * make the same decisions.
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
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( self::NS, '/vct/age-profiles/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'remove' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
    }

    public static function can_read(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_vct_plan' );
    }

    public static function can_admin(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_vct_admin_config' );
    }

    public static function listAll(): \WP_REST_Response {
        return RestResponse::success( [ 'profiles' => ( new VctAgeProfilesRepository() )->listAll() ] );
    }

    /**
     * Add a profile for an age group that has none (#2601).
     *
     * Nothing is defaulted: an academy adding U15 states its own
     * ceilings, because inventing load limits for children is exactly
     * what this endpoint must not do. The two that carry the age safety —
     * `session_minutes_max` and `intensity_band_max` — are required.
     */
    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $age_group = sanitize_text_field( (string) $r->get_param( 'age_group' ) );
        $minutes   = (int) $r->get_param( 'session_minutes_max' );
        $band      = (int) $r->get_param( 'intensity_band_max' );

        if ( $age_group === '' || $minutes <= 0 || $band <= 0 ) {
            return RestResponse::error(
                'bad_request',
                __( 'An age profile needs an age group, a maximum training length and an intensity ceiling.', 'talenttrack' ),
                400
            );
        }

        $result = ( new AgeProfileAdminService() )->create( [
            'age_group'                        => $age_group,
            'session_minutes_max'              => $minutes,
            'intensity_band_max'               => $band,
            'weekly_load_envelope'             => (int) $r->get_param( 'weekly_load_envelope' ),
            'md_logic_enabled'                 => (bool) $r->get_param( 'md_logic_enabled' ),
            'min_recovery_hours_between_high'  => (int) ( $r->get_param( 'min_recovery_hours_between_high' ) ?: 48 ),
            'growth_spurt_load_reduction_pct'  => (int) ( $r->get_param( 'growth_spurt_load_reduction_pct' ) ?: 20 ),
            'match_load_multiplier_per_minute' => (float) ( $r->get_param( 'match_load_multiplier_per_minute' ) ?: 7.0 ),
        ] );

        if ( $result['id'] <= 0 ) {
            return RestResponse::error( 'bad_request', $result['error'], 400 );
        }

        return RestResponse::success( [
            'id'               => $result['id'],
            'templates_copied' => $result['templates_copied'],
        ], 201 );
    }

    /** Remove a profile, unless a live team is still in that age group. */
    public static function remove( \WP_REST_Request $r ): \WP_REST_Response {
        $result = ( new AgeProfileAdminService() )->delete( (int) $r->get_param( 'id' ) );
        if ( ! $result['deleted'] ) {
            return RestResponse::error( 'conflict', $result['error'], 409 );
        }
        return RestResponse::success( [ 'deleted' => true ] );
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
