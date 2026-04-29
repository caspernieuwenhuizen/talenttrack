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
        $table = $wpdb->prefix . 'tt_trials';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'open'" );
        return KpiValue::of( (string) $count );
    }
}
