<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class NewEvaluationsThisWeek extends AbstractKpiDataSource {
    public function id(): string { return 'new_evaluations_this_week'; }
    public function label(): string { return __( 'New evaluations this week', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        // v3.110.182 (#781) — demo-mode scope so the count matches every
        // other evaluation surface under the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );
        $since = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} e WHERE e.created_at >= %s {$scope}",
            $since
        ) );
        return KpiValue::of( (string) $count );
    }
}
