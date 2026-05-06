<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Discovery rhythm. A month with low scouting activity
 * is its own warning signal.
 */
class ProspectsLoggedThisMonth extends AbstractKpiDataSource {
    public function id(): string { return 'prospects_logged_this_month'; }
    public function label(): string { return __( 'Prospects this month', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_prospects';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $cutoff = gmdate( 'Y-m-01 00:00:00' );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE club_id = %d AND created_at >= %s",
            $club_id, $cutoff
        ) );
        return KpiValue::of( (string) $count );
    }
}
