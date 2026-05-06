<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Prospects with no task progress in > 30 days. The
 * single most-actionable KPI on the HoD dashboard — every stale
 * prospect is either a missed opportunity or a GDPR liability (the
 * retention cron will eventually purge them).
 */
class ProspectsStaleCount extends AbstractKpiDataSource {
    public function id(): string { return 'prospects_stale_count'; }
    public function label(): string { return __( 'Stale prospects', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $prospects_table = $wpdb->prefix . 'tt_prospects';
        $tasks_table     = $wpdb->prefix . 'tt_workflow_tasks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prospects_table ) ) !== $prospects_table ) {
            return KpiValue::unavailable();
        }
        $threshold = (int) get_option( 'tt_prospect_stale_threshold_days', 30 );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $threshold * DAY_IN_SECONDS );

        $tasks_have_prospect = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$tasks_table} LIKE %s", 'prospect_id'
        ) ) === 'prospect_id';

        if ( $tasks_have_prospect ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$prospects_table} pr
                  WHERE pr.club_id = %d
                    AND pr.archived_at IS NULL
                    AND pr.promoted_to_player_id IS NULL
                    AND NOT EXISTS (
                          SELECT 1 FROM {$tasks_table} wt
                           WHERE wt.prospect_id = pr.id
                             AND wt.completed_at IS NOT NULL
                             AND wt.completed_at >= %s
                    )
                    AND NOT EXISTS (
                          SELECT 1 FROM {$tasks_table} wt2
                           WHERE wt2.prospect_id = pr.id
                             AND wt2.status IN ('open','in_progress')
                    )",
                $club_id, $cutoff
            ) );
        } else {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$prospects_table}
                  WHERE club_id = %d AND archived_at IS NULL
                    AND promoted_to_player_id IS NULL AND created_at < %s",
                $club_id, $cutoff
            ) );
        }
        return KpiValue::of( (string) $count );
    }
}
