<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Request;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Impersonation\ImpersonationLogRepository;

/**
 * ImpersonationRestController (#2861) — the reader the docs already promised.
 *
 *   GET /impersonation/log — who impersonated whom, when, from where, why.
 *
 * `docs/impersonation.md` told academy admins this endpoint existed and it
 * did not; the log has been written since migration 0056 with nothing able
 * to read it back. The audit trail is the whole reason impersonation is
 * acceptable on records belonging to minors, so a trail that cannot be
 * read is not a control.
 *
 * Gated on the `impersonation_log` matrix entity, which was already in
 * `MatrixEntityCatalog` waiting for a surface — this is a read over an
 * existing entity, not new authorization work.
 */
final class ImpersonationRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/impersonation/log', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'log' ],
                'permission_callback' => [ __CLASS__, 'canRead' ],
                'args'                => [
                    'actor_user_id'  => [ 'sanitize_callback' => 'absint',              'required' => false ],
                    'target_user_id' => [ 'sanitize_callback' => 'absint',              'required' => false ],
                    'date_from'      => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'date_to'        => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'active_only'    => [ 'type' => 'boolean', 'default' => false ],
                    'limit'          => [ 'sanitize_callback' => 'absint',              'required' => false ],
                    'offset'         => [ 'sanitize_callback' => 'absint',              'required' => false ],
                ],
            ],
        ] );
    }

    public static function canRead(): bool {
        return MatrixGate::canAnyScope( get_current_user_id(), 'impersonation_log', 'read' );
    }

    public static function log( WP_REST_Request $req ): \WP_REST_Response {
        $filters = [
            'actor_user_id'  => (int) $req->get_param( 'actor_user_id' ),
            'target_user_id' => (int) $req->get_param( 'target_user_id' ),
            'date_from'      => self::date( (string) $req->get_param( 'date_from' ) ),
            'date_to'        => self::date( (string) $req->get_param( 'date_to' ) ),
            'active_only'    => (bool) $req->get_param( 'active_only' ),
            'limit'          => (int) $req->get_param( 'limit' ) ?: 50,
            'offset'         => (int) $req->get_param( 'offset' ),
        ];

        $repo = new ImpersonationLogRepository();

        return RestResponse::success( [
            'sessions' => $repo->recent( $filters ),
            'total'    => $repo->count( $filters ),
        ] );
    }

    /** A blank or malformed date is no filter rather than a 400. */
    private static function date( string $raw ): string {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
    }
}
