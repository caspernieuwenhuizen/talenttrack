<?php
namespace TT\Modules\PersonaDashboard\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ActivePersonaController — POST /me/active-persona writes the user's
 * picked persona to tt_user_meta.tt_active_persona.
 *
 * Sprint 1 wires the endpoint; the role-switcher pill in
 * PersonaLandingRenderer posts to it on click. The endpoint validates
 * the picked persona against PersonaResolver::personasFor() so a user
 * can't claim a persona they don't qualify for.
 */
final class ActivePersonaController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route(
            'talenttrack/v1',
            '/me/active-persona',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ self::class, 'set' ],
                    'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can( 'read' ),
                    'args'                => [
                        'persona' => [
                            'sanitize_callback' => 'sanitize_key',
                            'required'          => true,
                        ],
                    ],
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => [ self::class, 'reset' ],
                    'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can( 'read' ),
                ],
            ]
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function set( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $picked  = (string) $req->get_param( 'persona' );

        $available = PersonaResolver::personasFor( $user_id );
        if ( ! in_array( $picked, $available, true ) ) {
            return new WP_Error(
                'tt_persona_unavailable',
                __( 'You do not qualify for that persona.', 'talenttrack' ),
                [ 'status' => 403 ]
            );
        }
        update_user_meta( $user_id, 'tt_active_persona', $picked );
        return new WP_REST_Response( [ 'persona' => $picked ], 200 );
    }

    public static function reset(): WP_REST_Response {
        $user_id = get_current_user_id();
        delete_user_meta( $user_id, 'tt_active_persona' );
        return new WP_REST_Response( [ 'persona' => null ], 200 );
    }
}
