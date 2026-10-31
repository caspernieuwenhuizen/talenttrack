<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Open `ReviewTrialGroupMembershipTemplate` tasks. The
 * HoD's "you owe a decision" surface.
 */
class TrialDecisionsPending extends AbstractKpiDataSource {
    public function id(): string { return 'trial_decisions_pending'; }
    public function label(): string { return __( 'Trial decisions pending', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_tasks';
        // tt_workflow_tasks (migration 0021) exists on every install; no table check.
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE club_id = %d AND template_key = %s
                AND status IN ('open','in_progress','overdue')",
            $club_id, 'review_trial_group_membership'
        ) );
        return KpiValue::of( (string) $count );
    }
}
