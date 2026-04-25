<?php
namespace TT\Modules\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\EvalCategoriesRestController;
use TT\Infrastructure\REST\EvaluationsRestController;

class EvaluationsModule implements ModuleInterface {
    public function getName(): string { return 'evaluations'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) {
            Admin\EvaluationsPage::init();
            // v2.12.0: evaluation categories got their own admin page (hierarchy support).
            add_action( 'admin_post_tt_save_eval_category',   [ Admin\EvalCategoriesPage::class, 'handleSave' ] );
            add_action( 'admin_post_tt_toggle_eval_category', [ Admin\EvalCategoriesPage::class, 'handleToggle' ] );
            // v2.13.0: per-age-group category weights for overall rating.
            add_action( 'admin_post_tt_save_category_weights',  [ Admin\CategoryWeightsPage::class, 'handleSave' ] );
            add_action( 'admin_post_tt_reset_category_weights', [ Admin\CategoryWeightsPage::class, 'handleReset' ] );
        }
        EvaluationsRestController::init();
        EvalCategoriesRestController::init();
    }
}
