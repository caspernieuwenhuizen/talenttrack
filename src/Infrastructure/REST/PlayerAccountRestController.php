<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\PlayerAccountService;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * PlayerAccountRestController (#1771) —
 * /wp-json/talenttrack/v1/players/{id}/account
 *
 * Resource-oriented link/unlink of a WP account to a player, the primary
 * mapping workflow behind the Player accounts view. Read access stays via
 * the PHP-rendered view; this is the mutate surface, shared with that view
 * through PlayerAccountService so both answer identically (CLAUDE.md §4).
 *
 * Cap model: `tt_manage_players` (academy/club admin via the matrix) — the
 * same capability that gates creating/deleting player records. Checked via
 * the capability layer, never a role-string compare.
 */
class PlayerAccountRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/account', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'link' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'unlink' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
    }

    public static function can_manage(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_manage_players' );
    }

    public static function link( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id  = absint( $r['id'] );
        $wp_user_id = absint( $r['wp_user_id'] ?? 0 );

        $result = ( new PlayerAccountService() )->link( $player_id, $wp_user_id );
        if ( ! $result['ok'] ) {
            return RestResponse::error( $result['code'], $result['message'], 422 );
        }
        return RestResponse::success( [ 'player_id' => $player_id, 'wp_user_id' => $wp_user_id, 'status' => 'linked' ] );
    }

    public static function unlink( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['id'] );

        $result = ( new PlayerAccountService() )->unlink( $player_id );
        if ( ! $result['ok'] ) {
            return RestResponse::error( $result['code'], $result['message'], 422 );
        }
        return RestResponse::success( [ 'player_id' => $player_id, 'status' => 'unlinked' ] );
    }
}
