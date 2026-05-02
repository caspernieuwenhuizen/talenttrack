<?php
namespace TT\Modules\PersonaDashboard\Defaults;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Registry\KpiDataSourceRegistry;

/**
 * CoreKpis — registers the 25 v1 shipped KPI data sources.
 */
final class CoreKpis {

    public static function register(): void {
        // Academy-wide (12).
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\ActivePlayersTotal() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\EvaluationsThisMonth() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\AttendancePctRolling() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\OpenTrialCases() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\PdpVerdictsPending() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\GoalCompletionPct() );
        // #0077 M3 — methodology coverage KPI for goals.
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\GoalsByPrincipleKpi() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\AvgEvaluationRating() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\PlayersTopQuartile() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\PlayersAtRisk() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\NewEvaluationsThisWeek() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\CohortDistribution() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\RecentAcademyEvents() );

        // Coach-context (6).
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyEvaluationsThisWeek() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyTeamAttendancePct() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\PdpPlannedVsConductedBlock() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyOpenWorkflowTasks() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyPlayersEvaluatedSeason() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyTeamAvgRating() );

        // Player / parent (7).
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyRatingTrend() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyTeamPodiumPosition() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyGoalsCompletedSeason() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyActivitiesAttendedPct() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyEvaluationsReceived() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyPdpConversationsDone() );
        KpiDataSourceRegistry::register( new \TT\Modules\PersonaDashboard\Kpis\MyNextMilestone() );
    }
}
