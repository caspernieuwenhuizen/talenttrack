<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Response;

/**
 * RestResponse — factory for the standard TalentTrack REST envelope.
 *
 * Envelope contract (Sprint 0 spec):
 *
 *   Success:
 *     {
 *       "success": true,
 *       "data":    <payload>,
 *       "errors":  []
 *     }
 *
 *   Error:
 *     {
 *       "success": false,
 *       "data":    null,
 *       "errors":  [
 *         { "code": "<snake_case>", "message": "<human readable>", "details": {...} }
 *       ]
 *     }
 *
 * Error codes are domain-specific (e.g. "player_not_found", "invalid_rating")
 * so consuming apps can key translations/UI flows off them.
 *
 * All methods return a WP_REST_Response so HTTP status codes and headers
 * behave correctly when returned from a route callback.
 */
class RestResponse {

    /**
     * Build a success envelope.
     *
     * @param mixed $data   The payload. Can be array, scalar, object, or null.
     * @param int   $status HTTP status code (default 200).
     */
    public static function success( $data = null, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => true,
            'data'    => $data,
            'errors'  => [],
        ], $status );
    }

    /**
     * Build an error envelope with a single error entry.
     *
     * @param string              $code    Domain-specific snake_case code (e.g. "player_not_found").
     * @param string              $message Human-readable message.
     * @param int                 $status  HTTP status code (default 400).
     * @param array<string,mixed> $details Optional extra context (e.g. field-level validation failures).
     */
    public static function error( string $code, string $message, int $status = 400, array $details = [] ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => false,
            'data'    => null,
            'errors'  => [ [
                'code'    => $code,
                'message' => $message,
                'details' => (object) $details, // cast to object so empty details serialize as {} not []
            ] ],
        ], $status );
    }

    /**
     * Build an error envelope with multiple error entries (e.g. validation errors
     * across several fields).
     *
     * @param array<int, array{code:string, message:string, details?:array<string,mixed>}> $errors
     * @param int $status HTTP status code (default 400).
     */
    public static function errors( array $errors, int $status = 400 ): WP_REST_Response {
        $normalized = [];
        foreach ( $errors as $e ) {
            $normalized[] = [
                'code'    => (string) ( $e['code'] ?? 'error' ),
                'message' => (string) ( $e['message'] ?? '' ),
                'details' => (object) ( $e['details'] ?? [] ),
            ];
        }
        return new WP_REST_Response( [
            'success' => false,
            'data'    => null,
            'errors'  => $normalized,
        ], $status );
    }
}
