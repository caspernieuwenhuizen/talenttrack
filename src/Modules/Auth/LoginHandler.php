<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Shared\Frontend\FrontendAccessControl;

/**
 * LoginHandler — handles the admin-post form submission from the TT login form.
 *
 * Sprint 1a change: after a successful login, unconditionally redirect to the
 * TalentTrack dashboard page (home_url() fallback). Never to wp-admin, even for
 * administrators — admins can always reach wp-admin via the admin bar which
 * remains visible for them.
 */
class LoginHandler {

    public function register(): void {
        add_action( 'admin_post_nopriv_tt_login', [ $this, 'handle' ] );
        add_action( 'admin_post_tt_login',        [ $this, 'handle' ] );
    }

    public function handle(): void {
        // Security — nonce check.
        if ( ! isset( $_POST['tt_login_nonce'] ) || ! wp_verify_nonce( (string) $_POST['tt_login_nonce'], 'tt_login' ) ) {
            $this->redirectWithError( 'nonce' );
        }

        $login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['log'] ) ) : '';
        $pass  = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
        $remember = ! empty( $_POST['rememberme'] );

        if ( $login === '' || $pass === '' ) {
            $this->redirectWithError( 'empty' );
        }

        $user = wp_signon( [
            'user_login'    => $login,
            'user_password' => $pass,
            'remember'      => $remember,
        ], is_ssl() );

        if ( is_wp_error( $user ) ) {
            $this->redirectWithError( 'credentials' );
        }

        // Successful login — set the current user so anything later in the
        // request sees them as authenticated, then redirect to the dashboard.
        wp_set_current_user( $user->ID );

        wp_safe_redirect( $this->dashboardUrl() );
        exit;
    }

    /**
     * Resolve dashboard URL via FrontendAccessControl (which respects the
     * configured dashboard_page_id, falling back to home_url()).
     */
    private function dashboardUrl(): string {
        /** @var FrontendAccessControl $access */
        $access = Kernel::instance()->container()->get( 'frontend.access' );
        return $access->dashboardUrl();
    }

    private function redirectWithError( string $code ): void {
        $target = add_query_arg( 'tt_login_error', $code, wp_get_referer() ?: home_url( '/' ) );
        wp_safe_redirect( $target );
        exit;
    }
}
