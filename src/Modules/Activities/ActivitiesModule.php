<?php
namespace TT\Modules\Activities;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\ActivitiesRestController;

/**
 * ActivitiesModule (#0035) — coach + admin CRUD for activities.
 *
 * v3.x renames: was `SessionsModule`. The product vocabulary is now
 * "activity" (umbrella for game / training / other), with games
 * further split into friendly / cup / league subtypes via the
 * `game_subtype` lookup. The post-game workflow template fans out
 * automatically when an activity completes with `activity_type_key
 * = 'game'`.
 */
class ActivitiesModule implements ModuleInterface {
    public function getName(): string { return 'activities'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) Admin\ActivitiesPage::init();
        ActivitiesRestController::init();
    }
}
