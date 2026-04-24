<?php
namespace TT\Modules\Goals;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\GoalsRestController;

class GoalsModule implements ModuleInterface {
    public function getName(): string { return 'goals'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\GoalsPage::init();
        GoalsRestController::init();
    }
}
