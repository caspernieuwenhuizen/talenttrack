<?php
namespace TT\Modules\PersonaDashboard\Defaults;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Registry\TableRowSourceRegistry;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;
use TT\Modules\PersonaDashboard\TableSources\UpcomingActivitiesSource;
use TT\Modules\PersonaDashboard\Widgets\ActionCardWidget;
use TT\Modules\PersonaDashboard\Widgets\AssignedPlayersGridWidget;
use TT\Modules\PersonaDashboard\Widgets\ChildSwitcherWithRecapWidget;
use TT\Modules\PersonaDashboard\Widgets\DataTableWidget;
use TT\Modules\PersonaDashboard\Widgets\InfoCardWidget;
use TT\Modules\PersonaDashboard\Widgets\KpiCardWidget;
use TT\Modules\PersonaDashboard\Widgets\KpiStripWidget;
use TT\Modules\PersonaDashboard\Widgets\MiniPlayerListWidget;
use TT\Modules\PersonaDashboard\Widgets\NavigationTileWidget;
use TT\Modules\PersonaDashboard\Widgets\QuickActionsPanelWidget;
use TT\Modules\PersonaDashboard\Widgets\RateCardHeroWidget;
use TT\Modules\PersonaDashboard\Widgets\SystemHealthStripWidget;
use TT\Modules\PersonaDashboard\Widgets\TaskListPanelWidget;
use TT\Modules\PersonaDashboard\Widgets\TeamOverviewGridWidget;
use TT\Modules\PersonaDashboard\Widgets\TodayUpNextHeroWidget;

/**
 * CoreWidgets — registers the v1 + #0073 shipped widget types and the
 * shipped table-row sources for DataTableWidget presets.
 */
final class CoreWidgets {

    public static function register(): void {
        WidgetRegistry::register( new NavigationTileWidget() );
        WidgetRegistry::register( new KpiCardWidget() );
        WidgetRegistry::register( new KpiStripWidget() );
        WidgetRegistry::register( new ActionCardWidget() );
        WidgetRegistry::register( new QuickActionsPanelWidget() );
        WidgetRegistry::register( new InfoCardWidget() );
        WidgetRegistry::register( new TaskListPanelWidget() );
        WidgetRegistry::register( new DataTableWidget() );
        WidgetRegistry::register( new MiniPlayerListWidget() );
        WidgetRegistry::register( new RateCardHeroWidget() );
        WidgetRegistry::register( new TodayUpNextHeroWidget() );
        WidgetRegistry::register( new ChildSwitcherWithRecapWidget() );
        WidgetRegistry::register( new SystemHealthStripWidget() );
        WidgetRegistry::register( new AssignedPlayersGridWidget() );
        WidgetRegistry::register( new TeamOverviewGridWidget() );

        TableRowSourceRegistry::register( 'upcoming_activities', new UpcomingActivitiesSource() );
    }
}
