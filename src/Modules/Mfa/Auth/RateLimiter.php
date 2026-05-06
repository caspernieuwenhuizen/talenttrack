<?php
namespace TT\Modules\Mfa\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Settings\MfaSettings;

/**
 * RateLimiter — wraps `MfaSecretsRepository`'s failure tracking with the
 * lockout policy from `MfaSettings` (#0086 Workstream B Child 1, sprint 3).
 *
 * Policy by default:
 *   - 5 failed verifications in a row → 15-minute lockout.
 *   - On the 5th failure, an audit-log entry `mfa.lockout` is written.
 *   - A successful verify resets the counter via `MfaSecretsRepository::recordVerification()`.
 *
 * The counter is monotonic across the attempt window — i.e. there's no
 * sliding 5-minute "attempts in the last X" check. Once `failed_attempts`
 * hits the threshold, the row is locked until `locked_until > NOW()`.
 * Justification: TOTP codes are unguessable in 5 attempts (1 in 1M for a
 * 6-digit code; 5 attempts brings the chance to 5/1,000,000); the lockout
 * exists primarily to slow down credential-stuffing, not to fence guessable
 * input. A simple cumulative counter is easier to reason about than a
 * sliding window.
 */
final class RateLimiter {

    /** @var MfaSecretsRepository */
    private $repo;

    /** @var MfaSettings */
    private $settings;

    public function __construct( ?MfaSecretsRepository $repo = null, ?MfaSettings $settings = null ) {
        $this->repo     = $repo ?? new MfaSecretsRepository();
        $this->settings = $settings ?? new MfaSettings();
    }

    /**
     * Returns the timestamp string when the user's lockout ends, or null
     * if the user is not currently locked out.
     */
    public function lockedUntil( int $wp_user_id ): ?string {
        return $this->repo->lockoutUntil( $wp_user_id );
    }

    public function isLockedOut( int $wp_user_id ): bool {
        return $this->lockedUntil( $wp_user_id ) !== null;
    }

    /**
     * Seconds remaining on the lockout window, or 0 when not locked.
     */
    public function lockoutSecondsRemaining( int $wp_user_id ): int {
        $until = $this->lockedUntil( $wp_user_id );
        if ( $until === null ) return 0;
        $delta = strtotime( $until . ' UTC' ) - time();
        return max( 0, $delta );
    }

    /**
     * Record a failed verification. Returns the new failure count.
     * If the count reaches `MfaSettings::maxAttempts()`, also writes an
     * `mfa.lockout` audit-log entry.
     */
    public function recordFailure( int $wp_user_id ): int {
        $max     = $this->settings->maxAttempts();
        $minutes = $this->settings->lockoutMinutes();
        $count   = $this->repo->recordFailedAttempt( $wp_user_id, $max, $minutes );

        MfaAuditEvents::record( MfaAuditEvents::VERIFY_FAILED, $wp_user_id, [
            'attempt'      => $count,
            'max_attempts' => $max,
        ] );

        if ( $count >= $max ) {
            MfaAuditEvents::record( MfaAuditEvents::LOCKOUT, $wp_user_id, [
                'lockout_minutes' => $minutes,
                'locked_until'    => gmdate( 'Y-m-d H:i:s', time() + ( $minutes * MINUTE_IN_SECONDS ) ),
            ] );
        }
        return $count;
    }

    /**
     * Record a successful verification. Resets `failed_attempts` and
     * clears `locked_until` via the existing repository method.
     */
    public function recordSuccess( int $wp_user_id ): void {
        $this->repo->recordVerification( $wp_user_id );
        MfaAuditEvents::record( MfaAuditEvents::VERIFIED, $wp_user_id );
    }
}
