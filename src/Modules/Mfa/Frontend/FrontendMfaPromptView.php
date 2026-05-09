<?php
namespace TT\Modules\Mfa\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Modules\Mfa\Auth\RateLimiter;
use TT\Modules\Mfa\Auth\RememberDeviceCookie;
use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendMfaPromptView — the post-login MFA challenge page
 * (#0086 Workstream B Child 1, sprint 3).
 *
 * Reachable at `?tt_view=mfa-prompt`. The login guard
 * (`MfaLoginGuard`) sets the `tt_mfa_pending_<user_id>` transient
 * after a successful WP password check + cookie issuance, and
 * redirects all subsequent requests here until the user verifies a
 * fresh TOTP code (or a backup code).
 *
 * GET renders the form (or the lockout screen). POST validates the
 * submitted code against `TotpService::verify()` first, then falls
 * back to `BackupCodesService::verify()` if a separate "use a backup
 * code instead" mode is on.
 *
 * Successful verify:
 *   - Clears the pending transient (the guard's middleware stops
 *     redirecting on the next request).
 *   - Optionally issues a 30-day "remember this device" cookie.
 *   - Audit-logs `mfa.verified` (or `mfa.backup_code_used` for
 *     backup codes).
 *   - Redirects the user back to the dashboard / their original
 *     destination.
 *
 * Failed verify:
 *   - `RateLimiter::recordFailure()` increments the counter and
 *     audit-logs `mfa.verify_failed`. On the threshold the limiter
 *     also writes `mfa.lockout`.
 *   - Re-renders the form with a generic error (not "wrong code"
 *     vs "backup code didn't match" — same string for both, no
 *     side-channel for guessing).
 *
 * Lockout state (`RateLimiter::isLockedOut()`):
 *   - Renders a countdown screen instead of the input. No form,
 *     so the user can't accumulate further failures during the
 *     window.
 */
class FrontendMfaPromptView extends FrontendViewBase {

    public const SLUG = 'mfa-prompt';

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Two-factor authentication', 'talenttrack' );

        if ( $user_id <= 0 ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You must be logged in to verify a code.', 'talenttrack' ) . '</p>';
            return;
        }

        $repo    = new MfaSecretsRepository();
        $limiter = new RateLimiter();
        $row     = $repo->findByUserId( $user_id );

        // Defensive: if the user is here but not enrolled, the guard
        // shouldn't have redirected — it would have sent them to the
        // enrollment wizard instead. Treat as "no challenge needed",
        // clear the transient, send them to the dashboard.
        if ( $row === null || empty( $row['enrolled_at'] ) || empty( $row['secret'] ) ) {
            MfaLoginGuard::clearPending( $user_id );
            wp_safe_redirect( WizardEntryPoint::dashboardBaseUrl() );
            exit;
        }

        // Lockout screen.
        if ( $limiter->isLockedOut( $user_id ) ) {
            self::renderLockout( $limiter->lockoutSecondsRemaining( $user_id ) );
            return;
        }

        $error = null;
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST['tt_mfa_prompt_nonce'] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_mfa_prompt_nonce'] ) ), 'tt_mfa_prompt_' . $user_id )
        ) {
            $error = self::handleSubmit( $user_id, $repo, $limiter );
        }

        self::renderForm( $user_id, $error );
    }

    /**
     * Process the POSTed code. On success, redirects (and exits). On
     * failure, returns the error string for re-render.
     */
    private static function handleSubmit( int $user_id, MfaSecretsRepository $repo, RateLimiter $limiter ): ?string {
        $row     = $repo->findByUserId( $user_id );
        if ( $row === null || empty( $row['secret'] ) ) {
            return __( 'Your MFA setup is incomplete. Sign out and back in, then re-enroll if needed.', 'talenttrack' );
        }

        $mode    = isset( $_POST['mode'] ) ? sanitize_key( (string) $_POST['mode'] ) : 'totp';
        $raw     = isset( $_POST['code'] ) ? (string) wp_unslash( (string) $_POST['code'] ) : '';
        $remember = ! empty( $_POST['remember_device'] );

        if ( $mode === 'backup' ) {
            $idx = BackupCodesService::verify( $raw, (array) ( $row['backup_codes'] ?? [] ) );
            if ( $idx === -1 ) {
                $limiter->recordFailure( $user_id );
                return __( "That code didn't match.", 'talenttrack' );
            }
            $updated = BackupCodesService::markUsed( (array) ( $row['backup_codes'] ?? [] ), $idx );
            $repo->updateBackupCodes( $user_id, $updated );
            $limiter->recordSuccess( $user_id );
            MfaAuditEvents::record( MfaAuditEvents::BACKUP_CODE_USED, $user_id, [
                'codes_remaining' => BackupCodesService::unusedCount( $updated ),
            ] );
        } else {
            $stripped = preg_replace( '/\s+/', '', $raw );
            if ( $stripped === null || ! ctype_digit( $stripped ) || strlen( $stripped ) !== 6 ) {
                // Format-only error doesn't count against the rate limit
                // (no information leaked) — but to keep the policy simple
                // and resistant to enumeration, we still record it.
                $limiter->recordFailure( $user_id );
                return __( 'The code is six digits. Try again with the current code from your authenticator app.', 'talenttrack' );
            }
            if ( ! TotpService::verify( (string) $row['secret'], $stripped ) ) {
                $limiter->recordFailure( $user_id );
                return __( "That code didn't match. Make sure your phone's clock is correct, then try the current code from your authenticator.", 'talenttrack' );
            }
            $limiter->recordSuccess( $user_id );
        }

        MfaLoginGuard::clearPending( $user_id );
        if ( $remember ) {
            RememberDeviceCookie::setForUser( $user_id );
        }

        $redirect = self::resolvePostVerifyRedirect();
        wp_safe_redirect( $redirect );
        exit;
    }

    private static function renderForm( int $user_id, ?string $error ): void {
        $repo  = new MfaSecretsRepository();
        $row   = $repo->findByUserId( $user_id );
        $unused_backup = BackupCodesService::unusedCount( (array) ( $row['backup_codes'] ?? [] ) );
        $mode  = isset( $_GET['mode'] ) && (string) $_GET['mode'] === 'backup' ? 'backup' : 'totp';

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Two-factor authentication', 'talenttrack' ) );
        self::renderHeader( __( 'Two-factor authentication', 'talenttrack' ) );

        echo '<div style="max-width:480px; margin:0 auto;">';
        echo '<p>'
            . esc_html__( 'For your security, sign-in needs a second factor on your account.', 'talenttrack' )
            . '</p>';

        if ( $error ) {
            echo '<div class="tt-notice tt-notice-error" role="alert" style="margin-bottom:16px;">'
                . esc_html( $error )
                . '</div>';
        }

        echo '<form method="post" action="">';
        wp_nonce_field( 'tt_mfa_prompt_' . $user_id, 'tt_mfa_prompt_nonce' );
        echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';

        if ( $mode === 'backup' ) {
            echo '<label for="tt-mfa-prompt-code" style="display:block; font-weight:600; margin-bottom:6px;">'
                . esc_html__( 'Backup code', 'talenttrack' )
                . '</label>';
            echo '<input type="text" id="tt-mfa-prompt-code" name="code" required autocomplete="off" '
                . 'maxlength="20" autofocus '
                . 'placeholder="XXXX-XXXX-XXXX" '
                . 'style="font-size:18px; letter-spacing:2px; padding:8px 12px; width:100%;">';
            echo '<p style="font-size:13px; color:#5b6e75; margin-top:6px;">'
                . esc_html__( 'Each backup code works once. Dashes optional.', 'talenttrack' )
                . '</p>';
        } else {
            echo '<label for="tt-mfa-prompt-code" style="display:block; font-weight:600; margin-bottom:6px;">'
                . esc_html__( 'Code from your authenticator app', 'talenttrack' )
                . '</label>';
            echo '<input type="text" id="tt-mfa-prompt-code" name="code" required '
                . 'autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]*" '
                . 'maxlength="11" autofocus '
                . 'placeholder="000 000" '
                . 'style="font-size:20px; letter-spacing:4px; padding:8px 12px; width:100%;">';
        }

        echo '<label style="display:flex; gap:8px; align-items:center; margin-top:16px; cursor:pointer;">';
        echo '<input type="checkbox" name="remember_device" value="1">';
        echo '<span>' . esc_html__( "Remember this device for 30 days. Skip the code on this browser next time.", 'talenttrack' ) . '</span>';
        echo '</label>';

        echo '<div style="margin-top:24px;">';
        echo '<button type="submit" class="tt-button tt-button-primary" style="width:100%; padding:12px;">'
            . esc_html__( 'Verify', 'talenttrack' )
            . '</button>';
        echo '</div>';
        echo '</form>';

        // Mode toggle.
        $base_url = remove_query_arg( [ 'mode' ] );
        if ( $mode === 'totp' ) {
            $url = add_query_arg( 'mode', 'backup', $base_url );
            echo '<p style="margin-top:24px; text-align:center;">';
            echo '<a href="' . esc_url( $url ) . '">'
                . esc_html__( "Use a backup code instead", 'talenttrack' )
                . '</a>';
            if ( $unused_backup > 0 ) {
                echo ' <span style="color:#5b6e75;">('
                    . esc_html(
                        sprintf(
                            /* translators: %d unused backup codes */
                            _n( '%d code remaining', '%d codes remaining', $unused_backup, 'talenttrack' ),
                            $unused_backup
                        )
                    )
                    . ')</span>';
            }
            echo '</p>';
        } else {
            $url = remove_query_arg( 'mode', $base_url );
            echo '<p style="margin-top:24px; text-align:center;">';
            echo '<a href="' . esc_url( $url ) . '">'
                . esc_html__( 'Use the authenticator app code instead', 'talenttrack' )
                . '</a>';
            echo '</p>';
        }

        echo '<p style="margin-top:32px; font-size:13px; color:#5b6e75; text-align:center;">'
            . esc_html__( "Lost both your phone and your backup codes? Ask your academy admin to reset MFA on your account.", 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    private static function renderLockout( int $seconds_remaining ): void {
        $minutes = max( 1, (int) ceil( $seconds_remaining / 60 ) );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Two-factor authentication', 'talenttrack' ) );
        self::renderHeader( __( 'Two-factor authentication', 'talenttrack' ) );
        echo '<div style="max-width:480px; margin:0 auto;">';
        echo '<div class="tt-notice tt-notice-error" role="alert" style="padding:16px;">';
        echo '<p style="margin:0 0 8px;"><strong>'
            . esc_html__( 'Too many failed attempts.', 'talenttrack' )
            . '</strong></p>';
        echo '<p style="margin:0;">' . esc_html(
            sprintf(
                /* translators: %d minutes */
                _n( 'Try again in %d minute.', 'Try again in %d minutes.', $minutes, 'talenttrack' ),
                $minutes
            )
        ) . '</p>';
        echo '</div>';
        echo '<p style="margin-top:24px; font-size:13px; color:#5b6e75;">'
            . esc_html__( "If you never see a working code, ask your academy admin to reset MFA on your account.", 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    /**
     * Where to send the user after a successful verification. Prefers
     * the original destination they were trying to reach (stashed in the
     * `tt_mfa_post_verify_url_<user_id>` transient by the guard); falls
     * back to the dashboard.
     */
    private static function resolvePostVerifyRedirect(): string {
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            $stash_key = 'tt_mfa_post_verify_url_' . $user_id;
            $stashed   = get_transient( $stash_key );
            if ( is_string( $stashed ) && $stashed !== '' ) {
                delete_transient( $stash_key );
                $valid = wp_validate_redirect( $stashed, '' );
                if ( $valid !== '' ) return $valid;
            }
        }
        return WizardEntryPoint::dashboardBaseUrl();
    }
}
