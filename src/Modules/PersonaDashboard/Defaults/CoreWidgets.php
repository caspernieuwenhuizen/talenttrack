<?php
namespace TT\Modules\PersonaDashboard\Defaults;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Registry\TableRowSourceRegistry;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;
use TT\Modules\PersonaDashboard\TableSources\AuditLogRecentSource;
use TT\Modules\PersonaDashboard\TableSources\BehaviourPendingSource;
use TT\Modules\PersonaDashboard\TableSources\GoalsByPrincipleSource;
use TT\Modules\PersonaDashboard\TableSources\MyRecentProspectsSource;
use TT\Modules\PersonaDashboard\TableSources\RecentScoutReportsSource;
use TT\Modules\PersonaDashboard\TableSources\TrialsNeedingDecisionSource;
use TT\Modules\PersonaDashboard\TableSources\UpcomingActivitiesSource;
use TT\Modules\PersonaDashboard\Widgets\ActionCardWidget;
use TT\Modules\PersonaDashboard\Widgets\AddProspectHeroWidget;
use TT\Modules\PersonaDashboard\Widgets\AssignedPlayersGridWidget;
use TT\Modules\PersonaDashboard\Widgets\ChildSwitcherWithRecapWidget;
use TT\Modules\PersonaDashboard\Widgets\DataTableWidget;
use TT\Modules\PersonaDashboard\Widgets\HodWeekRecapWidget;
use TT\Modules\PersonaDashboard\Widgets\InfoCardWidget;
use TT\Modules\PersonaDashboard\Widgets\KpiCardWidget;
use TT\Modules\PersonaDashboard\Widgets\KpiStripWidget;
use TT\Modules\PersonaDashboard\Widgets\MarkAttendanceHeroWidget;
use TT\Modules\PersonaDashboard\Widgets\MatchesNeedingReviewWidget;
use TT\Modules\PersonaDashboard\Widgets\MiniPlayerListWidget;
use TT\Modules\PersonaDashboard\Widgets\NavigationTileWidget;
use TT\Modules\PersonaDashboard\Widgets\OnboardingPipelineWidget;
use TT\Modules\PersonaDashboard\Widgets\QuickActionsPanelWidget;
use TT\Modules\PersonaDashboard\Widgets\ScoutingPlanWidget;
use TT\Modules\PersonaDashboard\Widgets\RateCardHeroWidget;
use TT\Modules\PersonaDashboard\Widgets\RecentCommentsWidget;
use TT\Modules\PersonaDashboard\Widgets\SystemHealthStripWidget;
use TT\Modules\PersonaDashboard\Widgets\TaskListPanelWidget;
use TT\Modules\PersonaDashboard\Widgets\TeamOverviewGridWidget;
use TT\Modules\PersonaDashboard\Widgets\TeamRosterTableWidget;
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
        // #1374 — HoD "This week at the academy" since-last-visit recap.
        WidgetRegistry::register( new HodWeekRecapWidget() );
        WidgetRegistry::register( new SystemHealthStripWidget() );
        WidgetRegistry::register( new AssignedPlayersGridWidget() );
        WidgetRegistry::register( new TeamOverviewGridWidget() );
        // #0089 A4 — per-team player roster table (First / Last /
        // Status / PDP / Avg attendance). Distinct from the multi-
        // team `team_overview_grid` shipped in #0073; the operator
        // picks a `team_id` in the slot's data_source string.
        WidgetRegistry::register( new TeamRosterTableWidget() );

        // #0081 child 3 — onboarding-pipeline visualisation. Reads
        // existing workflow-task + trial-case data; no new tables.
        WidgetRegistry::register( new OnboardingPipelineWidget() );

        // v3.110.68 — scout-persona dashboard hero. One-tap path to
        // the new-prospect wizard (action #1 in `docs/scout-actions.md`)
        // plus glance stats (logged this month / active in funnel).
        // Replaces `assigned_players_grid` as the scout hero in
        // `CoreTemplates::scout()`.
        WidgetRegistry::register( new AddProspectHeroWidget() );

        // v3.110.119 — scouting plan widget. Shows the next 5 planned
        // scouting visits for the current scout. Pairs with the new
        // `?tt_view=scouting-visits` list / detail surfaces.
        WidgetRegistry::register( new ScoutingPlanWidget() );

        // v3.110.69 (#0092) — head-coach dashboard hero. One-tap entry
        // into the new mark-attendance wizard with the next activity
        // preselected. Replaces `today_up_next_hero` as the default
        // coach hero in `CoreTemplates::coach()`; the older widget
        // stays registered for back-compat with customized templates.
        WidgetRegistry::register( new MarkAttendanceHeroWidget() );
        // #1050 — surfaces match executions in PENDING_REVIEW on the
        // coach hero so they don't get forgotten between End-match
        // and Finalize. Silent (returns empty) when nothing needs
        // review. Pairs with the listing surface #1047.
        WidgetRegistry::register( new MatchesNeedingReviewWidget() );

        // v3.110.113 — academy-wide feed of operator-authored
        // thread messages. Surfaces on the HoD dashboard as a
        // "what's been talked about" pulse alongside the KPI strip.
        // Five most recent non-deleted, non-system rows from
        // `tt_thread_messages`. Cap-gated on `tt_view_threads`.
        WidgetRegistry::register( new RecentCommentsWidget() );

        TableRowSourceRegistry::register( 'upcoming_activities',     new UpcomingActivitiesSource() );
        TableRowSourceRegistry::register( 'trials_needing_decision', new TrialsNeedingDecisionSource() );
        TableRowSourceRegistry::register( 'recent_scout_reports',    new RecentScoutReportsSource() );
        // v3.110.78 — scout-persona "my recent prospects" table source.
        // Replaces `recent_scout_reports` on `CoreTemplates::scout()` row 2.
        // Reports were a PDF-export artifact gated on a cap the working
        // scout doesn't have; what they actually want at row 2 is "the
        // prospects I just logged" with a Show-all link that lands on
        // their kanban (cap `tt_view_prospects`).
        TableRowSourceRegistry::register( 'my_recent_prospects',     new MyRecentProspectsSource() );
        TableRowSourceRegistry::register( 'audit_log_recent',        new AuditLogRecentSource() );
        // #0077 M3 — methodology coverage table for HoD.
        TableRowSourceRegistry::register( 'goals_by_principle',      new GoalsByPrincipleSource() );
        // #871 — behaviour-discoverability sub-ship B. Coach + HoD
        // dashboard widget surfacing players overdue for a rating.
        TableRowSourceRegistry::register( 'behaviour_pending',       new BehaviourPendingSource() );
    }
}
