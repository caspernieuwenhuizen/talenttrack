<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\GuardianContact\GuardianContactRequest;
use WP_REST_Request;

/**
 * GuardianContactRestController (#3794) — the guardian-contact link as
 * an API, so the rendered form is one consumer of it rather than the
 * only way in (CLAUDE.md §4).
 *
 *   POST /players/{id}/guardian-contact-request   ask a family
 *   GET  /guardian-contact/{token}                what the page may show
 *   POST /guardian-contact/{token}                the family's answer
 *
 * The two token routes are public, because the family has no account —
 * that is the problem this solves. The token is the credential, as it is
 * for the invitation lookup beside it. Everything they can reach is
 * therefore narrow on purpose: the read answers with the child's name
 * and nothing else, and both answer the same way for an unknown token as
 * for an expired one, so neither can be used to enumerate.
 */
final class GuardianContactRestController extends BaseController {

    private const TOKEN_PATTERN = '(?P<token>[A-Za-z0-9_\-]{16,64})';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/guardian-contact-request', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'requestFromFamily' ],
                'permission_callback' => self::permCan( GuardianContactRequest::CAP ),
                'args'                => [
                    'id' => [
                        'sanitize_callback' => 'absint',
                        'validate_callback' => [ self::class, 'isPositiveInt' ],
                    ],
                    'email' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_email',
                        'description'       => 'Where to send the request. The family needs no account.',
                    ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/guardian-contact/' . self::TOKEN_PATTERN, [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'read' ],
                'permission_callback' => '__return_true', // The token is the credential.
                'args'                => self::tokenArg(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'submit' ],
                'permission_callback' => '__return_true', // The token is the credential.
                // The body is declared (#3689) but not marked required:
                // what makes an answer usable — a name, consent, and at
                // least one way to be reached — is one rule, and it is
                // stated once in the domain layer so the public form and
                // the API refuse the same things in the same words.
                'args'                => self::tokenArg() + [
                    'guardian_name'  => [ 'sanitize_callback' => 'sanitize_text_field', 'description' => 'The name of the parent or guardian answering.' ],
                    'guardian_email' => [ 'sanitize_callback' => 'sanitize_email', 'description' => 'Their email address.' ],
                    'guardian_phone' => [ 'sanitize_callback' => 'sanitize_text_field', 'description' => 'Their phone number, as they write it.' ],
                    'consent'        => [ 'description' => 'Confirmation that the academy may use these details to make contact.' ],
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

    public static function requestFromFamily( WP_REST_Request $req ): \WP_REST_Response {
        $result = GuardianContactRequest::send(
            (int) $req->get_param( 'id' ),
            (string) ( $req->get_param( 'email' ) ?? '' )
        );

        if ( ! $result['ok'] ) {
            return RestResponse::error( 'guardian_contact_request_failed', (string) $result['error'], 400 );
        }

        // The token is not returned. It is a credential for one family,
        // it travels by email, and an API that hands it back invites a
        // staff surface to print it on a screen.
        return RestResponse::success( [ 'id' => (int) $result['id'], 'sent' => true ], 201 );
    }

    /**
     * What the public form may know: the child's name, and when the link
     * stops working. Never what is already on file — that may describe
     * the other parent.
     */
    public static function read( WP_REST_Request $req ): \WP_REST_Response {
        $request = GuardianContactRequest::resolve( (string) $req->get_param( 'token' ) );
        if ( $request === null ) return self::gone();

        $row = (array) $request;
        return RestResponse::success( [
            'player_name' => GuardianContactRequest::playerName( (int) ( $row['target_player_id'] ?? 0 ) ),
            'expires_at'  => (string) ( $row['expires_at'] ?? '' ),
            'fields'      => GuardianContactRequest::FIELDS,
        ] );
    }

    public static function submit( WP_REST_Request $req ): \WP_REST_Response {
        $request = GuardianContactRequest::resolve( (string) $req->get_param( 'token' ) );
        if ( $request === null ) return self::gone();

        $result = GuardianContactRequest::submit( $request, [
            'guardian_name'  => (string) ( $req->get_param( 'guardian_name' ) ?? '' ),
            'guardian_email' => (string) ( $req->get_param( 'guardian_email' ) ?? '' ),
            'guardian_phone' => (string) ( $req->get_param( 'guardian_phone' ) ?? '' ),
            'consent'        => ! empty( $req->get_param( 'consent' ) ),
        ] );

        if ( ! $result['ok'] ) {
            return RestResponse::error( 'guardian_contact_refused', (string) $result['error'], 400 );
        }

        // The changed values are not echoed back: the submitter typed
        // them, and the response is not a place to reprint a minor's
        // family contact details.
        return RestResponse::success( [ 'saved' => true, 'fields_changed' => array_keys( $result['changes'] ) ] );
    }

    /** One answer for unknown, tampered, expired, revoked and spent. */
    private static function gone(): \WP_REST_Response {
        return RestResponse::error(
            'guardian_contact_link_invalid',
            __( 'This link is no longer valid. Ask the academy to send you a new one.', 'talenttrack' ),
            404
        );
    }
}
