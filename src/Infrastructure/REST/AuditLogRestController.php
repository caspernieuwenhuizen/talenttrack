<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Request;

/**
 * AuditLogRestController (#0052 PR-B) — read-only paginated REST surface
 * for `tt_audit_log`.
 *
 *   GET /audit-log    list with optional filters; paginated via X-WP-* headers
 *
 * Cap: `tt_view_audit_log` (defined by the audit module's installer).
 * The PHP-side `FrontendAuditLogView` keeps its own query layer for the
 * server-rendered surface; this controller exists so a future SaaS
 * frontend can render the same data without rebuilding the query.
 */
final class AuditLogRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/audit-log', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list' ],
                'permission_callback' => self::permCan( 'tt_view_audit_log' ),
                'args'                => [
                    'entity_type' => [ 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'entity_id'   => [ 'sanitize_callback' => 'absint',       'required' => false ],
                    'user_id'     => [ 'sanitize_callback' => 'absint',       'required' => false ],
                    'action'      => [ 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'date_from'   => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'date_to'     => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'page'        => [ 'sanitize_callback' => 'absint', 'default' => 1 ],
                    'per_page'    => [ 'sanitize_callback' => 'absint', 'default' => 50 ],
                ],
            ],
        ] );
    }

    public static function list( WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_audit_log';

        [ $where, $args ] = self::buildWhere( $req );
        $page     = max( 1, (int) $req->get_param( 'page' ) );
        $per_page = max( 1, min( 200, (int) $req->get_param( 'per_page' ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table}{$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( empty( $args ) ? $count_sql : $wpdb->prepare( $count_sql, $args ) );

        $list_sql = "SELECT id, user_id, action, entity_type, entity_id, payload, ip_address, created_at
                       FROM {$table}{$where}
                       ORDER BY created_at DESC, id DESC
                       LIMIT %d OFFSET %d";
        $list_args = array_merge( $args, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ) );

        $payload = array_map( [ self::class, 'serialize' ], is_array( $rows ) ? $rows : [] );
        $response = RestResponse::success( $payload );
        $response->header( 'X-WP-Total',      (string) $total );
        $response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
        return $response;
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function buildWhere( WP_REST_Request $req ): array {
        $clauses = [];
        $args    = [];

        if ( $req->has_param( 'entity_type' ) && (string) $req->get_param( 'entity_type' ) !== '' ) {
            $clauses[] = 'entity_type = %s';
            $args[]    = (string) $req->get_param( 'entity_type' );
        }
        if ( $req->has_param( 'entity_id' ) && (int) $req->get_param( 'entity_id' ) > 0 ) {
            $clauses[] = 'entity_id = %d';
            $args[]    = (int) $req->get_param( 'entity_id' );
        }
        if ( $req->has_param( 'user_id' ) && (int) $req->get_param( 'user_id' ) > 0 ) {
            $clauses[] = 'user_id = %d';
            $args[]    = (int) $req->get_param( 'user_id' );
        }
        if ( $req->has_param( 'action' ) && (string) $req->get_param( 'action' ) !== '' ) {
            $clauses[] = 'action = %s';
            $args[]    = (string) $req->get_param( 'action' );
        }
        if ( $req->has_param( 'date_from' ) && (string) $req->get_param( 'date_from' ) !== '' ) {
            $clauses[] = 'created_at >= %s';
            $args[]    = (string) $req->get_param( 'date_from' );
        }
        if ( $req->has_param( 'date_to' ) && (string) $req->get_param( 'date_to' ) !== '' ) {
            $clauses[] = 'created_at <= %s';
            $args[]    = (string) $req->get_param( 'date_to' );
        }

        if ( empty( $clauses ) ) return [ '', [] ];
        return [ ' WHERE ' . implode( ' AND ', $clauses ), $args ];
    }

    /** @return array<string,mixed> */
    private static function serialize( object $row ): array {
        $payload = (string) ( $row->payload ?? '' );
        $decoded = $payload !== '' ? json_decode( $payload, true ) : null;
        return [
            'id'          => (int) $row->id,
            'user_id'     => (int) $row->user_id,
            'action'      => (string) $row->action,
            'entity_type' => (string) $row->entity_type,
            'entity_id'   => (int) $row->entity_id,
            'payload'     => is_array( $decoded ) ? $decoded : $payload,
            'ip_address'  => (string) $row->ip_address,
            'created_at'  => (string) $row->created_at,
        ];
    }
}
