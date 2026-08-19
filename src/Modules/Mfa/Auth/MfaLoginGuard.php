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

    /**
     * Slug of the challenge view. Kept as a literal here rather than
     * importing `FrontendMfaPromptView::SLUG` — the Auth layer doesn't
     * depend on the Frontend layer.
     */
    public const PROMPT_VIEW = 'mfa-prompt';

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
    /**
     * #1392 — break-glass kill-switch. `define( 'TT_MFA_DISABLE', true )`
     * in wp-config.php suppresses ENFORCEMENT only: no challenge is set
     * on login and no request is redirected. Enrollment rows and the
     * persona policy are untouched, so removing the constant restores
     * the exact prior state. This is the operator-lockout recovery that
     * doesn't need database access — see docs/mfa.md.
     */
    public static function killSwitchActive(): bool {
        return defined( 'TT_MFA_DISABLE' ) && TT_MFA_DISABLE;
    }

    public static function onLogin( $user_login, $user ): void {
        if ( self::killSwitchActive() ) return;
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
        //
        // #2553 — never stash a challenge URL. The frontend login form
        // defaults its `redirect_to` to the current REQUEST_URI, so a
        // visitor who signs in while sitting on `?tt_view=mfa-prompt`
        // (any refresh, back-button or re-login after a bounce) would
        // otherwise be sent straight back to the challenge the moment
        // they cleared it. Skipping the stash is the right repair: the
        // fallback is the dashboard, which is where they belong.
        $redirect_to = isset( $_REQUEST['redirect_to'] )
            ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) )
            : '';
        if ( $redirect_to !== '' && ! self::isChallengeUrl( $redirect_to ) ) {
            set_transient( self::POST_VERIFY_TRANSIENT_PREFIX . $user_id, $redirect_to, self::PENDING_TTL );
        }
    }

    /**
     * Whether `$url` points at one of the two MFA challenge screens —
     * the prompt itself or the enrollment wizard.
     *
     * Such a URL must never become a post-verify destination: landing
     * back on the prompt with the challenge already cleared renders a
     * code field the guard no longer intercepts, so the user is stuck
     * there with no way forward but hand-editing the address bar
     * (#2553).
     *
     * Accepts absolute and root-relative URLs alike — the stashed value
     * comes from `REQUEST_URI` in the common case.
     */
    public static function isChallengeUrl( string $url ): bool {
        if ( $url === '' ) return false;

        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        if ( $query === '' ) return false;

        $args = [];
        wp_parse_str( $query, $args );

        $view = isset( $args['tt_view'] ) ? sanitize_key( (string) $args['tt_view'] ) : '';
        if ( $view === self::PROMPT_VIEW ) return true;
        if ( $view !== 'wizard' ) return false;

        // #901 — primary query var is `tt_wizard`; `slug=` is the legacy
        // form `enforce()` still accepts, so match both here too.
        $slug = isset( $args['tt_wizard'] ) ? sanitize_key( (string) $args['tt_wizard'] ) : '';
        if ( $slug === '' && isset( $args['slug'] ) ) {
            $slug = sanitize_key( (string) $args['slug'] );
        }

        return $slug === MfaEnrollmentWizard::SLUG;
    }

    /** Whether `$user_id` has an unverified challenge outstanding. */
    public static function isPending( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return (bool) get_transient( self::PENDING_TRANSIENT_PREFIX . $user_id );
    }

    /** Whether `$user_id` is being held at the enrollment wizard. */
    public static function mustEnroll( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return (bool) get_transient( self::ENROLL_TRANSIENT_PREFIX . $user_id );
    }

    /**
     * Whether either challenge is open. The frontend uses this to decide
     * that a request is still *pre-authentication* and must therefore
     * render without the app shell (#2554) — a half-authenticated session
     * has no business painting the navigation.
     */
    public static function hasOpenChallenge( int $user_id ): bool {
        return self::isPending( $user_id ) || self::mustEnroll( $user_id );
    }

    /**
     * Per-request middleware. Redirects a logged-in user with an open
     * MFA challenge to the prompt / wizard until they clear it.
     */
    public static function enforce(): void {
        // #1392 — break-glass: wp-config kill-switch suppresses all
        // enforcement (a locked-out operator regains wp-admin).
        if ( self::killSwitchActive() ) return;
        if ( ! is_user_logged_in() ) return;
        if ( wp_doing_ajax() || wp_doing_cron() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

        $user_id = get_current_user_id();
        $pending = self::isPending( $user_id );
        $enroll  = self::mustEnroll( $user_id );
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
        // #901 — primary query var is `tt_wizard`. Back-compat: also
        // accept legacy `slug=` so a mid-flight MFA enrollment redirect
        // from a pre-v4.2.1 link still resolves.
        $slug = isset( $_GET['tt_wizard'] ) ? sanitize_key( (string) $_GET['tt_wizard'] ) : '';
        if ( $slug === '' && isset( $_GET['slug'] ) ) {
            $slug = sanitize_key( (string) $_GET['slug'] );
        }

        if ( $pending && $tt_view === self::PROMPT_VIEW ) return;
        if ( $enroll  && $tt_view === 'wizard' && $slug === MfaEnrollmentWizard::SLUG ) return;

        $base = WizardEntryPoint::dashboardBaseUrl();
        if ( $pending ) {
            $target = add_query_arg( [ 'tt_view' => self::PROMPT_VIEW ], $base );
        } else {
            $target = add_query_arg(
                [ 'tt_view' => 'wizard', 'tt_wizard' => MfaEnrollmentWizard::SLUG, 'tt_mfa_required' => '1' ],
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
     *
     * #2553 — the post-verify destination goes with them. The challenge
     * is over, so the stashed target is spent; leaving it behind meant
     * an abandoned challenge kept a live target for the rest of its
     * 15-minute TTL, and a *later* login inside that window inherited
     * it. Callers that still need the target must read it before
     * clearing.
     */
    public static function clearPending( int $user_id ): void {
        if ( $user_id <= 0 ) return;
        delete_transient( self::PENDING_TRANSIENT_PREFIX . $user_id );
        delete_transient( self::ENROLL_TRANSIENT_PREFIX . $user_id );
        delete_transient( self::POST_VERIFY_TRANSIENT_PREFIX . $user_id );
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
