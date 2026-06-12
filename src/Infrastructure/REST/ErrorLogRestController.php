<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\ErrorLogRepository;
use WP_REST_Request;

/**
 * ErrorLogRestController (#1360) — read-only paginated REST surface
 * for `tt_error_log`.
 *
 *   GET /system/errors    list with optional level/date filters;
 *                         paginated via X-WP-* headers
 *
 * Cap: `tt_view_audit_log` — same read-only operator log audience as
 * the audit log (see ErrorLogPage for the rationale). The wp-admin
 * `ErrorLogPage` renders through the same `ErrorLogRepository`, so a
 * future SaaS frontend gets identical answers.
 */
final class ErrorLogRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/system/errors', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list' ],
                'permission_callback' => self::permCan( 'tt_view_audit_log' ),
                'args'                => [
                    'level'     => [ 'sanitize_callback' => 'sanitize_key',        'required' => false ],
                    'date_from' => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'date_to'   => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'page'      => [ 'sanitize_callback' => 'absint', 'default' => 1 ],
                    'per_page'  => [ 'sanitize_callback' => 'absint', 'default' => 50 ],
                ],
            ],
        ] );
    }

    public static function list( WP_REST_Request $req ): \WP_REST_Response {
        $page     = max( 1, (int) $req->get_param( 'page' ) );
        $per_page = max( 1, min( 200, (int) $req->get_param( 'per_page' ) ) );

        $filters = [
            'level'     => (string) $req->get_param( 'level' ),
            'date_from' => (string) $req->get_param( 'date_from' ),
            'date_to'   => (string) $req->get_param( 'date_to' ),
            'limit'     => $per_page,
            'offset'    => ( $page - 1 ) * $per_page,
        ];

        $repo  = new ErrorLogRepository();
        $rows  = $repo->list( $filters );
        $total = $repo->count( $filters );

        $payload  = array_map( [ self::class, 'serialize' ], $rows );
        $response = RestResponse::success( $payload );
        $response->header( 'X-WP-Total',      (string) $total );
        $response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
        return $response;
    }

    /** @return array<string,mixed> */
    private static function serialize( object $row ): array {
        $context = (string) ( $row->context ?? '' );
        $decoded = $context !== '' ? json_decode( $context, true ) : null;
        return [
            'id'         => (int) $row->id,
            'level'      => (string) $row->level,
            'message'    => (string) $row->message,
            'context'    => is_array( $decoded ) ? $decoded : ( $context !== '' ? $context : null ),
            'created_at' => (string) $row->created_at,
        ];
    }
}
