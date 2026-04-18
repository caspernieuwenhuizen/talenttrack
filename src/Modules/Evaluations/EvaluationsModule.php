<?php
namespace TT\Modules\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\EvaluationsRestController;

class EvaluationsModule implements ModuleInterface {
    public function getName(): string { return 'evaluations'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\EvaluationsPage::init();
        EvaluationsRestController::init();
    }
}
