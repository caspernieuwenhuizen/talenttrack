<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Request;

/**
 * BaseController — shared helpers for TalentTrack REST controllers.
 *
 * Intentionally thin. Controllers remain responsible for route registration
 * and business logic; this base only provides common utilities.
 *
 * Subclasses typically inherit:
 *   - permLoggedIn() / permCan()   for permission_callbacks
 *   - requireFields()              for quick validation
 *   - requirePositiveInt()         for ID validation
 */
abstract class BaseController {

    public const NS = 'talenttrack/v1';

    /**
     * Permission callback: any logged-in user.
     */
    public static function permLoggedIn(): bool {
        return is_user_logged_in();
    }

    /**
     * Permission callback factory: require a specific capability.
     *
     * @return callable(): bool
     */
    public static function permCan( string $capability ): callable {
        return static function () use ( $capability ): bool {
            return current_user_can( $capability );
        };
    }

    /**
     * Validate that the named fields are present (not null, not '').
     *
     * @param string[] $fields
     * @return array<int, array{code:string, message:string, details:array<string,mixed>}>
     *         Empty array when valid.
     */
    protected static function requireFields( WP_REST_Request $request, array $fields ): array {
        $errors = [];
        foreach ( $fields as $field ) {
            $val = $request[ $field ];
            if ( $val === null || $val === '' ) {
                $errors[] = [
                    'code'    => 'missing_field',
                    'message' => sprintf( 'Field "%s" is required.', $field ),
                    'details' => [ 'field' => $field ],
                ];
            }
        }
        return $errors;
    }

    /**
     * Validate that a value is a positive integer (>= 1).
     */
    protected static function isPositiveInt( $value ): bool {
        return is_numeric( $value ) && (int) $value >= 1;
    }
}
