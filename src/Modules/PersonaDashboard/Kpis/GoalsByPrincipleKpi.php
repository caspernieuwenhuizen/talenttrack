<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * GoalsByPrincipleKpi — number of goals tagged to a methodology
 * principle in the rolling 90-day window (#0077 M3).
 *
 * Reports the share + absolute count of recent goals that have
 * `linked_principle_id` set. Useful for HoD / academy admin to spot
 * coaches still creating uncategorised goals.
 */
class GoalsByPrincipleKpi extends AbstractKpiDataSource {

    public function id(): string { return 'goals_by_principle_pct'; }
    public function label(): string { return __( 'Goals tagged to principle (90d)', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_goals';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'linked_principle_id'",
            $table
        ) );
        if ( $col === null ) return KpiValue::unavailable();

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
        // v3.110.182 (#781) — demo-mode scope so the ratio matches the
        // goals list / detail surfaces under the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 'g', 'goal' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} g WHERE g.club_id = %d AND g.created_at >= %s {$scope}",
            $club_id, $cutoff
        ) );
        if ( $total === 0 ) return KpiValue::of( '0%' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $tagged = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} g WHERE g.club_id = %d AND g.created_at >= %s AND g.linked_principle_id IS NOT NULL {$scope}",
            $club_id, $cutoff
        ) );
        $pct = (int) round( ( $tagged / $total ) * 100 );
        return KpiValue::of( $pct . '% (' . $tagged . '/' . $total . ')' );
    }
}
