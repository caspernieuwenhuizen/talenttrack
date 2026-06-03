<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class RecentAcademyEvents extends AbstractKpiDataSource {
    public function id(): string { return 'recent_academy_events'; }
    public function label(): string { return __( 'Recent academy events', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    /**
     * v4.20.24 (#1210) — Deep-link with `f_date_from=<-30d>`. The
     * audit-log destination uses `f_date_from` (not `filter[date_from]`),
     * so the URL shape diverges from the other academy KPIs. Lands live
     * in the dominant kpi_card placement via v4.20.22's KpiCardWidget
     * → linkUrl() routing fix (#1207).
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        return add_query_arg(
            [ 'f_date_from' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ],
            $ctx->viewUrl( $view )
        );
    }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_events';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $since = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) );
        // v4.20.24 (#1210, bonus) — added the missing `club_id` filter so
        // the count is tenant-scoped. Pre-fix, on a multi-tenant install
        // this KPI aggregated across every tenant. Mirrors the v3.108.5
        // pattern that closed the same gap on `EvaluationsThisMonth`.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE club_id = %d AND created_at >= %s",
            $club_id, $since
        ) );
        return KpiValue::of( (string) $count );
    }
}
