<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * PasswordResetView (#1866) — TalentTrack-branded password reset screens,
 * replacing the bare wp-login.php lost-password / reset pages.
 *
 * Two states, both rendered on the dashboard page before the login guard
 * (DashboardShortcode), so a logged-out visitor stays inside the branded
 * shell the login card uses:
 *
 *   ?tt_view=lost-password                      → request form (enter email)
 *   ?tt_view=reset-password&key=…&login=…       → set-new-password form
 *
 * The secure mechanics stay in WordPress core: get_password_reset_key /
 * check_password_reset_key / reset_password (see PasswordResetHandler).
 * This class only owns the chrome.
 */
class PasswordResetView {

    /** Request step — ask for the account email / username. */
    public static function renderRequest( string $error = '' ): string {
        $login_url = self::loginUrl();

        ob_start();
        self::open();
        echo '<p class="tt-login-subtitle">' . esc_html__( 'Reset your password', 'talenttrack' ) . '</p>';

        if ( $error !== '' ) {
            echo '<div class="tt-login-error">' . esc_html( $error ) . '</div>';
        }

        echo '<p class="tt-login-help">' . esc_html__( 'Enter your email and we\'ll send you a link to choose a new password.', 'talenttrack' ) . '</p>';
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-login-form">
            <?php wp_nonce_field( 'tt_lost_password', 'tt_lost_password_nonce' ); ?>
            <input type="hidden" name="action" value="tt_lost_password" />
            <div class="tt-form-row">
                <label for="tt_lp_login"><?php esc_html_e( 'Email or username', 'talenttrack' ); ?></label>
                <input type="text" name="user_login" id="tt_lp_login" required autocomplete="username" inputmode="email" autofocus />
            </div>
            <button type="submit" class="tt-btn tt-btn-primary tt-btn-block">
                <?php esc_html_e( 'Send reset link', 'talenttrack' ); ?>
            </button>
            <p class="tt-login-links">
                <a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Back to sign in', 'talenttrack' ); ?></a>
            </p>
        </form>
        <?php
        self::close();
        return (string) ob_get_clean();
    }

    /**
     * Reset step — validate the key and either render the set-password form
     * or a dignified "this link is no longer valid" state.
     */
    public static function renderReset( string $key, string $login, string $error = '' ): string {
        $login_url   = self::loginUrl();
        $request_url = add_query_arg( 'tt_view', 'lost-password', $login_url );

        $user = check_password_reset_key( $key, $login );

        ob_start();
        self::open();

        if ( is_wp_error( $user ) ) {
            echo '<p class="tt-login-subtitle">' . esc_html__( 'Reset link expired', 'talenttrack' ) . '</p>';
            echo '<p class="tt-login-help">' . esc_html__( 'This password reset link is invalid or has already been used. Request a new one to continue.', 'talenttrack' ) . '</p>';
            echo '<p class="tt-login-links"><a class="tt-btn tt-btn-primary tt-btn-block" href="' . esc_url( $request_url ) . '">'
                . esc_html__( 'Request a new link', 'talenttrack' ) . '</a></p>';
            echo '<p class="tt-login-links"><a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Back to sign in', 'talenttrack' ) . '</a></p>';
            self::close();
            return (string) ob_get_clean();
        }

        echo '<p class="tt-login-subtitle">' . esc_html__( 'Choose a new password', 'talenttrack' ) . '</p>';
        if ( $error !== '' ) {
            echo '<div class="tt-login-error">' . esc_html( $error ) . '</div>';
        }
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-login-form" autocomplete="off">
            <?php wp_nonce_field( 'tt_reset_password', 'tt_reset_password_nonce' ); ?>
            <input type="hidden" name="action" value="tt_reset_password" />
            <input type="hidden" name="rp_key"   value="<?php echo esc_attr( $key ); ?>" />
            <input type="hidden" name="rp_login" value="<?php echo esc_attr( $login ); ?>" />
            <div class="tt-form-row">
                <label for="tt_rp_pass1"><?php esc_html_e( 'New password', 'talenttrack' ); ?></label>
                <input type="password" name="pass1" id="tt_rp_pass1" required minlength="8" autocomplete="new-password" autofocus />
            </div>
            <div class="tt-form-row">
                <label for="tt_rp_pass2"><?php esc_html_e( 'Confirm new password', 'talenttrack' ); ?></label>
                <input type="password" name="pass2" id="tt_rp_pass2" required minlength="8" autocomplete="new-password" />
            </div>
            <button type="submit" class="tt-btn tt-btn-primary tt-btn-block">
                <?php esc_html_e( 'Set new password', 'talenttrack' ); ?>
            </button>
            <p class="tt-login-links">
                <a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Back to sign in', 'talenttrack' ); ?></a>
            </p>
        </form>
        <?php
        self::close();
        return (string) ob_get_clean();
    }

    /** Branded card opening — logo + academy name, mirroring the login card. */
    private static function open(): void {
        $logo    = QueryHelpers::get_config( 'logo_url', '' );
        $academy = QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        echo '<div class="tt-dashboard"><div class="tt-login-shell"><div class="tt-login-card">';
        if ( $logo ) {
            echo '<img src="' . esc_url( $logo ) . '" alt="" class="tt-login-logo" />';
        }
        echo '<h2 class="tt-login-title">' . esc_html( $academy ) . '</h2>';
    }

    private static function close(): void {
        echo '</div></div></div>';
    }

    /** The dashboard (login) page URL. */
    private static function loginUrl(): string {
        /** @var \TT\Shared\Frontend\FrontendAccessControl $access */
        $access = \TT\Core\Kernel::instance()->container()->get( 'frontend.access' );
        return $access->dashboardUrl();
    }
}
