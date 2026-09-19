<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\PlayerParentsRepository;

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

    /** #3571 — shortest search term the eligible-accounts lookup accepts. */
    private const MIN_SEARCH = 2;

    /** #3571 — most accounts one lookup returns. */
    private const MAX_RESULTS = 20;

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/parents', [
            // #3571 — the links that exist, so a client can check its work.
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_parents' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'link' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
                // #3571 — declared, so the route index says what it takes.
                'args'                => [
                    'wp_user_id'    => [ 'type' => 'integer', 'description' => 'The existing account to link as a parent. Find it with GET parent-accounts/eligible. Required unless create is set.' ],
                    'create'        => [ 'type' => 'boolean', 'description' => 'Create a new parent account from first_name, last_name and email, then link it.' ],
                    'first_name'    => [ 'type' => 'string', 'description' => 'With create: the parent\'s first name.' ],
                    'last_name'     => [ 'type' => 'string', 'description' => 'With create: the parent\'s last name.' ],
                    'email'         => [ 'type' => 'string', 'description' => 'With create: the parent\'s email address. They get a set-your-password email.' ],
                    'temp_password' => [ 'type' => 'string', 'description' => 'With create, only when there is no usable email: a temporary password instead of the email.' ],
                ],
            ],
        ] );
        register_rest_route( self::NS, '/parent-accounts/eligible', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'eligible' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
                'args'                => [
                    'search' => [ 'type' => 'string', 'required' => true, 'description' => 'At least two characters, matched against the display name and the email address.' ],
                ],
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
        $player_id = absint( $r['id'] );
        $svc       = new ParentAccountService();

        // #1847 — direct-create branch: provision a brand-new parent WP
        // account (set-password email by default) and link it, instead of
        // linking an existing user. Same endpoint, gated by the same cap.
        if ( ! empty( $r['create'] ) ) {
            $result = $svc->directCreate(
                $player_id,
                sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ),
                sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ),
                sanitize_email( (string) ( $r['email'] ?? '' ) ),
                ! empty( $r['temp_password'] ) ? (string) $r['temp_password'] : null
            );
        } else {
            // #3571 — say which field is missing; the old message named none.
            if ( absint( $r['wp_user_id'] ?? 0 ) <= 0 ) {
                return RestResponse::error(
                    'bad_request',
                    __( 'Say which account to link: wp_user_id is required.', 'talenttrack' ),
                    422,
                    [ 'field' => 'wp_user_id' ]
                );
            }
            $result = $svc->linkToPlayer( $player_id, absint( $r['wp_user_id'] ?? 0 ) );
        }

        if ( ! $result['ok'] ) {
            return RestResponse::error( $result['code'], $result['message'], 422 );
        }
        return RestResponse::success( [
            'player_id'  => $player_id,
            'wp_user_id' => $result['user_id'] ?? absint( $r['wp_user_id'] ?? 0 ),
            // #3571 — "noop" read as "skipped"; it means the link was
            // already there, and the message says so.
            'status'     => $result['code'] === 'noop' ? 'already_linked' : $result['code'],
            'message'    => $result['message'],
        ] );
    }

    /**
     * GET /players/{id}/parents (#3571) — the parent accounts linked to a
     * player, primary first.
     */
    public static function list_parents( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['id'] );
        $player    = QueryHelpers::get_player( $player_id );
        if ( ! $player || (int) ( ( (array) $player )['club_id'] ?? 0 ) !== (int) CurrentClub::id() ) {
            return RestResponse::error( 'not_found', __( 'Player not found.', 'talenttrack' ), 404 );
        }

        $parents = [];
        foreach ( ( new PlayerParentsRepository() )->linksForPlayer( $player_id ) as $link ) {
            $uid  = (int) ( ( (array) $link )['parent_user_id'] ?? 0 );
            $user = $uid > 0 ? get_userdata( $uid ) : false;
            $parents[] = [
                'wp_user_id'   => $uid,
                'display_name' => $user ? (string) $user->display_name : '',
                'email'        => $user ? (string) $user->user_email : '',
                'is_primary'   => (bool) ( ( (array) $link )['is_primary'] ?? false ),
            ];
        }

        return RestResponse::success( [ 'player_id' => $player_id, 'parents' => $parents ] );
    }

    /**
     * GET /parent-accounts/eligible?search= (#3571) — accounts that could be
     * linked as a parent: not a player or a staff person in this club.
     *
     * A search term is required and results are capped, so the route can
     * find the account an admin has in mind but cannot be used to list every
     * address on the site.
     */
    public static function eligible( \WP_REST_Request $r ): \WP_REST_Response {
        $search = trim( sanitize_text_field( (string) ( $r['search'] ?? '' ) ) );
        if ( mb_strlen( $search ) < self::MIN_SEARCH ) {
            return RestResponse::error(
                'bad_search',
                __( 'Type at least two characters of the name or email address.', 'talenttrack' ),
                400,
                [ 'field' => 'search' ]
            );
        }

        $accounts = [];
        foreach ( ( new ParentAccountService() )->eligibleUsers( $search, self::MAX_RESULTS ) as $user ) {
            $u          = (array) $user;
            $accounts[] = [
                'id'           => (int) ( $u['ID'] ?? 0 ),
                'display_name' => (string) ( $u['display_name'] ?? '' ),
                'email'        => (string) ( $u['user_email'] ?? '' ),
            ];
        }

        return RestResponse::success( [ 'accounts' => $accounts ] );
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
