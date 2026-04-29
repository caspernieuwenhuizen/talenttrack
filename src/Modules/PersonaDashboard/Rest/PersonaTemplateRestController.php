<?php
namespace TT\Modules\PersonaDashboard\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\PersonaDashboard\Registry\PersonaTemplateRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PersonaTemplateRestController — exposes the resolved layout JSON over
 * the REST API so a future SaaS frontend can render the same widgets
 * the plugin renders.
 *
 *   GET    /wp-json/talenttrack/v1/personas/{slug}/template
 *   PUT    /wp-json/talenttrack/v1/personas/{slug}/template            (sprint 2)
 *   POST   /wp-json/talenttrack/v1/personas/{slug}/template/publish    (sprint 2)
 *   DELETE /wp-json/talenttrack/v1/personas/{slug}/template            (sprint 2)
 *
 * Sprint 1 ships the GET only. Sprint 2 adds the editor write paths.
 */
final class PersonaTemplateRestController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route(
            'talenttrack/v1',
            '/personas/(?P<slug>[a-z_]+)/template',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ self::class, 'getTemplate' ],
                    'permission_callback' => [ self::class, 'canRead' ],
                    'args'                => [
                        'slug' => [
                            'sanitize_callback' => 'sanitize_key',
                            'validate_callback' => [ self::class, 'isKnownPersona' ],
                        ],
                    ],
                ],
            ]
        );
    }

    public static function canRead( WP_REST_Request $req ): bool {
        // Every logged-in user can read templates they're entitled to (their
        // own resolved persona). The auth-matrix bridge gates downstream
        // surface access; this endpoint just exposes the layout JSON.
        return is_user_logged_in() && current_user_can( 'read' );
    }

    public static function isKnownPersona( $value ): bool {
        $value   = is_string( $value ) ? sanitize_key( $value ) : '';
        $defaults = PersonaTemplateRegistry::defaultPersonas();
        return in_array( $value, $defaults, true );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function getTemplate( WP_REST_Request $req ) {
        $slug = (string) $req->get_param( 'slug' );
        $user = wp_get_current_user();
        $allowed = PersonaResolver::personasFor( (int) $user->ID );

        // Editors can request any persona's template (for "preview as
        // persona"); other users can only request their own.
        if ( ! current_user_can( 'tt_edit_persona_templates' ) && ! in_array( $slug, $allowed, true ) ) {
            return new WP_Error(
                'tt_persona_forbidden',
                __( 'You can only read your own persona template.', 'talenttrack' ),
                [ 'status' => 403 ]
            );
        }

        $club_id  = self::currentClubId();
        $template = PersonaTemplateRegistry::resolve( $slug, $club_id );
        return new WP_REST_Response( $template->toArray(), 200 );
    }

    private static function currentClubId(): int {
        if ( class_exists( '\\TT\\Infrastructure\\Tenancy\\CurrentClub' ) ) {
            return (int) \TT\Infrastructure\Tenancy\CurrentClub::id();
        }
        return 1;
    }
}
