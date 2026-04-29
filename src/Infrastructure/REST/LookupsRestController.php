<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use WP_REST_Request;

/**
 * LookupsRestController (#0052 PR-B) — REST surface for tt_lookups.
 *
 *   GET    /lookups                     paginated list of lookup types
 *   GET    /lookups/{type}              values for a type, sorted by sort_order
 *   POST   /lookups/{type}              create a value
 *   PUT    /lookups/{type}/{id}         update name / description / sort_order / meta
 *   DELETE /lookups/{type}/{id}         hard-delete
 *
 * Reads are open to logged-in users (`tt_view_settings`) so admin pickers
 * can pre-populate; writes require `tt_edit_settings`. The PHP-side
 * `QueryHelpers::get_lookups()` stays the canonical reader for the
 * plugin's own surfaces — this controller exists for future SaaS clients.
 */
final class LookupsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/lookups', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'listTypes' ],
                'permission_callback' => self::permCan( 'tt_view_settings' ),
            ],
        ] );

        register_rest_route( self::NS, '/lookups/(?P<type>[a-z0-9_]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'listValues' ],
                'permission_callback' => self::permCan( 'tt_view_settings' ),
                'args'                => self::typeArg(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'createValue' ],
                'permission_callback' => self::permCan( 'tt_edit_settings' ),
                'args'                => self::typeArg(),
            ],
        ] );

        register_rest_route( self::NS, '/lookups/(?P<type>[a-z0-9_]+)/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'updateValue' ],
                'permission_callback' => self::permCan( 'tt_edit_settings' ),
                'args'                => self::typeArg() + self::idArg(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'deleteValue' ],
                'permission_callback' => self::permCan( 'tt_edit_settings' ),
                'args'                => self::typeArg() + self::idArg(),
            ],
        ] );
    }

    /** @return array<string,array<string,mixed>> */
    private static function typeArg(): array {
        return [
            'type' => [
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => static fn( $v ): bool => is_string( $v ) && $v !== '',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function idArg(): array {
        return [
            'id' => [
                'sanitize_callback' => 'absint',
                'validate_callback' => [ self::class, 'isPositiveInt' ],
            ],
        ];
    }

    public static function listTypes( WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT lookup_type, COUNT(*) AS n FROM {$wpdb->prefix}tt_lookups GROUP BY lookup_type ORDER BY lookup_type"
        );
        $types = array_map( static fn( $r ): array => [
            'type'  => (string) $r->lookup_type,
            'count' => (int) $r->n,
        ], is_array( $rows ) ? $rows : [] );
        return RestResponse::success( $types );
    }

    public static function listValues( WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $type = (string) $req->get_param( 'type' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, description, meta, sort_order
               FROM {$wpdb->prefix}tt_lookups
              WHERE lookup_type = %s
              ORDER BY sort_order ASC, name ASC",
            $type
        ) );
        return RestResponse::success( array_map( [ self::class, 'serialize' ], is_array( $rows ) ? $rows : [] ) );
    }

    public static function createValue( WP_REST_Request $req ): \WP_REST_Response {
        $errors = self::requireFields( $req, [ 'name' ] );
        if ( ! empty( $errors ) ) return RestResponse::errors( $errors, 400 );

        global $wpdb;
        $row = self::buildRow( $req, true );
        $row['lookup_type'] = (string) $req->get_param( 'type' );
        $row['created_at']  = current_time( 'mysql' );
        $row['updated_at']  = current_time( 'mysql' );
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_lookups', $row );
        if ( ! $ok ) {
            return RestResponse::error( 'lookup_create_failed', __( 'Could not create lookup value.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => (int) $wpdb->insert_id ] + self::serialize( (object) $row ), 201 );
    }

    public static function updateValue( WP_REST_Request $req ): \WP_REST_Response {
        $type = (string) $req->get_param( 'type' );
        $id   = (int) $req->get_param( 'id' );
        global $wpdb;
        $row = self::buildRow( $req, false );
        if ( empty( $row ) ) {
            return RestResponse::error( 'lookup_no_changes', __( 'No fields to update.', 'talenttrack' ), 400 );
        }
        $row['updated_at'] = current_time( 'mysql' );
        $ok = $wpdb->update( $wpdb->prefix . 'tt_lookups', $row, [ 'id' => $id, 'lookup_type' => $type ] );
        if ( $ok === false ) {
            return RestResponse::error( 'lookup_update_failed', __( 'Could not update lookup value.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id, 'updated' => (int) $ok ] );
    }

    public static function deleteValue( WP_REST_Request $req ): \WP_REST_Response {
        $type = (string) $req->get_param( 'type' );
        $id   = (int) $req->get_param( 'id' );
        global $wpdb;
        $ok = $wpdb->delete( $wpdb->prefix . 'tt_lookups', [ 'id' => $id, 'lookup_type' => $type ] );
        if ( $ok === false ) {
            return RestResponse::error( 'lookup_delete_failed', __( 'Could not delete lookup value.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'deleted' => (int) $ok ] );
    }

    /** @return array<string,mixed> */
    private static function buildRow( WP_REST_Request $req, bool $require_name ): array {
        $row = [];
        if ( $req->has_param( 'name' ) || $require_name ) {
            $name = sanitize_text_field( (string) ( $req->get_param( 'name' ) ?? '' ) );
            if ( $name !== '' ) $row['name'] = $name;
        }
        if ( $req->has_param( 'description' ) ) {
            $row['description'] = wp_kses_post( (string) $req->get_param( 'description' ) );
        }
        if ( $req->has_param( 'meta' ) ) {
            $meta = $req->get_param( 'meta' );
            $row['meta'] = is_string( $meta ) ? $meta : wp_json_encode( $meta );
        }
        if ( $req->has_param( 'sort_order' ) ) {
            $row['sort_order'] = (int) $req->get_param( 'sort_order' );
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private static function serialize( object $row ): array {
        return [
            'id'          => (int) ( $row->id ?? 0 ),
            'name'        => (string) ( $row->name ?? '' ),
            'description' => (string) ( $row->description ?? '' ),
            'meta'        => self::decodeMeta( (string) ( $row->meta ?? '' ) ),
            'sort_order'  => (int) ( $row->sort_order ?? 0 ),
        ];
    }

    /**
     * Lookups historically store JSON or freeform text in `meta`. Try
     * JSON first; fall back to the raw string.
     *
     * @return array<string,mixed>|string
     */
    private static function decodeMeta( string $meta ) {
        if ( $meta === '' ) return [];
        $decoded = json_decode( $meta, true );
        return is_array( $decoded ) ? $decoded : $meta;
    }
}
