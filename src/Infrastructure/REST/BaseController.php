<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Request;
use WP_REST_Response;

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
 *   - checkBody()                  for the write-route body contract
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
     * Permission callback factory: require a capability AND that a
     * sub-feature is switched on (#1485). Used to gate the REST surface
     * of a feature that shares its capability with a sibling surface
     * (e.g. team chemistry shares `tt_view_team_chemistry` with the
     * blueprint editor), where gating on the cap alone would take the
     * sibling down too.
     *
     * @return callable(): bool
     */
    public static function permCanFeature( string $capability, string $feature_key ): callable {
        return static function () use ( $capability, $feature_key ): bool {
            if ( ! current_user_can( $capability ) ) return false;
            if ( ! class_exists( '\\TT\\Core\\FeatureRegistry' ) ) return true;
            return \TT\Core\FeatureRegistry::isEnabled( $feature_key );
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

    /**
     * The body contract for a write route (#3689): refuse a key the route
     * does not declare, and a declared-required key sent empty.
     *
     * Reads the request body only. URL segments (`id`, `activity_id`) and
     * query parameters are never reported as unknown, because a caller does
     * not choose them the way it chooses body keys.
     *
     * Unknown keys are checked first and nothing is reported past the first
     * failing check, so a caller fixes the shape before the values. Core
     * refuses an absent required key before the callback runs; this catches
     * the present-but-empty one core lets through, and names every missing
     * key in one answer rather than one per round trip.
     *
     * Public static so a controller that does not extend this class (match
     * prep) can call it, the same way `isPositiveInt()` is shared.
     *
     * @param array<array-key,mixed> $args The route's declared `args`.
     * @return WP_REST_Response|null Null when the body is acceptable.
     */
    public static function checkBody( WP_REST_Request $r, array $args ): ?WP_REST_Response {
        // Core returns null for a body that is not JSON; the stubs say array,
        // so a falsy test rather than is_array().
        $body = $r->get_json_params();
        if ( ! $body ) $body = $r->get_body_params();

        $allowed = array_map( 'strval', array_keys( $args ) );
        $unknown = array_values( array_diff( array_map( 'strval', array_keys( $body ) ), $allowed ) );
        if ( $unknown !== [] ) {
            return RestResponse::error(
                'unknown_field',
                sprintf(
                    /* translators: %s: comma-separated field names */
                    __( 'This request does not accept: %s.', 'talenttrack' ),
                    implode( ', ', $unknown )
                ),
                400,
                [ 'fields' => $unknown, 'allowed' => $allowed ]
            );
        }

        $missing = [];
        foreach ( $args as $key => $spec ) {
            if ( ! is_array( $spec ) || empty( $spec['required'] ) ) continue;
            $key = (string) $key;
            // A required key that is not in the body may be a URL segment;
            // core has already refused it if it is absent altogether.
            $value = array_key_exists( $key, $body ) ? $body[ $key ] : $r->get_param( $key );
            if ( $value === null || $value === '' ) $missing[] = $key;
        }
        if ( $missing !== [] ) {
            return RestResponse::error(
                'missing_fields',
                sprintf(
                    /* translators: %s: comma-separated field names */
                    __( 'These fields are required: %s.', 'talenttrack' ),
                    implode( ', ', $missing )
                ),
                400,
                [ 'fields' => $missing ]
            );
        }

        return null;
    }
}
