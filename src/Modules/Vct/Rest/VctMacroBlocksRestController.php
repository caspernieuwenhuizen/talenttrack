<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;

/**
 * VctMacroBlocksRestController — periodization calendar.
 *
 *   GET /vct/macro-blocks?season_id=N&team_id=M
 *
 * Caps: `tt_vct_admin_library` (HoD/admin only). Coaches consume the
 * macro-block read indirectly via ProgressionRule; they don't need
 * direct REST access in MVP.
 *
 * `PUT /vct/macro-blocks` ships in VCT-11 (configuration tile UI).
 */
class VctMacroBlocksRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vct/macro-blocks', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'listForSeason' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
    }

    public static function can_admin(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_vct_admin_library' );
    }

    public static function listForSeason( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = (int) ( $r->get_param( 'season_id' ) ?? 0 );
        $team_id   = (int) ( $r->get_param( 'team_id' )   ?? 0 );

        $repo = new VctMacroBlocksRepository();

        if ( $season_id === 0 ) {
            return RestResponse::success( [ 'references' => $repo->listReferenceTemplates() ] );
        }
        return RestResponse::success( [
            'season_id' => $season_id,
            'team_id'   => $team_id,
            'blocks'    => $repo->listForSeason( $team_id, $season_id ),
        ] );
    }
}
