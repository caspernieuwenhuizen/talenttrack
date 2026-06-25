<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\FrontendAccessControl;

/**
 * PasswordResetHandler (#1866) — backs the branded password reset flow.
 *
 * Handles the two admin-post submissions and points WordPress's
 * `lostpassword_url` at the branded frontend page so every "Lost your
 * password?" link (the login card included) lands on our screens rather
 * than wp-login.php.
 *
 * Security stays in core:
 *   - get_password_reset_key()  — generates + stores the hashed key + expiry
 *   - check_password_reset_key()— validates the key + expiry
 *   - reset_password()          — hashes + stores the new password, clears key
 *
 * The request step never reveals whether an account exists — it always
 * lands on the same "check your email" confirmation.
 */
class PasswordResetHandler {

    public function register(): void {
        add_action( 'admin_post_nopriv_tt_lost_password',  [ $this, 'handleRequest' ] );
        add_action( 'admin_post_tt_lost_password',         [ $this, 'handleRequest' ] );
        add_action( 'admin_post_nopriv_tt_reset_password', [ $this, 'handleReset' ] );
        add_action( 'admin_post_tt_reset_password',        [ $this, 'handleReset' ] );

        // Route WP's lost-password URL to the branded frontend page.
        add_filter( 'lostpassword_url', [ $this, 'lostPasswordUrl' ], 10, 0 );
    }

    /** Branded frontend lost-password page. */
    public function lostPasswordUrl(): string {
        return add_query_arg( 'tt_view', 'lost-password', $this->loginUrl() );
    }

    /**
     * Request step — generate a reset key for the matched account and email
     * a branded link. Always redirects to the same confirmation so a caller
     * can't probe which emails have accounts.
     */
    public function handleRequest(): void {
        if ( ! isset( $_POST['tt_lost_password_nonce'] )
            || ! wp_verify_nonce( (string) $_POST['tt_lost_password_nonce'], 'tt_lost_password' ) ) {
            $this->redirect( add_query_arg( 'tt_view', 'lost-password', $this->loginUrl() ) );
        }

        $login = isset( $_POST['user_login'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['user_login'] ) ) ) : '';
        $confirm = add_query_arg( 'checkemail', 'confirm', $this->loginUrl() );

        if ( $login === '' ) {
            $this->redirect( $confirm );
        }

        $user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
        // Fall back to the email lookup for an email that wasn't recognised
        // by is_email() edge cases — but still never branch the response.
        if ( ! $user && is_email( $login ) ) {
            $user = get_user_by( 'login', $login );
        }

        if ( $user instanceof \WP_User ) {
            $key = get_password_reset_key( $user );
            if ( ! is_wp_error( $key ) ) {
                $this->sendResetEmail( $user, $key );
            }
        }

        $this->redirect( $confirm );
    }

    /**
     * Reset step — re-validate the key, enforce a confirmed minimum-length
     * password, then hand off to core reset_password().
     */
    public function handleReset(): void {
        $key   = isset( $_POST['rp_key'] )   ? trim( (string) wp_unslash( $_POST['rp_key'] ) )   : '';
        $login = isset( $_POST['rp_login'] ) ? trim( (string) wp_unslash( $_POST['rp_login'] ) ) : '';

        $reset_url = add_query_arg(
            [ 'tt_view' => 'reset-password', 'key' => rawurlencode( $key ), 'login' => rawurlencode( $login ) ],
            $this->loginUrl()
        );

        if ( ! isset( $_POST['tt_reset_password_nonce'] )
            || ! wp_verify_nonce( (string) $_POST['tt_reset_password_nonce'], 'tt_reset_password' ) ) {
            $this->redirect( add_query_arg( 'tt_view', 'lost-password', $this->loginUrl() ) );
        }

        $user = check_password_reset_key( $key, $login );
        if ( is_wp_error( $user ) ) {
            // Key died between render and submit — send them to request again.
            $this->redirect( add_query_arg( 'tt_view', 'lost-password', $this->loginUrl() ) );
        }

        $pass1 = isset( $_POST['pass1'] ) ? (string) $_POST['pass1'] : '';
        $pass2 = isset( $_POST['pass2'] ) ? (string) $_POST['pass2'] : '';

        if ( $pass1 === '' || $pass2 === '' ) {
            $this->redirect( add_query_arg( 'rp_error', 'empty', $reset_url ) );
        }
        if ( $pass1 !== $pass2 ) {
            $this->redirect( add_query_arg( 'rp_error', 'mismatch', $reset_url ) );
        }
        if ( strlen( $pass1 ) < 8 ) {
            $this->redirect( add_query_arg( 'rp_error', 'weak', $reset_url ) );
        }

        reset_password( $user, $pass1 );

        $this->redirect( add_query_arg( 'password', 'reset', $this->loginUrl() ) );
    }

    /** Send the branded reset email with a link to the frontend reset page. */
    private function sendResetEmail( \WP_User $user, string $key ): void {
        $academy   = (string) QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        $reset_url = add_query_arg(
            [ 'tt_view' => 'reset-password', 'key' => rawurlencode( $key ), 'login' => rawurlencode( $user->user_login ) ],
            $this->loginUrl()
        );

        $subject = sprintf(
            /* translators: %s: academy name */
            __( '[%s] Reset your password', 'talenttrack' ),
            $academy
        );

        $lines = [
            sprintf(
                /* translators: %s: user display/login name */
                __( 'Hi %s,', 'talenttrack' ),
                $user->display_name !== '' ? $user->display_name : $user->user_login
            ),
            '',
            __( 'We received a request to reset your password. Choose a new one here:', 'talenttrack' ),
            $reset_url,
            '',
            __( "If you didn't request this, you can safely ignore this email — your password stays the same.", 'talenttrack' ),
            '',
            $academy,
        ];

        wp_mail( $user->user_email, $subject, implode( "\r\n", $lines ) );
    }

    private function loginUrl(): string {
        /** @var FrontendAccessControl $access */
        $access = Kernel::instance()->container()->get( 'frontend.access' );
        return $access->dashboardUrl();
    }

    private function redirect( string $url ): void {
        wp_safe_redirect( $url );
        exit;
    }
}
