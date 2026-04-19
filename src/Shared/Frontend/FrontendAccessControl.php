<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * FrontendAccessControl — Sprint 1a access-control primitives.
 *
 * Responsibilities (all of these are non-destructive and reversible):
 *
 *  1. Redirect non-administrator users away from wp-admin pages.
 *     - Allows admin-ajax.php (critical — frontend AJAX uses it).
 *     - Allows the profile page for all users (so they can change their password).
 *     - Bypasses for actual administrators.
 *
 *  2. Hide admin bar for non-administrators on the frontend.
 *
 *  3. Gate wp-login.php:
 *     - action=logout, lostpassword, rp, resetpass → pass through (WP handles these)
 *     - bare / action=login → redirect to the TalentTrack dashboard page
 *
 *  4. Ensure logout always lands on the dashboard page (i.e. the login form).
 *
 * Home resolution: prefers the configured dashboard_page_id (tt_config) if set;
 * falls back to home_url('/'). This prevents the "homepage doesn't contain the
 * shortcode" failure mode.
 */
class FrontendAccessControl {

    /** @var ConfigService */
    private $config;

    public function __construct( ConfigService $config ) {
        $this->config = $config;
    }

    public function register(): void {
        add_action( 'admin_init',          [ $this, 'restrictWpAdmin' ], 1 );
        add_filter( 'show_admin_bar',      [ $this, 'hideAdminBar' ] );
        add_action( 'login_init',          [ $this, 'gateWpLogin' ] );
        add_filter( 'logout_redirect',     [ $this, 'logoutRedirect' ], 10, 3 );
        add_filter( 'login_redirect',      [ $this, 'loginRedirect' ], 10, 3 );
        add_filter( 'lostpassword_redirect', [ $this, 'passwordResetRedirect' ] );
    }

    /**
     * Resolve the URL to send users to. Prefers the configured dashboard page;
     * falls back to home_url('/').
     */
    public function dashboardUrl(): string {
        $page_id = (int) $this->config->get( 'dashboard_page_id', '0' );
        if ( $page_id > 0 ) {
            $permalink = get_permalink( $page_id );
            if ( $permalink ) {
                return $permalink;
            }
        }
        return home_url( '/' );
    }

    /**
     * Redirect non-administrator users away from wp-admin.
     *
     * Runs on admin_init at priority 1 so it fires before any plugin's admin-init
     * work. Careful exceptions:
     *   - DOING_AJAX is already true during admin-ajax.php requests; we never
     *     redirect those (would break every frontend AJAX call).
     *   - DOING_CRON is true for wp-cron.php; let it through.
     *   - profile.php is allowed for all logged-in users (password change etc.).
     */
    public function restrictWpAdmin(): void {
        // Never interfere with AJAX or cron.
        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        // Not logged in → WP will handle the login-required redirect itself; don't interfere.
        if ( ! is_user_logged_in() ) {
            return;
        }

        // Administrators bypass everything.
        if ( current_user_can( 'administrator' ) ) {
            return;
        }

        // Allow profile management for everyone (password change, display name).
        global $pagenow;
        if ( in_array( $pagenow, [ 'profile.php' ], true ) ) {
            return;
        }

        // Redirect everyone else to the dashboard page.
        wp_safe_redirect( $this->dashboardUrl() );
        exit;
    }

    /**
     * Hide admin bar for all non-administrators.
     */
    public function hideAdminBar( bool $show ): bool {
        if ( ! is_user_logged_in() ) {
            return $show;
        }
        return current_user_can( 'administrator' ) ? $show : false;
    }

    /**
     * Gate direct access to wp-login.php.
     *
     * Pass-through actions (handled by WP core):
     *   - logout           (logging out)
     *   - lostpassword     (requesting a reset link)
     *   - rp / resetpass   (completing the reset flow)
     *   - confirmaction    (WP's email-confirmation endpoints)
     *   - postpass         (password-protected post form)
     *
     * Everything else (including bare wp-login.php and action=login) redirects
     * to the TT dashboard page so users see the TT login form.
     */
    public function gateWpLogin(): void {
        $action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
        $pass_through = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'confirmaction', 'postpass' ];
        if ( in_array( $action, $pass_through, true ) ) {
            return;
        }

        // Suppress only direct GET requests. POST requests (form submissions to
        // wp-login.php) are allowed through so existing code — including core
        // password submission — keeps working.
        if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
            return;
        }

        // If a user is already logged in and hits wp-login.php, they get the
        // dashboard. If not, they get the TT login form on the dashboard page.
        wp_safe_redirect( $this->dashboardUrl() );
        exit;
    }

    /**
     * Always redirect logout to the dashboard page.
     *
     * @param string  $redirect_to
     * @param string  $requested_redirect_to
     * @param \WP_User|\WP_Error|mixed $user
     */
    public function logoutRedirect( $redirect_to, $requested_redirect_to, $user ): string {
        return $this->dashboardUrl();
    }

    /**
     * After WP-core login (e.g. someone who bypassed the TT login form),
     * still end up on the TT dashboard — except for administrators who
     * explicitly asked for wp-admin.
     *
     * @param string  $redirect_to
     * @param string  $requested_redirect_to
     * @param \WP_User|\WP_Error|mixed $user
     */
    public function loginRedirect( $redirect_to, $requested_redirect_to, $user ): string {
        // On failed login, $user is WP_Error; leave the redirect alone so the
        // error can render.
        if ( ! $user instanceof \WP_User ) {
            return $redirect_to;
        }

        // Administrator explicitly asked for wp-admin → allow it.
        if ( user_can( $user, 'administrator' ) ) {
            if ( $requested_redirect_to && strpos( $requested_redirect_to, admin_url() ) === 0 ) {
                return $requested_redirect_to;
            }
        }

        // Everyone else goes to the TT dashboard.
        return $this->dashboardUrl();
    }

    /**
     * After password reset request, send back to the dashboard page
     * (so the user sees confirmation next to the login form).
     */
    public function passwordResetRedirect( $redirect_to ): string {
        $url = add_query_arg( 'checkemail', 'confirm', $this->dashboardUrl() );
        return $url;
    }
}
