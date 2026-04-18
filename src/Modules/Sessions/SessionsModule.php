<?php
namespace TT\Modules\Sessions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

class SessionsModule implements ModuleInterface {
    public function getName(): string { return 'sessions'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\SessionsPage::init();
    }
}
