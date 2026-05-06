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
 * the wp-admin admin-post.php handler.
 *
 * #0080 Wave C3 — was umbrella `tt_view_settings` / `tt_edit_settings`,
 * now per-key sub-cap routing. Each writable key is mapped to a
 * sub-cap area (branding / feature_toggles / rating_scale / …);
 * the GET / POST handlers gate per key. Existing umbrella holders
 * keep working via `CapabilityAliases` (the roll-up still grants the
 * umbrella when every sub-cap is held).
 *
 * Whitelist of accepted keys lives in `ALLOWED_KEYS` — drift-prone if
 * we accept arbitrary keys, and the frontend doesn't expose
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
        // #0060 — default-dashboard toggle (persona vs classic tile grid)
        'persona_dashboard.enabled',
        // #0069 — per-persona overrides for the default-dashboard
        // toggle. Any of these may resolve to '' (inherit), '1'
        // (persona dashboard), or '0' (classic tile grid).
        'persona_dashboard.academy_admin.enabled',
        'persona_dashboard.head_of_development.enabled',
        'persona_dashboard.head_coach.enabled',
        'persona_dashboard.assistant_coach.enabled',
        'persona_dashboard.team_manager.enabled',
        'persona_dashboard.scout.enabled',
        'persona_dashboard.player.enabled',
        'persona_dashboard.parent.enabled',
        'persona_dashboard.readonly_observer.enabled',
    ];

    /**
     * Exact-match key → sub-cap area. Areas resolve to `tt_view_<area>`
     * and `tt_edit_<area>` per request action.
     */
    private const KEY_AREA_MAP = [
        'academy_name'      => 'branding',
        'logo_url'          => 'branding',
        'primary_color'     => 'branding',
        'secondary_color'   => 'branding',
        'theme_inherit'     => 'branding',
        'font_display'      => 'branding',
        'font_body'         => 'branding',
        'color_accent'      => 'branding',
        'color_danger'      => 'branding',
        'color_warning'     => 'branding',
        'color_success'     => 'branding',
        'color_info'        => 'branding',
        'color_focus'       => 'branding',
        'rating_min'        => 'rating_scale',
        'rating_max'        => 'rating_scale',
        'rating_step'       => 'rating_scale',
        'show_legacy_menus' => 'feature_toggles',
    ];

    /**
     * Areas this controller can route to. Used by the route gates as
     * the "user has at least one relevant sub-cap" allow-list.
     */
    private const AREAS = [
        'branding', 'rating_scale', 'feature_toggles',
        'lookups', 'translations',
    ];

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/config', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_config' ],
                'permission_callback' => function () { return self::userHasAnyAreaCap( 'view' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'save_config' ],
                'permission_callback' => function () { return self::userHasAnyAreaCap( 'edit' ); },
            ],
        ] );
    }

    public static function get_config( \WP_REST_Request $r ) {
        $out = [];
        foreach ( self::ALLOWED_KEYS as $key ) {
            if ( ! current_user_can( self::capForKey( $key, 'view' ) ) ) continue;
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
        $skipped = [];
        foreach ( $payload as $key => $value ) {
            $key = (string) $key;
            // Whitelist is the security boundary — match raw key (some
            // legitimate keys contain dots, e.g. `persona_dashboard.enabled`,
            // which sanitize_key would strip).
            if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) continue;
            if ( ! current_user_can( self::capForKey( $key, 'edit' ) ) ) {
                $skipped[] = $key;
                continue;
            }
            QueryHelpers::set_config( $key, sanitize_text_field( (string) $value ) );
            $written++;
        }
        Logger::info( 'rest.config.saved', [
            'keys_written' => $written,
            'keys_skipped' => $skipped,
            'user'         => get_current_user_id(),
        ] );
        return RestResponse::success( [ 'keys_written' => $written, 'keys_skipped' => $skipped ] );
    }

    /**
     * Resolve the sub-cap that gates a given config key.
     *
     * Order: exact-match table → `persona_dashboard.*` prefix bucket →
     * umbrella fallback. The umbrella is the right answer for keys we
     * haven't yet area-categorised; the CapabilityAliases roll-up
     * still resolves it to the union of every sub-cap so umbrella
     * holders pass.
     */
    private static function capForKey( string $key, string $action ): string {
        $area = self::KEY_AREA_MAP[ $key ] ?? null;
        if ( $area === null && strpos( $key, 'persona_dashboard.' ) === 0 ) {
            $area = 'feature_toggles';
        }
        if ( $area === null ) {
            return $action === 'view' ? 'tt_view_settings' : 'tt_edit_settings';
        }
        return ( $action === 'view' ? 'tt_view_' : 'tt_edit_' ) . $area;
    }

    /**
     * True if the user holds any sub-cap that lets them touch at least
     * one config area exposed by this controller (or the umbrella).
     */
    private static function userHasAnyAreaCap( string $action ): bool {
        $umbrella = $action === 'view' ? 'tt_view_settings' : 'tt_edit_settings';
        if ( current_user_can( $umbrella ) ) return true;
        $prefix = $action === 'view' ? 'tt_view_' : 'tt_edit_';
        foreach ( self::AREAS as $area ) {
            if ( current_user_can( $prefix . $area ) ) return true;
        }
        return false;
    }
}
