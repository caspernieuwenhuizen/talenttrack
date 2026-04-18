<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LoginHandler — processes the login POST from LoginForm.
 *
 * Security:
 *   - Nonce check (tt_login / tt_login_nonce)
 *   - Uses wp_signon() — handles password hashing, brute-force protection by WP
 *   - Safely redirects using wp_safe_redirect()
 */
class LoginHandler {

    public static function handle(): void {
        if ( ! isset( $_POST['tt_login_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['tt_login_nonce'] ), 'tt_login' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'talenttrack' ) );
        }

        $redirect_to = isset( $_POST['redirect_to'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['redirect_to'] ) )
            : home_url( '/' );

        $creds = [
            'user_login'    => sanitize_text_field( wp_unslash( (string) ( $_POST['log'] ?? '' ) ) ),
            'user_password' => (string) ( $_POST['pwd'] ?? '' ),
            'remember'      => ! empty( $_POST['rememberme'] ),
        ];

        $user = wp_signon( $creds, is_ssl() );

        if ( is_wp_error( $user ) ) {
            $msg = $user->get_error_message();
            $sep = ( strpos( $redirect_to, '?' ) !== false ) ? '&' : '?';
            wp_safe_redirect( $redirect_to . $sep . 'tt_login_error=' . rawurlencode( wp_strip_all_tags( $msg ) ) );
            exit;
        }

        wp_safe_redirect( $redirect_to );
        exit;
    }
}
