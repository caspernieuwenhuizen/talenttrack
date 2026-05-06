<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Players who have been offered a team position but
 * haven't yet accepted/declined. Time-sensitive — every day of delay
 * is a recruitment risk.
 */
class TeamOffersPendingResponse extends AbstractKpiDataSource {
    public function id(): string { return 'team_offers_pending_response'; }
    public function label(): string { return __( 'Team offers awaiting response', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_workflow_tasks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE club_id = %d AND template_key = %s
                AND status IN ('open','in_progress','overdue')",
            $club_id, 'await_team_offer_decision'
        ) );
        return KpiValue::of( (string) $count );
    }
}
