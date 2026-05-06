<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Per-scout: prospects I logged that promoted to an
 * academy player within the trailing 24 months. The scout's "I helped
 * find these players" trophy KPI — and a real coaching-development
 * question for the HoD when reviewing scouts.
 */
class MyProspectsPromoted extends AbstractKpiDataSource {
    public function id(): string { return 'my_prospects_promoted'; }
    public function label(): string { return __( 'My successful scoutings', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_prospects';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        if ( $user_id <= 0 ) return KpiValue::of( '0' );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 730 * DAY_IN_SECONDS );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE club_id = %d AND discovered_by_user_id = %d
                AND promoted_to_player_id IS NOT NULL
                AND created_at >= %s",
            $club_id, $user_id, $cutoff
        ) );
        return KpiValue::of( (string) $count );
    }
}
