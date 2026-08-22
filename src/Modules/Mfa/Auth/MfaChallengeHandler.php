<?php
namespace TT\Modules\Mfa\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * MfaChallengeHandler — everything the challenge page does *before* it
 * renders anything (#2668).
 *
 * Runs on `init` at priority 6, immediately after
 * `MfaLoginGuard::enforce()` has decided the request may stay on the
 * prompt. That timing is the whole point: the prompt renders inside the
 * `[talenttrack_dashboard]` shortcode, i.e. during `the_content`, by
 * which time the response headers have long since gone out. A
 * `wp_safe_redirect()` from there emits nothing and returns false, and
 * the `exit` behind it truncates the page — which is what left a
 * verified user staring at a blank screen. At `init` no output has
 * happened, so the redirect is a real 302.
 *
 * Three outcomes, all resolved here:
 *
 *   - **Nothing to verify** (no pending challenge — a hand-typed URL, or
 *     a reload of a page whose challenge was already cleared): bounce to
 *     the dashboard.
 *   - **Not enrolled** (the guard would have sent them to the enrollment
 *     wizard, so they shouldn't be here): clear the flags, bounce.
 *   - **A submitted code**: verify it. Success redirects to the stashed
 *     destination; failure records the attempt and hands the error
 *     string to the view through `error()`, which re-renders the form on
 *     this same request exactly as before.
 *
 * The view keeps the rendering and nothing else — no verification, no
 * rate limiting, no audit writes, no cookie issuance (CLAUDE.md § 4).
 */
final class MfaChallengeHandler {

    /** Error to surface on the form for this request, if the code failed. */
    private static ?string $error = null;

    public static function init(): void {
        add_action( 'init', [ self::class, 'handle' ], 6 );
    }

    /**
     * The error string for the current request's failed submission, or
     * null when there was no submission (or it succeeded, in which case
     * we never got back here — the redirect already happened).
     */
    public static function error(): ?string {
        return self::$error;
    }

    public static function handle(): void {
        self::$error = null;

        if ( wp_doing_ajax() || wp_doing_cron() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $view !== MfaLoginGuard::PROMPT_VIEW ) return;

        $user_id = get_current_user_id();
        // Logged out on the challenge URL — the shortcode renders the
        // login form. A second factor means nothing without a first.
        if ( $user_id <= 0 ) return;

        // Nothing outstanding. Either someone typed the URL, or they just
        // cleared the challenge and reloaded. Send them where they meant
        // to go rather than rendering a code field with nothing behind it.
        if ( ! MfaLoginGuard::isPending( $user_id ) ) {
            self::bounce( WizardEntryPoint::dashboardBaseUrl() );
            return;
        }

        $repo = new MfaSecretsRepository();
        $row  = $repo->findByUserId( $user_id );

        // Pending but not enrolled — the guard should have held them at
        // the enrollment wizard instead. Treat as "no challenge needed".
        if ( $row === null || empty( $row['enrolled_at'] ) || empty( $row['secret'] ) ) {
            MfaLoginGuard::clearPending( $user_id );
            self::bounce( WizardEntryPoint::dashboardBaseUrl() );
            return;
        }

        if ( ! self::isSubmission( $user_id ) ) return;

        $limiter = new RateLimiter();
        // Locked out: the view renders the countdown instead of the form,
        // so there is nothing to process and no attempt to record.
        if ( $limiter->isLockedOut( $user_id ) ) return;

        self::$error = self::verify( $user_id, $row, $repo, $limiter );
    }

    /** Whether this request carries a nonce-valid code submission. */
    private static function isSubmission( int $user_id ): bool {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
        if ( $method !== 'POST' ) return false;
        if ( ! isset( $_POST['tt_mfa_prompt_nonce'] ) ) return false;

        return (bool) wp_verify_nonce(
            sanitize_text_field( wp_unslash( (string) $_POST['tt_mfa_prompt_nonce'] ) ),
            'tt_mfa_prompt_' . $user_id
        );
    }

    /**
     * Validate the submitted code. On success this redirects and exits.
     * On failure it returns the error string for the view to re-render.
     *
     * @param array<string,mixed> $row
     */
    private static function verify( int $user_id, array $row, MfaSecretsRepository $repo, RateLimiter $limiter ): ?string {
        $mode     = isset( $_POST['mode'] ) ? sanitize_key( (string) $_POST['mode'] ) : 'totp';
        $raw      = isset( $_POST['code'] ) ? (string) wp_unslash( (string) $_POST['code'] ) : '';
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
        $redirect = self::postVerifyRedirect( $user_id );

        MfaLoginGuard::clearPending( $user_id );
        if ( $remember ) {
            RememberDeviceCookie::setForUser( $user_id );
        }

        self::bounce( $redirect );
        return null;
    }

    /**
     * Where to send the user after a successful verification. Prefers
     * the original destination they were trying to reach (stashed in the
     * `tt_mfa_post_verify_url_<user_id>` transient by the guard); falls
     * back to the dashboard.
     */
    private static function postVerifyRedirect( int $user_id ): string {
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

    /**
     * Redirect and stop. Only ever called from `init`, where nothing has
     * been echoed yet — the `headers_sent()` check is a tripwire for a
     * future caller that moves this back into a render path, not an
     * expected branch.
     */
    private static function bounce( string $url ): void {
        if ( headers_sent() ) return;
        wp_safe_redirect( $url );
        exit;
    }
}
