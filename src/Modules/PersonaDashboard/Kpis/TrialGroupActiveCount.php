<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Players currently in the trial group (open trial
 * cases with `decision = continue_in_trial_group`). Drives the per-
 * age-group capacity conversation.
 */
class TrialGroupActiveCount extends AbstractKpiDataSource {
    public function id(): string { return 'trial_group_active_count'; }
    public function label(): string { return __( 'Players in trial group', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_trial_cases';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT player_id) FROM {$table}
              WHERE club_id = %d AND archived_at IS NULL AND decision = %s",
            $club_id, 'continue_in_trial_group'
        ) );
        return KpiValue::of( (string) $count );
    }
}
