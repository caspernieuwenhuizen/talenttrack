<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class OpenTrialCases extends AbstractKpiDataSource {
    public function id(): string { return 'open_trial_cases'; }
    public function label(): string { return __( 'Open trial cases', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        // v3.108.5 — was looking up `tt_trials` (which doesn't exist;
        // the actual table is `tt_trial_cases` per migration 0036).
        // SHOW TABLES would always miss → KpiValue::unavailable() and
        // the HoD strip would render "—" instead of the real count.
        // Also missing `club_id` filter; added.
        $table = $wpdb->prefix . 'tt_trial_cases';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'open' AND club_id = %d AND archived_at IS NULL",
            $club_id
        ) );
        return KpiValue::of( (string) $count );
    }
}
