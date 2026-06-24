<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * ParentAccountRestController (#1815) —
 * /wp-json/talenttrack/v1/players/{id}/parents
 *
 * Resource-oriented link/unlink of a parent WP account on a player, the
 * mutate surface behind the Parent accounts view. Shares ParentAccountService
 * with that view so both answer identically (CLAUDE.md §4).
 *
 * Cap model: the dedicated `tt_manage_parent_accounts` capability, checked
 * via the matrix-aware capability layer — never a role-string compare.
 */
class ParentAccountRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/parents', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'link' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/parents/(?P<parent_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'unlink' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
    }

    public static function can_manage(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_manage_parent_accounts' );
    }

    public static function link( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id      = absint( $r['id'] );
        $parent_user_id = absint( $r['wp_user_id'] ?? 0 );

        $result = ( new ParentAccountService() )->linkToPlayer( $player_id, $parent_user_id );
        if ( ! $result['ok'] ) {
            return RestResponse::error( $result['code'], $result['message'], 422 );
        }
        return RestResponse::success( [
            'player_id'  => $player_id,
            'wp_user_id' => $parent_user_id,
            'status'     => $result['code'],
        ] );
    }

    public static function unlink( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id      = absint( $r['id'] );
        $parent_user_id = absint( $r['parent_id'] );

        $result = ( new ParentAccountService() )->unlinkFromPlayer( $player_id, $parent_user_id );
        if ( ! $result['ok'] ) {
            return RestResponse::error( $result['code'], $result['message'], 422 );
        }
        return RestResponse::success( [
            'player_id'  => $player_id,
            'wp_user_id' => $parent_user_id,
            'status'     => $result['code'],
        ] );
    }
}
