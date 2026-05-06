<?php
namespace TT\Modules\Mfa\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Modules\Mfa\Settings\MfaSettings;
use TT\Modules\Mfa\Wizards\MfaEnrollmentWizard;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * MfaLoginGuard — runtime enforcement of the per-club MFA policy
 * (#0086 Workstream B Child 1, sprint 3).
 *
 * Two-stage flow:
 *
 * 1. **`wp_login` action (post-cookie).** Right after WordPress validates
 *    the password and issues the auth cookie, decide whether this user's
 *    persona requires a second factor. Outcomes:
 *    - Persona not gated → no-op. Login proceeds normally.
 *    - Persona gated, valid `tt_mfa_device` cookie → no-op. The cookie's
 *      `last_used_at` was bumped during verify; user skips the challenge.
 *    - Persona gated, user enrolled, no remember cookie → set the
 *      `tt_mfa_pending_<user_id>` transient. The middleware on the next
 *      request redirects to the prompt page.
 *    - Persona gated, user NOT enrolled → set `tt_mfa_must_enroll_<user_id>`.
 *      The middleware redirects to the enrollment wizard with a notice.
 *
 *    The original `redirect_to` URL is stashed in a per-user transient
 *    (`tt_mfa_post_verify_url_<user_id>`) so the prompt page can send the
 *    user there after success.
 *
 * 2. **`init` action (every subsequent request).** While the pending /
 *    must-enroll transient is set, redirect every request that isn't:
 *    - the prompt page itself,
 *    - the enrollment wizard,
 *    - admin-post / admin-ajax actions (so the user can submit forms),
 *    - logout (so they can bail out).
 *
 *    The transient acts as a "challenge required" flag the user can't
 *    bypass by changing URLs. Login flow doesn't fully complete until
 *    the prompt clears the transient.
 *
 * Sprint-3 scope cut: we don't intercept inside the WordPress
 * `authenticate` filter (which would return WP_Error before the cookie
 * issues). Instead we let WP issue the cookie and gate every subsequent
 * request via the middleware. Trade-off: there's a brief window where
 * the user has a session cookie before the second factor lands. The
 * middleware redirects that user away from anything sensitive. Plugin
 * convention; matches the most-deployed WP MFA plugins.
 */
final class MfaLoginGuard {

    private const PENDING_TRANSIENT_PREFIX     = 'tt_mfa_pending_';
    private const ENROLL_TRANSIENT_PREFIX      = 'tt_mfa_must_enroll_';
    private const POST_VERIFY_TRANSIENT_PREFIX = 'tt_mfa_post_verify_url_';
    private const PENDING_TTL                  = 15 * MINUTE_IN_SECONDS;

    public static function init(): void {
        // Post-cookie hook: WordPress has issued the auth cookie by the
        // time wp_login fires. We set the gating transient here.
        add_action( 'wp_login', [ self::class, 'onLogin' ], 10, 2 );
        // Per-request middleware. Priority 5 so we run before most other
        // init subscribers (we may redirect before they execute).
        add_action( 'init', [ self::class, 'enforce' ], 5 );
    }

    /**
     * @param string   $user_login
     * @param \WP_User $user
     */
    public static function onLogin( $user_login, $user ): void {
        if ( ! ( $user instanceof \WP_User ) ) return;
        $user_id = (int) $user->ID;
        if ( $user_id <= 0 ) return;

        if ( ! self::personaIsGated( $user_id ) ) return;

        // Valid remember-device cookie? Bumped server-side during verify.
        if ( RememberDeviceCookie::verifyForUser( $user_id ) ) return;

        $repo = new MfaSecretsRepository();
        if ( $repo->isEnrolled( $user_id ) ) {
            set_transient( self::PENDING_TRANSIENT_PREFIX . $user_id, 1, self::PENDING_TTL );
        } else {
            set_transient( self::ENROLL_TRANSIENT_PREFIX . $user_id, 1, self::PENDING_TTL );
        }

        // Stash the original post-login destination if WP carried one.
        $redirect_to = isset( $_REQUEST['redirect_to'] )
            ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) )
            : '';
        if ( $redirect_to !== '' ) {
            set_transient( self::POST_VERIFY_TRANSIENT_PREFIX . $user_id, $redirect_to, self::PENDING_TTL );
        }
    }

    /**
     * Per-request middleware. Redirects a logged-in user with an open
     * MFA challenge to the prompt / wizard until they clear it.
     */
    public static function enforce(): void {
        if ( ! is_user_logged_in() ) return;
        if ( wp_doing_ajax() || wp_doing_cron() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

        $user_id = get_current_user_id();
        $pending = (bool) get_transient( self::PENDING_TRANSIENT_PREFIX . $user_id );
        $enroll  = (bool) get_transient( self::ENROLL_TRANSIENT_PREFIX . $user_id );
        if ( ! $pending && ! $enroll ) return;

        // Allow the prompt page, the enrollment wizard, admin-post, and
        // wp-logout to proceed without redirect — the user needs them to
        // resolve the challenge.
        $script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        if ( str_ends_with( $script, '/wp-login.php' )
             || str_ends_with( $script, '/admin-post.php' )
             || str_ends_with( $script, '/admin-ajax.php' )
        ) {
            return;
        }

        $tt_view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        $slug    = isset( $_GET['slug'] )    ? sanitize_key( (string) $_GET['slug'] )    : '';

        if ( $pending && $tt_view === 'mfa-prompt' ) return;
        if ( $enroll  && $tt_view === 'wizard' && $slug === MfaEnrollmentWizard::SLUG ) return;

        $base = WizardEntryPoint::dashboardBaseUrl();
        if ( $pending ) {
            $target = add_query_arg( [ 'tt_view' => 'mfa-prompt' ], $base );
        } else {
            $target = add_query_arg(
                [ 'tt_view' => 'wizard', 'slug' => MfaEnrollmentWizard::SLUG, 'tt_mfa_required' => '1' ],
                $base
            );
        }
        wp_safe_redirect( $target );
        exit;
    }

    /**
     * Drop the pending challenge for a user. Called by the prompt view
     * after a successful verify — the next request stops being
     * intercepted by `enforce()`. Also drops the must-enroll flag (the
     * enrollment wizard's submit clears it; this is belt-and-braces).
     */
    public static function clearPending( int $user_id ): void {
        if ( $user_id <= 0 ) return;
        delete_transient( self::PENDING_TRANSIENT_PREFIX . $user_id );
        delete_transient( self::ENROLL_TRANSIENT_PREFIX . $user_id );
    }

    /**
     * Whether `$user_id` holds at least one persona that's in the
     * per-club `mfa_required_personas` list. Multi-persona users
     * trip the gate as soon as any persona is required.
     */
    public static function personaIsGated( int $user_id ): bool {
        $required = ( new MfaSettings() )->requiredPersonas();
        if ( empty( $required ) ) return false;
        $personas = PersonaResolver::personasFor( $user_id );
        return ! empty( array_intersect( $required, $personas ) );
    }
}
