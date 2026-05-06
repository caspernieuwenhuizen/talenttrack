<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * #0081 child 3 — Prospects promoted to academy players in the current
 * season. The lagging-indicator for funnel effectiveness; pairs with
 * `prospects_logged_this_month` for the funnel conversion rate.
 *
 * "Current season" is approximated as the trailing 12 months because
 * the seasons table requires more lookup than a quick KPI should run.
 * Operators wanting strict season-aligned numbers can build a dataset-
 * specific KPI in #0083.
 */
class ProspectsPromotedThisSeason extends AbstractKpiDataSource {
    public function id(): string { return 'prospects_promoted_this_season'; }
    public function label(): string { return __( 'Prospects promoted (12 months)', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_prospects';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE club_id = %d AND promoted_to_player_id IS NOT NULL
                AND created_at >= %s",
            $club_id, $cutoff
        ) );
        return KpiValue::of( (string) $count );
    }
}
