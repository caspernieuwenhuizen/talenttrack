<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Configuration\SettingsRegistry;
use TT\Shared\Tiles\NavigationGroups;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SettingsEntriesRestController (#4670) — `GET /settings-entries`, the
 * settings the caller may open, from `SettingsRegistry`.
 *
 * The Configuration landing and this route read the same registry, so a
 * non-WordPress front end and the global search get the entries the page
 * shows, filtered the same way. `q` narrows by label, description, keywords
 * and section name, with the rule the page's filter box uses.
 */
final class SettingsEntriesRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::NS, '/settings-entries', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'index' ],
            'permission_callback' => [ __CLASS__, 'mayRead' ],
            'args'                => [
                'q' => [ 'type' => 'string', 'required' => false ],
            ],
        ] );
    }

    /**
     * `tt_view_settings`, or the right to open at least one entry: a coach
     * who may import players reaches that one setting and nothing else.
     */
    public static function mayRead(): bool {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return false;
        if ( current_user_can( 'tt_view_settings' ) ) return true;
        return SettingsRegistry::entriesFor( $user_id ) !== [];
    }

    public static function index( WP_REST_Request $request ): WP_REST_Response {
        $q   = trim( (string) $request->get_param( 'q' ) );
        $out = [];
        foreach ( SettingsRegistry::entriesFor( get_current_user_id() ) as $entry ) {
            if ( ! SettingsRegistry::matches( $entry, $q ) ) continue;
            $out[] = [
                'key'           => $entry['key'],
                'section'       => $entry['section'],
                'section_label' => NavigationGroups::label( $entry['section'] ),
                'label'         => $entry['label'],
                'description'   => $entry['description'],
                'keywords'      => $entry['keywords'],
                'url'           => $entry['url'],
                'module_state'  => $entry['module_state'],
            ];
        }
        return RestResponse::success( [ 'entries' => $out ] );
    }
}
