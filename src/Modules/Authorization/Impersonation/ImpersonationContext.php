<?php
namespace TT\Modules\Authorization\Impersonation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ImpersonationContext (#0071 child 5) — query helper exposing the
 * "are we currently inside an impersonation session?" question.
 *
 * Most of the codebase doesn't need to know about impersonation:
 * `MatrixGate`, `PersonaResolver`, every REST controller, every render
 * call site reads `get_current_user_id()` and gets the impersonated
 * user's ID, which is exactly what's wanted.
 *
 * Three exceptions consume this helper:
 *   1. Audit log writers — switch to `effectiveActorId()` so the
 *      actual admin is recorded, not the impersonated user.
 *   2. Destructive admin handlers — call `denyIfImpersonating()` to
 *      block destructive operations from inside an impersonation
 *      session.
 *   3. Outbound notifications (push, email) — read `isImpersonating()`
 *      to suppress dispatches during the session.
 */
final class ImpersonationContext {

    public const COOKIE_NAME = 'tt_impersonator_id';

    public static function isImpersonating(): bool {
        return self::actorIdFromCookie() > 0;
    }

    /**
     * The actual admin's user id when an impersonation session is
     * active; otherwise the current user id.
     */
    public static function effectiveActorId(): int {
        $actor = self::actorIdFromCookie();
        if ( $actor > 0 ) return $actor;
        return (int) get_current_user_id();
    }

    /**
     * Returns a WP_Error when an impersonation session is active so a
     * destructive handler can short-circuit. Null otherwise.
     */
    public static function denyIfImpersonating( string $action = '' ): ?\WP_Error {
        if ( ! self::isImpersonating() ) return null;
        return new \WP_Error(
            'impersonation_blocks_destructive',
            sprintf(
                /* translators: %s: action name (e.g. matrix.apply) */
                __( 'Action %s is disabled while impersonating. Switch back to perform this operation.', 'talenttrack' ),
                $action !== '' ? $action : __( 'this', 'talenttrack' )
            ),
            [ 'status' => 403 ]
        );
    }

    /**
     * Convenience for admin-post handlers — short-circuits with a 403
     * `wp_die` when an impersonation session is active. Equivalent to
     * `if ($err = denyIfImpersonating(...)) wp_die(...)`.
     */
    public static function blockDestructiveAdminHandler( string $action = '' ): void {
        $err = self::denyIfImpersonating( $action );
        if ( $err instanceof \WP_Error ) {
            wp_die( esc_html( (string) $err->get_error_message() ), '', [ 'response' => 403 ] );
        }
    }

    /**
     * Read the signed actor id from the cookie. Returns 0 when no
     * cookie or signature mismatch.
     */
    public static function actorIdFromCookie(): int {
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) return 0;
        $raw = (string) $_COOKIE[ self::COOKIE_NAME ];
        $parts = explode( '|', $raw );
        if ( count( $parts ) !== 2 ) return 0;
        [ $id, $sig ] = $parts;
        $expected = wp_hash( 'tt_impersonator|' . $id );
        if ( ! hash_equals( $expected, $sig ) ) return 0;
        return (int) $id;
    }

    public static function setCookie( int $actor_id ): void {
        $sig    = wp_hash( 'tt_impersonator|' . $actor_id );
        $value  = $actor_id . '|' . $sig;
        $secure = is_ssl();
        setcookie( self::COOKIE_NAME, $value, [
            'expires'  => 0, // session cookie
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
        $_COOKIE[ self::COOKIE_NAME ] = $value;
    }

    public static function clearCookie(): void {
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            unset( $_COOKIE[ self::COOKIE_NAME ] );
        }
        setcookie( self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }
}
