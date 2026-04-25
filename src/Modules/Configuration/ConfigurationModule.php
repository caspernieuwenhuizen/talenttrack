<?php
namespace TT\Modules\Configuration;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\ConfigRestController;
use TT\Infrastructure\REST\CustomFieldsRestController;

class ConfigurationModule implements ModuleInterface {
    public function getName(): string { return 'configuration'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\ConfigurationPage::init();
        ConfigRestController::init();
        CustomFieldsRestController::init();
    }
}
