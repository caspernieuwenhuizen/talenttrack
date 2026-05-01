<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * FrontendAccessControl — Sprint 1a access-control primitives.
 *
 * Responsibilities (all non-destructive and reversible):
 *
 *  1. Redirect non-administrator users away from wp-admin pages.
 *     Allows: admin-ajax.php, admin-post.php, profile.php, DOING_CRON.
 *     Bypasses for actual administrators.
 *
 *  2. Hide admin bar for non-administrators on the frontend.
 *
 *  3. Gate wp-login.php (except logout / password-reset actions).
 *
 *  4. Ensure logout always lands on the dashboard page.
 *
 *  5. Override core login redirect to push users to the TT dashboard.
 *
 * v2.5.1 fix: admin-post.php is now whitelisted. Previously this was blocked
 * for non-admins, which broke the frontend logout button and any future
 * admin-post endpoint hit by non-admin TT roles.
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
     * Resolve the URL to send users to. Prefers configured dashboard_page_id;
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
     * Runs on admin_init priority 1 so it fires before any plugin's
     * admin-init work. Critical exceptions:
     *   - DOING_AJAX (admin-ajax.php)   — frontend AJAX relies on this
     *   - DOING_CRON (wp-cron)          — scheduled tasks must run
     *   - admin-post.php                — logout + any other form-post endpoints
     *   - profile.php                   — any logged-in user may edit their profile
     */
    public function restrictWpAdmin(): void {
        // Never interfere with AJAX or cron.
        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        // Not logged in → let WP handle it.
        if ( ! is_user_logged_in() ) {
            return;
        }

        // Administrators bypass everything.
        if ( current_user_can( 'administrator' ) ) {
            return;
        }

        // Whitelist specific admin pages.
        global $pagenow;
        $allowed = [
            'admin-post.php',   // logout endpoint + any future admin-post form targets
            'profile.php',      // user profile management (password, name, email)
        ];
        if ( in_array( $pagenow, $allowed, true ) ) {
            return;
        }

        // Redirect everyone else to the dashboard page.
        wp_safe_redirect( $this->dashboardUrl() );
        exit;
    }

    public function hideAdminBar( bool $show ): bool {
        if ( ! is_user_logged_in() ) {
            return $show;
        }
        return current_user_can( 'administrator' ) ? $show : false;
    }

    public function gateWpLogin(): void {
        // v3.75.1 — test-only bypass so the Playwright suite (#12) can
        // load `/wp-login.php` and exercise the login flow. Setting
        // `TT_DISABLE_LOGIN_GATE` to true in `.wp-env.json` (or any
        // other dev / staging install) keeps the default redirect off.
        // Production installs leave the constant undefined and the
        // gate keeps doing its job.
        if ( defined( 'TT_DISABLE_LOGIN_GATE' ) && TT_DISABLE_LOGIN_GATE ) {
            return;
        }
        $action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
        $pass_through = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'confirmaction', 'postpass' ];
        if ( in_array( $action, $pass_through, true ) ) {
            return;
        }
        if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
            return;
        }
        wp_safe_redirect( $this->dashboardUrl() );
        exit;
    }

    /**
     * @param string $redirect_to
     * @param string $requested_redirect_to
     * @param \WP_User|\WP_Error|mixed $user
     */
    public function logoutRedirect( $redirect_to, $requested_redirect_to, $user ): string {
        return $this->dashboardUrl();
    }

    /**
     * @param string $redirect_to
     * @param string $requested_redirect_to
     * @param \WP_User|\WP_Error|mixed $user
     */
    public function loginRedirect( $redirect_to, $requested_redirect_to, $user ): string {
        if ( ! $user instanceof \WP_User ) {
            return $redirect_to;
        }
        if ( user_can( $user, 'administrator' ) ) {
            if ( $requested_redirect_to && strpos( $requested_redirect_to, admin_url() ) === 0 ) {
                return $requested_redirect_to;
            }
        }
        return $this->dashboardUrl();
    }

    public function passwordResetRedirect( $redirect_to ): string {
        return add_query_arg( 'checkemail', 'confirm', $this->dashboardUrl() );
    }
}
