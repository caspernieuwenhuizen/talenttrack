<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * ConfigRestController — /wp-json/talenttrack/v1/config
 *
 * #0019 Sprint 5. Thin write surface around `QueryHelpers::set_config`
 * so the frontend Configuration view can save without going through
 * the wp-admin admin-post.php handler. The cap gate is
 * `tt_edit_settings` (same as the existing wp-admin save handler).
 *
 * Whitelist of accepted keys lives in `ALLOWED_KEYS` — drift-prone
 * if we accept arbitrary keys, and the frontend doesn't expose
 * everything anyway.
 */
class ConfigRestController {

    const NS = 'talenttrack/v1';

    /** Keys writable from the frontend Configuration view. */
    private const ALLOWED_KEYS = [
        // Branding (existing)
        'academy_name',
        'logo_url',
        'primary_color',
        'secondary_color',
        // Branding additions from #0023
        'theme_inherit',
        'font_display',
        'font_body',
        'color_accent',
        'color_danger',
        'color_warning',
        'color_success',
        'color_info',
        'color_focus',
        // Rating scale
        'rating_min',
        'rating_max',
        'rating_step',
        // #0019 Sprint 6 — wp-admin legacy-menu toggle
        'show_legacy_menus',
    ];

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/config', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_config' ],
                'permission_callback' => function () { return current_user_can( 'tt_view_settings' ) || current_user_can( 'tt_edit_settings' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'save_config' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_settings' ); },
            ],
        ] );
    }

    public static function get_config( \WP_REST_Request $r ) {
        $out = [];
        foreach ( self::ALLOWED_KEYS as $key ) {
            $out[ $key ] = QueryHelpers::get_config( $key, '' );
        }
        return RestResponse::success( $out );
    }

    public static function save_config( \WP_REST_Request $r ) {
        $payload = $r->get_param( 'config' );
        if ( ! is_array( $payload ) ) {
            return RestResponse::error( 'bad_payload', __( 'Expected an object under `config`.', 'talenttrack' ), 400 );
        }
        $written = 0;
        foreach ( $payload as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) continue;
            QueryHelpers::set_config( $key, sanitize_text_field( (string) $value ) );
            $written++;
        }
        Logger::info( 'rest.config.saved', [ 'keys_written' => $written, 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'keys_written' => $written ] );
    }
}
