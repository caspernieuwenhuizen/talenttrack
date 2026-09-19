<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_Error;
use WP_REST_Request;

/**
 * CoreParamErrors — core's argument errors, answered in the house envelope
 * (#3689).
 *
 * A route that declares `'required' => true` or a `type` has WordPress core
 * refuse a bad request before the callback runs, and core answers in its own
 * shape (`{code, message, data: {status, params}}`). A route that checks by
 * hand answers in the TalentTrack envelope (`RestResponse`). A client had to
 * handle both for the same mistake. This translates core's two codes, on our
 * own namespace only:
 *
 *   rest_missing_callback_param  ->  400 missing_fields  (details.fields)
 *   rest_invalid_param           ->  400 invalid_field   (details.fields,
 *                                                         details.reasons)
 *
 * Hooked on `rest_request_after_callbacks`, not `..._before_callbacks`.
 * Core validates params before either filter; if the before-filter turned
 * the WP_Error into a response, core would no longer see an error and would
 * go on to run the permission check and the callback with the invalid
 * request. After the callbacks, the error is still an error (core skipped
 * the callback because of it) and only its shape changes.
 */
final class CoreParamErrors {

    public static function init(): void {
        add_filter( 'rest_request_after_callbacks', [ self::class, 'translate' ], 10, 3 );
    }

    /**
     * @param mixed $response
     * @param mixed $handler
     * @param mixed $request
     * @return mixed
     */
    public static function translate( $response, $handler, $request ) {
        if ( ! $response instanceof WP_Error || ! $request instanceof WP_REST_Request ) return $response;
        if ( strpos( (string) $request->get_route(), '/' . BaseController::NS . '/' ) !== 0 ) return $response;

        $code = $response->get_error_code();
        $data = $response->get_error_data();
        $data = is_array( $data ) ? $data : [];

        if ( $code === 'rest_missing_callback_param' ) {
            $fields = [];
            foreach ( (array) ( $data['params'] ?? [] ) as $param ) {
                if ( is_scalar( $param ) ) $fields[] = (string) $param;
            }
            return RestResponse::error(
                'missing_fields',
                sprintf(
                    /* translators: %s: comma-separated field names */
                    __( 'These fields are required: %s.', 'talenttrack' ),
                    implode( ', ', $fields )
                ),
                400,
                [ 'fields' => $fields ]
            );
        }

        if ( $code === 'rest_invalid_param' ) {
            $reasons = [];
            foreach ( (array) ( $data['params'] ?? [] ) as $key => $message ) {
                $reasons[ (string) $key ] = is_scalar( $message ) ? (string) $message : '';
            }
            $fields = array_keys( $reasons );
            return RestResponse::error(
                'invalid_field',
                sprintf(
                    /* translators: %s: comma-separated field names */
                    __( 'These fields have a value this request cannot use: %s.', 'talenttrack' ),
                    implode( ', ', $fields )
                ),
                400,
                [ 'fields' => $fields, 'reasons' => (object) $reasons ]
            );
        }

        return $response;
    }
}
