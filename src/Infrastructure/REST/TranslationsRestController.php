<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Translations\TranslationLayer;

/**
 * TranslationsRestController — /wp-json/talenttrack/v1/translations/*
 *
 * #1935 — write surface for the frontend Translations configuration
 * view (`?tt_view=translations`). The generic `POST /config` controller
 * can't carry the translation engine's special save semantics
 * (keep-on-blank credentials, the enable-validation gate, the GDPR
 * opt-out cache purge), so this dedicated controller exposes two
 * resource actions and delegates the actual work to the domain layer
 * (`TranslationLayer::saveSettings` / `TranslationLayer::purgeAllCaches`).
 * Both the wp-admin tab and this controller call the same domain
 * methods, so a future SaaS frontend gets identical behaviour.
 *
 *   POST /translations/settings    — persist the engine config
 *   POST /translations/clear-cache — purge the translation caches
 *
 * Gated on the matrix caps `tt_view_translations` (read) and
 * `tt_edit_translations` (write) — never a role-string compare.
 */
class TranslationsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/translations/settings', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'save_settings' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_translations' ); },
                'args'                => self::settingsArgs(),
            ],
        ] );

        register_rest_route( self::NS, '/translations/clear-cache', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'clear_cache' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_translations' ); },
                'args'                => [],
            ],
        ] );
    }

    /**
     * #3819 — the body `POST /translations/settings` takes.
     *
     * The settings view posts the whole form, so every key is written on
     * every save. Nothing is declared `required`: core checks required
     * params before the permission callback, so a required key would
     * answer an unauthorised POST with a 400 naming the engine credentials
     * rather than the 403 it is owed. `TranslationLayer::saveSettings()`
     * refuses an incomplete configuration itself, with a 422 that says
     * what is missing.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function settingsArgs(): array {
        return [
            'enabled'                => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Whether machine translation is switched on.' ],
            'subprocessor_confirmed' => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Whether the Article 28 sub-processor confirmation has been ticked. Required before the engine may be enabled.' ],
            'primary_engine'         => [ 'type' => 'string', 'description' => 'Which engine translates first. Defaults to deepl.' ],
            'fallback_engine'        => [ 'type' => 'string', 'description' => 'Which engine to try when the primary one fails. Blank means none.' ],
            'deepl_key'              => [ 'type' => 'string', 'description' => 'The DeepL API key. Blank keeps the stored one rather than clearing it.' ],
            'google_service_account' => [ 'type' => 'string', 'description' => 'The Google service-account JSON. Blank keeps the stored one rather than clearing it.' ],
            'site_default_lang'      => [ 'type' => 'string', 'description' => 'The language the content is authored in.' ],
            'monthly_cap'            => [ 'type' => [ 'integer', 'string' ], 'description' => 'How many characters may be translated in a month.' ],
            'threshold_pct'          => [ 'type' => [ 'integer', 'string' ], 'description' => 'At what percentage of the cap the warning appears.' ],
        ];
    }

    public static function save_settings( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::settingsArgs() );
        if ( $refused !== null ) return $refused;

        $result = TranslationLayer::saveSettings( [
            'enabled'                => (bool) $r->get_param( 'enabled' ),
            'subprocessor_confirmed' => (bool) $r->get_param( 'subprocessor_confirmed' ),
            'primary_engine'         => (string) ( $r->get_param( 'primary_engine' ) ?? 'deepl' ),
            'fallback_engine'        => (string) ( $r->get_param( 'fallback_engine' ) ?? '' ),
            'deepl_key'              => (string) ( $r->get_param( 'deepl_key' ) ?? '' ),
            'google_service_account' => (string) ( $r->get_param( 'google_service_account' ) ?? '' ),
            'site_default_lang'      => (string) ( $r->get_param( 'site_default_lang' ) ?? '' ),
            'monthly_cap'            => (int) ( $r->get_param( 'monthly_cap' ) ?? TranslationLayer::DEFAULT_MONTHLY_CAP ),
            'threshold_pct'          => (int) ( $r->get_param( 'threshold_pct' ) ?? TranslationLayer::DEFAULT_THRESHOLD_PCT ),
        ] );

        if ( ! $result['ok'] ) {
            return RestResponse::error(
                (string) $result['error_code'],
                self::errorMessage( (string) $result['error_code'] ),
                422
            );
        }

        Logger::info( 'rest.translations.saved', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'enabled' => TranslationLayer::isEnabled() ] );
    }

    public static function clear_cache( \WP_REST_Request $r ) {
        // #3819 — the route takes no body.
        $refused = BaseController::checkBody( $r, [] );
        if ( $refused !== null ) return $refused;

        TranslationLayer::purgeAllCaches();
        Logger::info( 'rest.translations.cache_cleared', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'cleared' => true ] );
    }

    private static function errorMessage( string $code ): string {
        switch ( $code ) {
            case 'subprocessor_required':
                return __( 'Tick the Article 28 sub-processor confirmation before enabling.', 'talenttrack' );
            case 'credentials_required':
                return __( 'Add credentials for the selected primary engine before enabling.', 'talenttrack' );
        }
        return __( 'Translation settings could not be saved.', 'talenttrack' );
    }
}
