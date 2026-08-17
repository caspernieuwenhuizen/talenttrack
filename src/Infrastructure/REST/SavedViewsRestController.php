<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Infrastructure\Filters\SavedViewsRepository;
use WP_REST_Request;

/**
 * SavedViewsRestController (#2448) — personal named filter presets for any
 * surface that renders the shared FilterBar.
 *
 * Promoted off ReportsRestController (#2385), which registered these under
 * `/reports/filter-presets` and hardcoded `tt_view_analytics` on all three
 * routes. Two things change:
 *
 *   1. The route drops the `reports/` prefix — saved views are no longer
 *      reports-only. The old paths stay registered as aliases for one
 *      release so a page loaded just before a deploy keeps working.
 *   2. The capability comes from SavedViewsRegistry, keyed on the surface,
 *      so a players-list preset is gated on the players capability rather
 *      than the analytics one. The gate is per-request on the submitted
 *      `view_key`, not fixed at registration time.
 *
 * Ownership is enforced in the repository: every query is scoped to the
 * caller's user id and the current club, so a user can only ever read or
 * mutate their own rows.
 */
final class SavedViewsRestController extends BaseController {

    /** Hard ceilings on the opaque filter payload. */
    private const MAX_KEYS       = 20;
    private const MAX_VALUE_LEN  = 200;
    private const MAX_NAME_LEN   = 120;

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
    }

    public static function registerRoutes(): void {
        foreach ( [ '/filter-presets', '/reports/filter-presets' ] as $base ) {
            register_rest_route( self::NS, $base, [
                [
                    'methods'             => 'GET',
                    'callback'            => [ self::class, 'listViews' ],
                    'permission_callback' => [ self::class, 'permitRequest' ],
                    'args'                => [
                        'view_key' => [ 'sanitize_callback' => 'sanitize_key' ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ self::class, 'saveView' ],
                    'permission_callback' => [ self::class, 'permitRequest' ],
                    'args'                => [
                        'view_key' => [ 'sanitize_callback' => 'sanitize_key' ],
                        'name'     => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => true ],
                    ],
                ],
            ] );

            register_rest_route( self::NS, $base . '/(?P<id>\d+)', [
                [
                    'methods'             => 'DELETE',
                    'callback'            => [ self::class, 'deleteView' ],
                    'permission_callback' => [ self::class, 'permitDelete' ],
                    'args'                => [
                        'id' => [ 'validate_callback' => [ self::class, 'isPositiveInt' ] ],
                    ],
                ],
            ] );
        }
    }

    /**
     * Gate on the capability the *surface* is gated by, resolved from the
     * registry. An unknown or missing key is refused — never fall through to
     * a permissive default, or an unregistered key becomes a bypass.
     */
    public static function permitRequest( WP_REST_Request $req ): bool {
        if ( ! is_user_logged_in() ) return false;
        return SavedViewsRegistry::currentUserCan( self::viewKey( $req ) );
    }

    /**
     * DELETE carries no view_key — the row does. Resolve the row first (which
     * also proves ownership), then gate on its surface's capability.
     */
    public static function permitDelete( WP_REST_Request $req ): bool {
        if ( ! is_user_logged_in() ) return false;
        $row = ( new SavedViewsRepository() )->find( (int) $req->get_param( 'id' ), get_current_user_id() );
        if ( $row === null ) return false;
        return SavedViewsRegistry::currentUserCan( (string) $row->view_key );
    }

    /** #2448 — a user's saved views for one surface. */
    public static function listViews( WP_REST_Request $req ): \WP_REST_Response {
        $rows = ( new SavedViewsRepository() )->listForUser( get_current_user_id(), self::viewKey( $req ) );
        return RestResponse::success( [ 'views' => array_values( array_map(
            [ self::class, 'shapeView' ],
            $rows
        ) ) ] );
    }

    /** #2448 — persist the current filter set under a name. */
    public static function saveView( WP_REST_Request $req ): \WP_REST_Response {
        $view_key = self::viewKey( $req );
        if ( $view_key === '' ) {
            return RestResponse::error( 'missing_view_key', __( 'No view key supplied.', 'talenttrack' ), 400 );
        }

        $name = trim( (string) $req->get_param( 'name' ) );
        if ( $name === '' ) {
            return RestResponse::error( 'missing_name', __( 'Give this view a name first.', 'talenttrack' ), 400 );
        }
        if ( mb_strlen( $name ) > self::MAX_NAME_LEN ) {
            $name = mb_substr( $name, 0, self::MAX_NAME_LEN );
        }

        $raw     = $req->get_param( 'filters' );
        $filters = self::sanitizeFilterPayload( is_array( $raw ) ? $raw : [] );

        $row = ( new SavedViewsRepository() )->create( get_current_user_id(), $view_key, $name, $filters );
        if ( $row === null ) {
            return RestResponse::error( 'save_failed', __( 'Could not save this view.', 'talenttrack' ), 422 );
        }
        return RestResponse::success( self::shapeView( $row ) );
    }

    /** #2448 — delete one of the caller's own saved views. */
    public static function deleteView( WP_REST_Request $req ): \WP_REST_Response {
        $ok = ( new SavedViewsRepository() )->delete( (int) $req->get_param( 'id' ), get_current_user_id() );
        if ( ! $ok ) {
            return RestResponse::error( 'not_found', __( 'View not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'deleted' => true ] );
    }

    /** Accept the new `view_key`, falling back to the retired `report_key`. */
    private static function viewKey( WP_REST_Request $req ): string {
        $key = (string) ( $req->get_param( 'view_key' ) ?? '' );
        if ( $key === '' ) {
            $key = sanitize_key( (string) ( $req->get_param( 'report_key' ) ?? '' ) );
        }
        return $key;
    }

    /** @return array{id:int,name:string,filters:array<string,string>,is_default:bool} */
    private static function shapeView( object $row ): array {
        $filters = json_decode( (string) ( $row->filters_json ?? '' ), true );
        return [
            'id'         => (int) $row->id,
            'name'       => (string) $row->name,
            'filters'    => is_array( $filters ) ? $filters : [],
            'is_default' => ! empty( $row->is_default ),
        ];
    }

    /**
     * Sanitise the stored filter payload (#2448).
     *
     * #2385 whitelisted six hardcoded report params here. That cannot scale
     * to every FilterBar surface — a central list would have to know all of
     * their vocabularies, and on a surface it does not know about it silently
     * stores nothing. A saved view is instead treated as an opaque key/value
     * bag with structural limits only: the consuming view already sanitises
     * its own `$_GET` when the preset is re-applied, which is the layer that
     * actually knows what each param means.
     *
     * @param array<string,mixed> $raw
     * @return array<string,string>
     */
    private static function sanitizeFilterPayload( array $raw ): array {
        $out = [];
        foreach ( $raw as $key => $value ) {
            if ( count( $out ) >= self::MAX_KEYS ) break;

            $key = is_string( $key ) ? trim( $key ) : '';
            // Flat params plus FrontendListTable's `filter[<key>]` shape.
            if ( preg_match( '/^[a-z0-9_]+(\[[a-z0-9_]+\])?$/i', $key ) !== 1 ) continue;
            if ( ! is_scalar( $value ) ) continue;

            $val = sanitize_text_field( (string) $value );
            if ( $val === '' ) continue;
            if ( mb_strlen( $val ) > self::MAX_VALUE_LEN ) continue;

            $out[ $key ] = $val;
        }
        return $out;
    }
}
