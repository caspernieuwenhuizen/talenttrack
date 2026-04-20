<?php
namespace TT\Modules\Stats;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * StatsModule — statistics, rate cards, analytics.
 *
 * Sprint 2A (v2.14.0). First addition: player rate cards at
 * TalentTrack → Player Rate Cards, also embedded in the Players edit
 * page. Menu registration lives in Shared\Admin\Menu; this module
 * just wires the admin_enqueue_scripts handler.
 */
class StatsModule implements ModuleInterface {
    public function getName(): string { return 'stats'; }
    public function register( Container $container ): void {}
    public function boot( Container $container ): void {
        if ( is_admin() ) {
            Admin\PlayerRateCardsPage::init();
        }
    }
}
