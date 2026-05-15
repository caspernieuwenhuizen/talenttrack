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
     * statuses) currently in `completed` state. The denominator is
     * EVERY goal row for the club regardless of status (open / in
     * progress / blocked / completed / cancelled / archived); the
     * numerator is goals where `status = 'completed'`. So a club
     * with 100 goals total of which 23 are completed reports 23 %.
     *
     * Scope is `club_id` only — already global for the HoD. There is
     * no coach-scoping on this KPI; HoD and Academy Admin see the same
     * number a Club Admin would.
     *
     * Hardcoded `unavailable` stub through v3.108.4 — implemented in
     * v3.108.5 so the HoD KPI strip stops rendering "—" for this slot.
     *
     * v3.110.112 — docblock clarification only (pilot question: "not
     * sure what this KPI actually does"). No behaviour change.
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
