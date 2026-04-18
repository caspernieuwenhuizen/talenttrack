<?php
namespace TT\Modules\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\PlayersRestController;

class PlayersModule implements ModuleInterface {
    public function getName(): string { return 'players'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\PlayersPage::init();
        PlayersRestController::init();
    }
}
