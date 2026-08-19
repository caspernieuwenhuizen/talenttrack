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
use TT\Shared\Frontend\DashboardShortcode;
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
            self::openCard();
            echo '<p class="tt-login-subtitle">' . esc_html( $title ) . '</p>';
            echo '<p class="tt-login-help">' . esc_html__( 'You must be logged in to verify a code.', 'talenttrack' ) . '</p>';
            self::closeCard();
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

        // Resolve the destination before clearing — `clearPending()`
        // drops the stashed post-verify URL along with the challenge
        // flags (#2553), so reading it afterwards would always fall
        // back to the dashboard and lose a legitimate deep link.
        $redirect = self::resolvePostVerifyRedirect();

        MfaLoginGuard::clearPending( $user_id );
        if ( $remember ) {
            RememberDeviceCookie::setForUser( $user_id );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    private static function renderForm( int $user_id, ?string $error ): void {
        $repo  = new MfaSecretsRepository();
        $row   = $repo->findByUserId( $user_id );
        $unused_backup = BackupCodesService::unusedCount( (array) ( $row['backup_codes'] ?? [] ) );
        $mode  = isset( $_GET['mode'] ) && (string) $_GET['mode'] === 'backup' ? 'backup' : 'totp';

        self::openCard();
        echo '<p class="tt-login-subtitle">' . esc_html__( 'Two-factor authentication', 'talenttrack' ) . '</p>';
        echo '<p class="tt-login-help">'
            . esc_html__( 'For your security, sign-in needs a second factor on your account.', 'talenttrack' )
            . '</p>';

        if ( $error ) {
            echo '<div class="tt-login-error" role="alert">' . esc_html( $error ) . '</div>';
        }

        echo '<form method="post" action="" class="tt-login-form tt-mfa-form">';
        wp_nonce_field( 'tt_mfa_prompt_' . $user_id, 'tt_mfa_prompt_nonce' );
        echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';

        if ( $mode === 'backup' ) {
            echo '<label class="tt-mfa-label" for="tt-mfa-prompt-code">'
                . esc_html__( 'Backup code', 'talenttrack' )
                . '</label>';
            echo '<input type="text" id="tt-mfa-prompt-code" name="code" required autocomplete="off" '
                . 'maxlength="20" autofocus '
                . 'placeholder="XXXX-XXXX-XXXX" '
                . 'class="tt-mfa-code tt-mfa-code-backup">';
            echo '<p class="tt-mfa-hint">'
                . esc_html__( 'Each backup code works once. Dashes optional.', 'talenttrack' )
                . '</p>';
        } else {
            echo '<label class="tt-mfa-label" for="tt-mfa-prompt-code">'
                . esc_html__( 'Code from your authenticator app', 'talenttrack' )
                . '</label>';
            echo '<input type="text" id="tt-mfa-prompt-code" name="code" required '
                . 'autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]*" '
                . 'maxlength="11" autofocus '
                . 'placeholder="000 000" '
                . 'class="tt-mfa-code">';
        }

        echo '<label class="tt-checkbox-label tt-mfa-remember">';
        echo '<input type="checkbox" name="remember_device" value="1">';
        echo '<span>' . esc_html__( "Remember this device for 30 days. Skip the code on this browser next time.", 'talenttrack' ) . '</span>';
        echo '</label>';

        echo '<button type="submit" class="tt-btn tt-btn-primary tt-btn-block">'
            . esc_html__( 'Verify', 'talenttrack' )
            . '</button>';
        echo '</form>';

        // Mode toggle.
        $base_url = remove_query_arg( [ 'mode' ] );
        if ( $mode === 'totp' ) {
            $url = add_query_arg( 'mode', 'backup', $base_url );
            echo '<p class="tt-login-links">';
            echo '<a href="' . esc_url( $url ) . '">'
                . esc_html__( "Use a backup code instead", 'talenttrack' )
                . '</a>';
            if ( $unused_backup > 0 ) {
                echo ' <span class="tt-mfa-hint-inline">('
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
            echo '<p class="tt-login-links">';
            echo '<a href="' . esc_url( $url ) . '">'
                . esc_html__( 'Use the authenticator app code instead', 'talenttrack' )
                . '</a>';
            echo '</p>';
        }

        echo '<p class="tt-mfa-hint tt-mfa-hint-footer">'
            . esc_html__( "Lost both your phone and your backup codes? Ask your academy admin to reset MFA on your account.", 'talenttrack' )
            . '</p>';
        self::closeCard();
    }

    /**
     * Open the pre-authentication card (#2554). The challenge renders on
     * the same centred, branded chrome as the login form and the password
     * reset screens — no app shell, because the session has a cookie but
     * has not cleared its second factor and has no business painting the
     * navigation.
     */
    private static function openCard(): void {
        echo '<div class="tt-dashboard"><div class="tt-preauth"><div class="tt-login-card">';
        DashboardShortcode::renderPreAuthBrand();
    }

    private static function closeCard(): void {
        DashboardShortcode::renderPreAuthSignOut();
        echo '</div></div></div>';
    }

    private static function renderLockout( int $seconds_remaining ): void {
        $minutes = max( 1, (int) ceil( $seconds_remaining / 60 ) );
        self::openCard();
        echo '<p class="tt-login-subtitle">' . esc_html__( 'Two-factor authentication', 'talenttrack' ) . '</p>';
        echo '<div class="tt-login-error tt-mfa-lockout" role="alert">';
        echo '<p><strong>'
            . esc_html__( 'Too many failed attempts.', 'talenttrack' )
            . '</strong></p>';
        echo '<p>' . esc_html(
            sprintf(
                /* translators: %d minutes */
                _n( 'Try again in %d minute.', 'Try again in %d minutes.', $minutes, 'talenttrack' ),
                $minutes
            )
        ) . '</p>';
        echo '</div>';
        echo '<p class="tt-mfa-hint">'
            . esc_html__( "If you never see a working code, ask your academy admin to reset MFA on your account.", 'talenttrack' )
            . '</p>';
        self::closeCard();
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
                // #2553 — belt-and-braces. `onLogin()` no longer stashes a
                // challenge URL, but a stash written by an older build is
                // live for up to 15 minutes after the upgrade and would
                // otherwise send the user straight back to the prompt.
                if ( ! MfaLoginGuard::isChallengeUrl( $stashed ) ) {
                    $valid = wp_validate_redirect( $stashed, '' );
                    if ( $valid !== '' ) return $valid;
                }
            }
        }
        return WizardEntryPoint::dashboardBaseUrl();
    }
}
