<?php
namespace TT\Modules\Security\Hooks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;

/**
 * LoginFailedSubscriber (#0086 Workstream B Child 3) — bridges WordPress's
 * `wp_login_failed` action into the TalentTrack audit log so failed-login
 * attempts surface on the existing audit-log viewer's new "Failed logins"
 * tab.
 *
 * One row per failed attempt. Row carries the attempted username (NOT the
 * resolved user_id — for non-existent users that's empty), source IP, and
 * User-Agent. Source IP travels through `AuditService::record()`'s normal
 * `ip_address` column; username + UA ride in the `payload` JSON since they
 * are diagnostic, not identity.
 *
 * Privacy note: the failed-login attempt itself is the kind of data the
 * operator legitimately needs (brute-force detection). The audit-log
 * surface is gated on `tt_view_settings`, so only operators see this
 * data.
 */
final class LoginFailedSubscriber {

    public static function init(): void {
        add_action( 'wp_login_failed', [ self::class, 'onLoginFailed' ], 10, 2 );
    }

    /**
     * @param string                      $username Attempted username (may not match any WP user).
     * @param \WP_Error|null              $error    The auth error WordPress raised (optional; absent in older WP).
     */
    public static function onLoginFailed( string $username, $error = null ): void {
        // Resolve user_id when the attempted username matches a real
        // account, so the audit-log "User" filter on the viewer can
        // narrow to "failed logins against this user". A miss returns 0
        // — the row is still recorded, just without a linked user.
        $user_id = 0;
        $user    = null;
        if ( $username !== '' ) {
            $user = is_email( $username )
                ? get_user_by( 'email', $username )
                : get_user_by( 'login', $username );
            if ( $user instanceof \WP_User ) {
                $user_id = (int) $user->ID;
            }
        }

        $payload = [
            'username'    => substr( $username, 0, 255 ),
            'user_agent'  => self::userAgent(),
        ];

        // The WP_Error error code carries useful detail — `incorrect_password`,
        // `invalid_username`, `invalidcombo`, etc. — that we surface on the
        // operator tab so brute-force detection can distinguish "wrong pwd
        // on real account" (account-targeted attack) from "username not
        // found" (spray attack).
        if ( $error instanceof \WP_Error ) {
            $code = $error->get_error_code();
            if ( is_string( $code ) && $code !== '' ) {
                $payload['error_code'] = substr( $code, 0, 64 );
            }
        }

        try {
            /** @var AuditService $audit */
            $audit = Kernel::instance()->container()->get( 'audit' );
        } catch ( \Throwable $e ) {
            // Container not booted yet (extremely early in request lifecycle).
            // Failure here is silent — we never block a login flow on
            // audit-write hygiene.
            return;
        }

        $audit->record(
            'login_fail',
            'auth',
            $user_id,
            $payload
        );
    }

    private static function userAgent(): string {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ( ! is_string( $ua ) ) return '';
        return substr( $ua, 0, 255 );
    }
}
