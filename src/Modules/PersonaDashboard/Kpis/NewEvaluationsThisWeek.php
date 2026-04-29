<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

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
        $since = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
            $since
        ) );
        return KpiValue::of( (string) $count );
    }
}
