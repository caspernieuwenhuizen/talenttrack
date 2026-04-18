<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * AuthModule — owns the frontend login experience.
 *
 * Provides the login form rendered by the dashboard shortcode when the user
 * is logged out, and handles the login POST via admin-post.php.
 */
class AuthModule implements ModuleInterface {

    public function getName(): string {
        return 'auth';
    }

    public function register( Container $container ): void {
        $container->bind( 'auth.login_form', function () {
            return new LoginForm();
        });
    }

    public function boot( Container $container ): void {
        add_action( 'admin_post_nopriv_tt_login', [ LoginHandler::class, 'handle' ] );
        add_action( 'admin_post_tt_login',        [ LoginHandler::class, 'handle' ] );
    }
}
