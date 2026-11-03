<?php
namespace TT\Modules\Mfa\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Mfa\Settings\MfaPolicyService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * MfaPolicyRestController (#4707) — `GET` / `PUT /settings/mfa-policy`,
 * the academy-wide rule for which personas must enrol a second factor.
 *
 * Gated on `tt_edit_settings`, the right the wp-admin operator form asks.
 * The write goes through `MfaPolicyService::save()`, the same path as that
 * form, so it stores the same value and writes the same audit row.
 */
final class MfaPolicyRestController extends BaseController {

    public const CAP = 'tt_edit_settings';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::NS, '/settings/mfa-policy', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'show' ],
                'permission_callback' => self::permCan( self::CAP ),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update' ],
                'permission_callback' => self::permCan( self::CAP ),
                'args'                => [
                    'personas' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => [ 'type' => 'string' ],
                    ],
                ],
            ],
        ] );
    }

    public static function show( WP_REST_Request $request ): WP_REST_Response {
        return RestResponse::success( [ 'personas' => ( new MfaPolicyService() )->overview() ] );
    }

    public static function update( WP_REST_Request $request ): WP_REST_Response {
        $raw     = $request->get_param( 'personas' );
        $service = new MfaPolicyService();
        $service->save( is_array( $raw ) ? $raw : [], get_current_user_id() );
        return RestResponse::success( [ 'personas' => $service->overview() ] );
    }
}
