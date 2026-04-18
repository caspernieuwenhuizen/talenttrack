<?php
namespace TT\Modules\Teams;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

class TeamsModule implements ModuleInterface {
    public function getName(): string { return 'teams'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\TeamsPage::init();
    }
}
