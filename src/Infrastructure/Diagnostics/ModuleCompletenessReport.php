<?php
namespace TT\Infrastructure\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Filesystem-heuristic completeness report for entity-bearing modules
 * (#0077 F4).
 *
 * Each module gets scored on seven facets — list, detail, edit, widget,
 * kpi, docs (en + nl), help-drawer entry. The result is purely
 * informational and gated to WP_DEBUG so it never surfaces in
 * production. Used by the Development module to render a dev tile
 * highlighting module gaps as they appear.
 */
final class ModuleCompletenessReport {

    /**
     * Modules to score — restricted to the entity-bearing surfaces that
     * the player-centric model expects to be complete. Infra-only
     * modules (Auth, Backup, Workflow, etc.) are excluded.
     *
     * @var array<int,array{slug:string,name:string,patterns:array<string,array<int,string>>}>
     */
    private const MODULES = [
        [
            'slug' => 'players', 'name' => 'Players',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendPlayersListView.php', 'src/Modules/Players/Admin/PlayersPage.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendPlayerDetailView.php' ],
                'edit'   => [ 'src/Modules/Wizards/Player/' ],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/MyPlayersWidget.php', 'src/Modules/PersonaDashboard/Widgets/PlayersListWidget.php' ],
                'kpi'    => [ 'src/Modules/PersonaDashboard/Kpis/PlayerCountKpi.php' ],
                'docs_en' => [ 'docs/teams-players.md', 'docs/players.md' ],
                'docs_nl' => [ 'docs/nl_NL/teams-players.md', 'docs/nl_NL/players.md' ],
            ],
        ],
        [
            'slug' => 'teams', 'name' => 'Teams',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendTeamsListView.php', 'src/Modules/Teams/Admin/TeamsPage.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendTeamDetailView.php' ],
                'edit'   => [ 'src/Modules/Wizards/Team/' ],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/MyTeamsWidget.php', 'src/Modules/PersonaDashboard/Widgets/TeamsListWidget.php' ],
                'kpi'    => [ 'src/Modules/PersonaDashboard/Kpis/TeamCountKpi.php' ],
                'docs_en' => [ 'docs/teams-players.md' ],
                'docs_nl' => [ 'docs/nl_NL/teams-players.md' ],
            ],
        ],
        [
            'slug' => 'activities', 'name' => 'Activities',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendActivitiesManageView.php', 'src/Modules/Activities/Admin/ActivitiesPage.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendActivityDetailView.php' ],
                'edit'   => [ 'src/Shared/Frontend/FrontendActivitiesManageView.php', 'src/Modules/Wizards/Activity/' ],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/UpcomingActivitiesWidget.php' ],
                'kpi'    => [ 'src/Modules/PersonaDashboard/Kpis/ActivityCountKpi.php' ],
                'docs_en' => [ 'docs/activities.md' ],
                'docs_nl' => [ 'docs/nl_NL/activities.md' ],
            ],
        ],
        [
            'slug' => 'goals', 'name' => 'Goals',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendGoalsManageView.php', 'src/Modules/Goals/Admin/GoalsPage.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendGoalDetailView.php' ],
                'edit'   => [ 'src/Shared/Frontend/FrontendGoalsManageView.php', 'src/Modules/Wizards/Goal/' ],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/MyGoalsWidget.php', 'src/Modules/PersonaDashboard/Widgets/GoalsListWidget.php' ],
                'kpi'    => [ 'src/Modules/PersonaDashboard/Kpis/GoalCountKpi.php' ],
                'docs_en' => [ 'docs/goals.md' ],
                'docs_nl' => [ 'docs/nl_NL/goals.md' ],
            ],
        ],
        [
            'slug' => 'evaluations', 'name' => 'Evaluations',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendEvaluationsManageView.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendEvaluationDetailView.php' ],
                'edit'   => [ 'src/Modules/Wizards/Evaluation/' ],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/RecentEvaluationsWidget.php' ],
                'kpi'    => [ 'src/Modules/PersonaDashboard/Kpis/EvaluationCountKpi.php' ],
                'docs_en' => [ 'docs/evaluations.md' ],
                'docs_nl' => [ 'docs/nl_NL/evaluations.md' ],
            ],
        ],
        [
            'slug' => 'pdp', 'name' => 'PDP cycle',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendPdpListView.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendPdpDetailView.php' ],
                'edit'   => [ 'src/Modules/Pdp/Admin/' ],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/PdpUpcomingWidget.php' ],
                'kpi'    => [ 'src/Modules/PersonaDashboard/Kpis/PdpCountKpi.php' ],
                'docs_en' => [ 'docs/pdp.md' ],
                'docs_nl' => [ 'docs/nl_NL/pdp.md' ],
            ],
        ],
        [
            'slug' => 'trials', 'name' => 'Trials',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendTrialsManageView.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendTrialCaseView.php' ],
                'edit'   => [ 'src/Shared/Frontend/FrontendTrialsManageView.php' ],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/TrialsNeedingDecisionWidget.php' ],
                'kpi'    => [ 'src/Modules/PersonaDashboard/Kpis/TrialOpenCountKpi.php' ],
                'docs_en' => [ 'docs/trials.md' ],
                'docs_nl' => [ 'docs/nl_NL/trials.md' ],
            ],
        ],
        [
            'slug' => 'reports', 'name' => 'Reports',
            'patterns' => [
                'list'   => [ 'src/Shared/Frontend/FrontendReportsLauncherView.php', 'src/Modules/Reports/Admin/ReportsPage.php' ],
                'detail' => [ 'src/Shared/Frontend/FrontendReportDetailView.php' ],
                'edit'   => [],
                'widget' => [ 'src/Modules/PersonaDashboard/Widgets/RecentReportsWidget.php' ],
                'kpi'    => [],
                'docs_en' => [ 'docs/reports.md' ],
                'docs_nl' => [ 'docs/nl_NL/reports.md' ],
            ],
        ],
    ];

    /**
     * @return array<int,array{slug:string,name:string,facets:array<string,bool>,score:int}>
     */
    public static function report(): array {
        $out = [];
        foreach ( self::MODULES as $m ) {
            $facets = [];
            foreach ( $m['patterns'] as $facet => $paths ) {
                $facets[ $facet ] = self::anyExists( $paths );
            }
            $score = 0;
            foreach ( $facets as $ok ) { if ( $ok ) $score++; }
            $out[] = [
                'slug'   => $m['slug'],
                'name'   => $m['name'],
                'facets' => $facets,
                'score'  => $score,
            ];
        }
        return $out;
    }

    private static function anyExists( array $paths ): bool {
        if ( empty( $paths ) ) return false;
        foreach ( $paths as $rel ) {
            $abs = TT_PLUGIN_DIR . ltrim( $rel, '/' );
            if ( file_exists( $abs ) ) return true;
        }
        return false;
    }
}
