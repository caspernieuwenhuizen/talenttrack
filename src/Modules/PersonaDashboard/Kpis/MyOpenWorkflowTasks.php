<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyOpenWorkflowTasks extends AbstractKpiDataSource {
    public function id(): string { return 'my_open_workflow_tasks'; }
    public function label(): string { return __( 'My open tasks', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_tasks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        // Stay in lock-step with FrontendMyTasksView::openCountForUser
        // and TasksRepository::listActionableForUser: "actionable" =
        // open / in_progress / overdue, club-scoped, snoozed rows hidden.
        // Without this the dashboard KPI shows "0" while the inbox shows
        // a task that's `in_progress` or `overdue`.
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE assignee_user_id = %d
                AND club_id = %d
                AND status IN ('open','in_progress','overdue')
                AND (snoozed_until IS NULL OR snoozed_until <= %s)",
            $user_id, $club_id, current_time( 'mysql' )
        ) );
        return KpiValue::of( (string) $count );
    }
}
