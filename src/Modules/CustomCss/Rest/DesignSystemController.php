<?php
namespace TT\Modules\CustomCss\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomCss\DesignSystem\TokenCatalogue;
use TT\Modules\CustomCss\Repositories\CustomCssRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * DesignSystemController — read-only REST surface for the #0075
 * design-system token catalogue + the operator's current saved
 * values, per CLAUDE.md § 4 (every feature reachable through REST).
 *
 * Routes:
 *   GET  /wp-json/talenttrack/v1/design-system/tokens
 *
 * Returns the full catalogue (with category groupings, kind, defaults,
 * validation metadata) plus the live `visual_settings` blob from
 * `tt_config:custom_css.frontend.visual_settings`. PUT is deliberately
 * not implemented yet — operators write through the visual editor's
 * server-side `save_visual` POST handler so storage shape changes can
 * land later without breaking external clients. The first PUT consumer
 * will be a future SaaS frontend or a Figma → tokens importer; it
 * gates on a structured-storage decision that's parked for Sprint 3.
 */
final class DesignSystemController {

    public const NAMESPACE_V1 = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
    }

    public static function registerRoutes(): void {
        register_rest_route(
            self::NAMESPACE_V1,
            '/design-system/tokens',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ self::class, 'getTokens' ],
                    'permission_callback' => [ self::class, 'permissionCheck' ],
                ],
            ]
        );
    }

    public static function permissionCheck(): bool {
        return current_user_can( 'tt_admin_styling' );
    }

    public static function getTokens( WP_REST_Request $request ): WP_REST_Response {
        $surface = (string) $request->get_param( 'surface' );
        $surface = $surface !== '' ? CustomCssRepository::sanitizeSurface( $surface ) : CustomCssRepository::SURFACE_FRONTEND;

        $repo = new CustomCssRepository();
        $live = $repo->getLive( $surface );
        $settings = is_array( $live['visual_settings'] ?? null ) ? $live['visual_settings'] : [];

        $catalogue_payload = [];
        foreach ( TokenCatalogue::all() as $key => $def ) {
            $entry = [
                'key'      => (string) $def['key'],
                'css_var'  => (string) $def['css_var'],
                'category' => (string) $def['category'],
                'kind'     => (string) $def['kind'],
                'label'    => (string) $def['label'],
                'default'  => (string) ( $def['default'] ?? '' ),
                'value'    => isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '',
            ];
            foreach ( [ 'min', 'max', 'step', 'unit' ] as $opt ) {
                if ( isset( $def[ $opt ] ) ) $entry[ $opt ] = $def[ $opt ];
            }
            if ( isset( $def['options'] ) && is_array( $def['options'] ) ) {
                $entry['options'] = array_map( 'strval', $def['options'] );
            }
            $catalogue_payload[] = $entry;
        }

        $categories_payload = [];
        foreach ( TokenCatalogue::categoriesInOrder() as $slug => $label ) {
            $categories_payload[] = [
                'slug'  => $slug,
                'label' => (string) $label,
            ];
        }

        return new WP_REST_Response( [
            'surface'    => $surface,
            'version'    => (int) ( $live['version'] ?? 0 ),
            'enabled'    => (bool) ( $live['enabled'] ?? false ),
            'categories' => $categories_payload,
            'tokens'     => $catalogue_payload,
        ], 200 );
    }
}
