<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * LoginForm — renders the TalentTrack-branded frontend login form.
 *
 * Called by the dashboard shortcode when the visitor is not logged in.
 */
class LoginForm {

    public function render( string $error = '', string $redirect_to = '' ): string {
        $logo    = QueryHelpers::get_config( 'logo_url', '' );
        $academy = QueryHelpers::get_config( 'academy_name', 'TalentTrack' );

        if ( $redirect_to === '' ) {
            $redirect_to = (string) ( $_SERVER['REQUEST_URI'] ?? home_url( '/' ) );
        }

        ob_start();
        ?>
        <div class="tt-dashboard">
            <div class="tt-login-shell">
                <div class="tt-login-card">
                    <?php if ( $logo ) : ?>
                        <img src="<?php echo esc_url( $logo ); ?>" alt="" class="tt-login-logo" />
                    <?php endif; ?>
                    <h2 class="tt-login-title"><?php echo esc_html( $academy ); ?></h2>
                    <p class="tt-login-subtitle"><?php esc_html_e( 'Sign in to continue', 'talenttrack' ); ?></p>

                    <?php if ( $error ) : ?>
                        <div class="tt-form-msg tt-error" style="display:block;"><?php echo esc_html( $error ); ?></div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-login-form">
                        <?php wp_nonce_field( 'tt_login', 'tt_login_nonce' ); ?>
                        <input type="hidden" name="action" value="tt_login" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />

                        <div class="tt-form-row">
                            <label for="tt_login_user"><?php esc_html_e( 'Username or email', 'talenttrack' ); ?></label>
                            <input type="text" name="log" id="tt_login_user" required autocomplete="username" autofocus />
                        </div>
                        <div class="tt-form-row">
                            <label for="tt_login_pass"><?php esc_html_e( 'Password', 'talenttrack' ); ?></label>
                            <input type="password" name="pwd" id="tt_login_pass" required autocomplete="current-password" />
                        </div>
                        <div class="tt-form-row tt-form-row-inline">
                            <label class="tt-checkbox-label">
                                <input type="checkbox" name="rememberme" value="forever" />
                                <?php esc_html_e( 'Remember me', 'talenttrack' ); ?>
                            </label>
                        </div>

                        <button type="submit" class="tt-btn tt-btn-primary tt-btn-block">
                            <?php esc_html_e( 'Sign in', 'talenttrack' ); ?>
                        </button>

                        <p class="tt-login-links">
                            <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
                                <?php esc_html_e( 'Lost your password?', 'talenttrack' ); ?>
                            </a>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
