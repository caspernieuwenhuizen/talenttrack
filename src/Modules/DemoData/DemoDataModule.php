<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * DemoDataModule (#0020).
 *
 * Self-contained wp-admin module for generating, toggling, and wiping
 * a realistic academy dataset. See specs/0020-feat-demo-data-generator.md
 * for the full design.
 *
 * Stays wp-admin-only forever, even after #0019 migrates other surfaces.
 */
class DemoDataModule implements ModuleInterface {

    public function getName(): string { return 'demodata'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        if ( is_admin() ) {
            Admin\DemoDataPage::init();
            // #1272 PR2 — wire the convert form's admin-post handler.
            Admin\DemoReviewPage::init();
        }
        // #3041 — the generate page's overlay advances a run one step per
        // request through these routes, so a large preset never has to
        // outlive a gateway timeout. REST rather than admin-ajax because
        // that is where the rest of the plugin's write surface lives.
        Rest\DemoRunRestController::init();
        DemoBanner::init();
    }
}
