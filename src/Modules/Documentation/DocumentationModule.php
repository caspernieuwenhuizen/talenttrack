<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

class DocumentationModule implements ModuleInterface {
    public function getName(): string { return 'documentation'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\DocumentationPage::init();
        DocsRestController::init();
    }
}
