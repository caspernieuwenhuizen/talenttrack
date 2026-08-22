<?php
namespace TT\Modules\Mfa\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Auth\MfaChallengeHandler;
use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Modules\Mfa\Auth\RateLimiter;
use TT\Modules\Mfa\Domain\BackupCodesService;
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
 * This class renders and nothing else (#2668). The submitted code is
 * validated by `MfaChallengeHandler` on `init`, before a byte of the
 * page has gone out — a successful verify has to redirect, and a
 * redirect issued from here (during `the_content`) arrives after the
 * headers and is silently dropped. What's left for the view:
 *
 *   - The form, in TOTP or backup-code mode.
 *   - The failed-attempt error, read back from
 *     `MfaChallengeHandler::error()` — one generic string for both
 *     modes, no "wrong code" vs "backup code didn't match"
 *     side-channel for guessing.
 *   - The lockout countdown (`RateLimiter::isLockedOut()`) in place of
 *     the input, so the user can't accumulate further failures during
 *     the window.
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
        // enrollment wizard instead. `MfaChallengeHandler` bounces this
        // case at `init`; reaching it here means the handler didn't run,
        // and the headers are already out, so offer a way forward
        // instead of a redirect that would blank the page (#2668).
        if ( $row === null || empty( $row['enrolled_at'] ) || empty( $row['secret'] ) ) {
            MfaLoginGuard::clearPending( $user_id );
            self::renderContinue( WizardEntryPoint::dashboardBaseUrl() );
            return;
        }

        // Lockout screen.
        if ( $limiter->isLockedOut( $user_id ) ) {
            self::renderLockout( $limiter->lockoutSecondsRemaining( $user_id ) );
            return;
        }

        self::renderForm( $user_id, MfaChallengeHandler::error() );
    }

    /**
     * Dead-end insurance (#2668). Every "you don't belong on this page"
     * case is a redirect issued from `MfaChallengeHandler` at `init`, so
     * this should never paint. If it ever does — the headers are already
     * out and a redirect would blank the page — the user gets a card with
     * a link out instead of an empty screen.
     */
    public static function renderContinue( string $url ): void {
        self::openCard();
        echo '<p class="tt-login-subtitle">' . esc_html__( 'Two-factor authentication', 'talenttrack' ) . '</p>';
        echo '<p class="tt-login-help">'
            . esc_html__( "You're verified. Continue to your dashboard.", 'talenttrack' )
            . '</p>';
        echo '<p class="tt-login-links"><a class="tt-btn tt-btn-primary tt-btn-block" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Go to dashboard', 'talenttrack' )
            . '</a></p>';
        self::closeCard();
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
}
