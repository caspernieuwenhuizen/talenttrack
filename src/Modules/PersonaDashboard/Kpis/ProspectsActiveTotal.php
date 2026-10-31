<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Total prospects in any non-terminal stage of the
 * onboarding funnel. The HoD's headline funnel number; anchors the
 * "how big is our pipeline?" question.
 */
class ProspectsActiveTotal extends AbstractKpiDataSource {
    public function id(): string { return 'prospects_active_total'; }
    public function label(): string { return __( 'Active prospects', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_prospects';
        // tt_prospects (migration 0066) exists on every install; no table check.
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE club_id = %d AND archived_at IS NULL",
            $club_id
        ) );
        return KpiValue::of( (string) $count );
    }
}
