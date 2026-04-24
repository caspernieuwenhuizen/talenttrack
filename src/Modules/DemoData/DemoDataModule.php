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
 * for the full design. Checkpoint 1 ships schema, admin skeleton, and
 * the user/team/player generators. Scope filter + eval/session/goal
 * generators + wipe flow land in Checkpoint 2.
 *
 * Stays wp-admin-only forever, even after #0019 migrates other surfaces.
 */
class DemoDataModule implements ModuleInterface {

    public function getName(): string { return 'demodata'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        if ( is_admin() ) {
            Admin\DemoDataPage::init();
        }
    }
}
