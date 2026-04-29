<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\InvitationsRepository;
use TT\Modules\Invitations\InvitationStatus;
use WP_REST_Request;

/**
 * InvitationsRestController (#0052 PR-B) — REST surface around the
 * existing `InvitationsRepository`.
 *
 *   POST   /invitations              create a pending invitation
 *   GET    /invitations              list (cap: tt_manage_invitations)
 *   GET    /invitations/{token}      public read by token (token IS the auth)
 *   POST   /invitations/{token}/accept   accept (logged-in user claim)
 *   DELETE /invitations/{id}         revoke (creator or tt_manage_invitations)
 *
 * The classic admin-post / acceptance view flow stays in place (the
 * server-rendered acceptance page still works); these endpoints are
 * the SaaS-frontend contract.
 */
final class InvitationsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/invitations', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list' ],
                'permission_callback' => self::permCan( 'tt_manage_invitations' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'create' ],
                'permission_callback' => self::permCan( 'tt_manage_invitations' ),
            ],
        ] );

        register_rest_route( self::NS, '/invitations/(?P<token>[A-Za-z0-9_\-]{16,128})', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'getByToken' ],
                'permission_callback' => '__return_true', // Token is the auth.
                'args'                => self::tokenArg(),
            ],
        ] );

        register_rest_route( self::NS, '/invitations/(?P<token>[A-Za-z0-9_\-]{16,128})/accept', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'accept' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
                'args'                => self::tokenArg(),
            ],
        ] );

        register_rest_route( self::NS, '/invitations/(?P<id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'revoke' ],
                'permission_callback' => [ self::class, 'canRevoke' ],
                'args'                => [
                    'id' => [
                        'sanitize_callback' => 'absint',
                        'validate_callback' => [ self::class, 'isPositiveInt' ],
                    ],
                ],
            ],
        ] );
    }

    /** @return array<string,array<string,mixed>> */
    private static function tokenArg(): array {
        return [
            'token' => [
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static fn( $v ): bool => is_string( $v ) && $v !== '',
            ],
        ];
    }

    public static function canRevoke( WP_REST_Request $req ): bool {
        if ( ! is_user_logged_in() ) return false;
        if ( current_user_can( 'tt_manage_invitations' ) ) return true;
        $id = (int) $req->get_param( 'id' );
        if ( $id <= 0 ) return false;
        $row = ( new InvitationsRepository() )->find( $id );
        if ( ! $row ) return false;
        return (int) ( $row->created_by ?? 0 ) === get_current_user_id();
    }

    public static function list( WP_REST_Request $req ): \WP_REST_Response {
        $status = (string) ( $req->get_param( 'status' ) ?? '' );
        $rows = ( new InvitationsRepository() )->listAll( 200, $status !== '' ? $status : null );
        return RestResponse::success( array_map( [ self::class, 'serialize' ], is_array( $rows ) ? $rows : [] ) );
    }

    public static function create( WP_REST_Request $req ): \WP_REST_Response {
        $errors = self::requireFields( $req, [ 'kind' ] );
        if ( ! empty( $errors ) ) return RestResponse::errors( $errors, 400 );

        $repo = new InvitationsRepository();
        $data = [
            'kind'         => sanitize_key( (string) $req->get_param( 'kind' ) ),
            'player_id'    => (int) ( $req->get_param( 'player_id' ) ?? 0 ),
            'person_id'    => (int) ( $req->get_param( 'person_id' ) ?? 0 ),
            'email'        => sanitize_email( (string) ( $req->get_param( 'email' ) ?? '' ) ),
            'created_by'   => get_current_user_id(),
            'token'        => self::generateToken(),
            'status'       => InvitationStatus::PENDING,
            'expires_at'   => self::defaultExpiry(),
        ];
        $id = $repo->insert( $data );
        if ( $id <= 0 ) {
            return RestResponse::error( 'invitation_create_failed', __( 'Could not create invitation.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_invitation_created', $id, $data );
        return RestResponse::success( [ 'id' => $id, 'token' => $data['token'] ], 201 );
    }

    public static function getByToken( WP_REST_Request $req ): \WP_REST_Response {
        $row = ( new InvitationsRepository() )->findByToken( (string) $req->get_param( 'token' ) );
        if ( ! $row ) {
            return RestResponse::error( 'invitation_not_found', __( 'Invitation not found.', 'talenttrack' ), 404 );
        }
        // The public endpoint redacts internal fields.
        return RestResponse::success( [
            'kind'       => (string) ( $row->kind ?? '' ),
            'status'     => (string) ( $row->status ?? '' ),
            'expires_at' => (string) ( $row->expires_at ?? '' ),
            'is_pending' => (string) ( $row->status ?? '' ) === InvitationStatus::PENDING,
        ] );
    }

    public static function accept( WP_REST_Request $req ): \WP_REST_Response {
        $repo = new InvitationsRepository();
        $row = $repo->findByToken( (string) $req->get_param( 'token' ) );
        if ( ! $row ) {
            return RestResponse::error( 'invitation_not_found', __( 'Invitation not found.', 'talenttrack' ), 404 );
        }
        if ( (string) $row->status !== InvitationStatus::PENDING ) {
            return RestResponse::error( 'invitation_not_pending', __( 'Invitation is no longer pending.', 'talenttrack' ), 409 );
        }
        $ok = $repo->claimForAcceptance( (int) $row->id, get_current_user_id() );
        if ( ! $ok ) {
            return RestResponse::error( 'invitation_accept_failed', __( 'Could not accept invitation.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_invitation_accepted', (int) $row->id, get_current_user_id() );
        return RestResponse::success( [ 'id' => (int) $row->id, 'accepted' => true ] );
    }

    public static function revoke( WP_REST_Request $req ): \WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $repo = new InvitationsRepository();
        $ok = $repo->update( $id, [ 'status' => InvitationStatus::REVOKED ] );
        if ( ! $ok ) {
            return RestResponse::error( 'invitation_revoke_failed', __( 'Could not revoke invitation.', 'talenttrack' ), 500 );
        }
        do_action( 'tt_invitation_revoked', $id, get_current_user_id() );
        return RestResponse::success( [ 'id' => $id, 'revoked' => true ] );
    }

    /** @return array<string,mixed> */
    private static function serialize( object $row ): array {
        return [
            'id'         => (int) ( $row->id ?? 0 ),
            'kind'       => (string) ( $row->kind ?? '' ),
            'status'     => (string) ( $row->status ?? '' ),
            'email'      => (string) ( $row->email ?? '' ),
            'player_id'  => (int) ( $row->player_id ?? 0 ),
            'person_id'  => (int) ( $row->person_id ?? 0 ),
            'created_by' => (int) ( $row->created_by ?? 0 ),
            'created_at' => (string) ( $row->created_at ?? '' ),
            'expires_at' => (string) ( $row->expires_at ?? '' ),
        ];
    }

    private static function generateToken(): string {
        if ( function_exists( 'wp_generate_password' ) ) {
            return wp_generate_password( 32, false, false );
        }
        return bin2hex( random_bytes( 16 ) );
    }

    private static function defaultExpiry(): string {
        return gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) );
    }
}
