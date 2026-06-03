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
                    'message' => sprintf(
                        /* translators: %s is the field name */
                        __( 'Field "%s" is required.', 'talenttrack' ),
                        $field
                    ),
                    'details' => [ 'field' => $field ],
                ];
            }
        }
        return $errors;
    }

    /**
     * Validate that a value is a positive integer (>= 1).
     *
     * #1057 — MUST be `public` because subclasses pass it to
     * `register_rest_route` as `'validate_callback' => [ self::class,
     * 'isPositiveInt' ]`. WP REST invokes the callback via PHP's
     * `call_user_func()` from outside the class hierarchy, which
     * cannot reach `protected` methods even from a subclass — the
     * dispatcher 500s with `cannot access protected method ...` when
     * the route arg validation runs. Four controllers depend on this
     * (LookupsRestController, InvitationsRestController,
     * LookupNormalisationRestController, PushSubscriptionsRestController);
     * all of them were broken on any path that took an `id` URL
     * segment until v4.15.5.
     */
    public static function isPositiveInt( $value ): bool {
        return is_numeric( $value ) && (int) $value >= 1;
    }
}
