<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Test trainings scheduled in the next 14 days.
 * Operational planning anchor for the HoD.
 */
class TestTrainingsUpcoming extends AbstractKpiDataSource {
    public function id(): string { return 'test_trainings_upcoming'; }
    public function label(): string { return __( 'Upcoming test trainings', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_test_trainings';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $now    = gmdate( 'Y-m-d H:i:s' );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );
        $count  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE club_id = %d AND archived_at IS NULL
                AND date >= %s AND date <= %s",
            $club_id, $now, $cutoff
        ) );
        return KpiValue::of( (string) $count );
    }
}
