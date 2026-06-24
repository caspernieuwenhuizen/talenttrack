<?php
namespace TT\Modules\Auth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * AuthModule — login form + login handler + logout handler.
 *
 * Sprint 1a: adds LogoutHandler.
 */
class AuthModule implements ModuleInterface {

    public function getName(): string {
        return 'auth';
    }

    public function register( Container $container ): void {
        $container->bind( 'auth.login_form', function () {
            return new LoginForm();
        });
        $container->bind( 'auth.login_handler', function () {
            return new LoginHandler();
        });
        $container->bind( 'auth.logout_handler', function () {
            return new LogoutHandler();
        });
    }

    public function boot( Container $container ): void {
        /** @var LoginHandler $login */
        $login = $container->get( 'auth.login_handler' );
        $login->register();

        /** @var LogoutHandler $logout */
        $logout = $container->get( 'auth.logout_handler' );
        $logout->register();

        // #1772 — clear player/person/parent account links when a WP user
        // is deleted, so a re-issued user id can't silently inherit
        // someone else's record.
        WpUserUnlink::register();
    }
}
