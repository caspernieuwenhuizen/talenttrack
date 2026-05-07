<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class GoalCompletionPct extends AbstractKpiDataSource {
    public function id(): string { return 'goal_completion_pct'; }
    public function label(): string { return __( 'Goal completion %', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    /**
     * Percentage of all goals (across the club, all players, all
     * statuses) currently in `completed` state. Hardcoded `unavailable`
     * stub through v3.108.4 — implemented in v3.108.5 so the HoD KPI
     * strip stops rendering "—" for this slot.
     *
     * Pilot complaint: "the KPI strip for HoD is completely empty."
     * Two of the six HoD KPIs (this one and `pdp_verdicts_pending`)
     * were `unavailable()` stubs; the other four had `club_id`
     * filters missing or wrong table names.
     */
    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_goals';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        // `tt_goals` doesn't carry an `archived_at` column in the
        // initial schema; the table also lacks `completed_at` (only
        // `updated_at` reflects status changes). Skip the historical
        // sparkline — current snapshot only.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done
              FROM {$table}
              WHERE club_id = %d",
            $club_id
        ) );
        if ( ! $row || (int) $row->total === 0 ) {
            return KpiValue::of( '—' );
        }
        $pct = round( ( (int) $row->done / (int) $row->total ) * 100, 0 );
        return KpiValue::of( number_format_i18n( $pct, 0 ) . '%' );
    }
}
