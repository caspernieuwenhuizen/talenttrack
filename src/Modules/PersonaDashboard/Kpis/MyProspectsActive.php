<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Per-scout: prospects I logged that are still in any
 * non-terminal stage. The scout's personal pipeline tile.
 */
class MyProspectsActive extends AbstractKpiDataSource {
    public function id(): string { return 'my_prospects_active'; }
    public function label(): string { return __( 'My active prospects', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_prospects';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        if ( $user_id <= 0 ) return KpiValue::of( '0' );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE club_id = %d AND archived_at IS NULL
                AND discovered_by_user_id = %d",
            $club_id, $user_id
        ) );
        return KpiValue::of( (string) $count );
    }
}
