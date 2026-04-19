<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Shared\Frontend\FrontendAccessControl;

/**
 * LogoutHandler — explicit TT logout endpoint.
 *
 * Why a separate endpoint (instead of just wp_logout_url())?
 * - wp_logout_url() requires a nonce in the URL, which works fine but gives
 *   us no central place to hook additional logic later (e.g. clearing a
 *   TT-specific session artefact, audit log entry).
 * - This endpoint accepts a nonce the same way, calls wp_logout(), then
 *   redirects to the dashboard page.
 *
 * URL shape: /wp-admin/admin-post.php?action=tt_logout&_wpnonce=...
 * Both logged-in and not-logged-in variants are registered (the latter
 * just redirects home harmlessly).
 */
class LogoutHandler {

    public function register(): void {
        add_action( 'admin_post_tt_logout',        [ $this, 'handle' ] );
        add_action( 'admin_post_nopriv_tt_logout', [ $this, 'handle' ] );
    }

    public function handle(): void {
        // Nonce verification — WP's wp_logout_url() uses "log-out" as the
        // action name; we use our own action so we control the flow.
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? (string) $_REQUEST['_wpnonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'tt_logout' ) ) {
            wp_safe_redirect( $this->dashboardUrl() );
            exit;
        }

        if ( is_user_logged_in() ) {
            wp_logout(); // Destroys the session & unsets auth cookies.
        }

        wp_safe_redirect( $this->dashboardUrl() );
        exit;
    }

    public static function url(): string {
        return add_query_arg( [
            'action'   => 'tt_logout',
            '_wpnonce' => wp_create_nonce( 'tt_logout' ),
        ], admin_url( 'admin-post.php' ) );
    }

    private function dashboardUrl(): string {
        /** @var FrontendAccessControl $access */
        $access = Kernel::instance()->container()->get( 'frontend.access' );
        return $access->dashboardUrl();
    }
}
