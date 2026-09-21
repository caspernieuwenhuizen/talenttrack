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
                'args'                => self::linkArgs(),
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

    /**
     * #3819 — the body `POST /players/{id}/account` takes. The route has
     * two branches and both are declared: `create` provisions a new login
     * from the name and email, and its absence links the `wp_user_id`
     * instead.
     *
     * Nothing is declared `required`: core checks required params before
     * the permission callback, and this route hands somebody an account on
     * a minor's record — an unauthorised caller is owed a 403, not a 400
     * describing how the linking works. `PlayerAccountService` refuses an
     * incomplete request with a 422 that says what is missing.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function linkArgs(): array {
        return [
            'id'            => [ 'type' => [ 'integer', 'string' ], 'description' => 'The player, from the URL. A copy in the body is accepted and ignored.' ],
            'create'        => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Provision a new login rather than linking an existing one.' ],
            'wp_user_id'    => [ 'type' => [ 'integer', 'string' ], 'description' => 'The existing account to link. Read only when create is absent.' ],
            'first_name'    => [ 'type' => 'string', 'description' => 'The new account\'s first name. Create only.' ],
            'last_name'     => [ 'type' => 'string', 'description' => 'The new account\'s last name. Create only.' ],
            'email'         => [ 'type' => 'string', 'description' => 'Where the set-password mail goes. Create only.' ],
            'temp_password' => [ 'type' => 'string', 'description' => 'A starting password instead of the set-password mail. Create only; blank sends the mail.' ],
        ];
    }

    public static function link( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::linkArgs() );
        if ( $refused !== null ) return $refused;

        $player_id = absint( $r['id'] );
        $svc       = new PlayerAccountService();

        // #1847 — direct-create branch: provision a brand-new player WP
        // account (set-password email by default) and link it.
        if ( ! empty( $r['create'] ) ) {
            $result = $svc->directCreate(
                $player_id,
                sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ),
                sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ),
                sanitize_email( (string) ( $r['email'] ?? '' ) ),
                ! empty( $r['temp_password'] ) ? (string) $r['temp_password'] : null
            );
        } else {
            $result = $svc->link( $player_id, absint( $r['wp_user_id'] ?? 0 ) );
        }
        if ( ! $result['ok'] ) {
            return RestResponse::error( $result['code'], $result['message'], 422 );
        }
        return RestResponse::success( [ 'player_id' => $player_id, 'wp_user_id' => $result['user_id'] ?? absint( $r['wp_user_id'] ?? 0 ), 'status' => $result['code'] ] );
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
