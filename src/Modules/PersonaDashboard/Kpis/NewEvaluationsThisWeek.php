<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class NewEvaluationsThisWeek extends AbstractKpiDataSource {
    public function id(): string { return 'new_evaluations_this_week'; }
    public function label(): string { return __( 'New evaluations this week', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    /** Cutoff used by both compute() + linkUrl() — single source of truth. */
    private static function cutoffDate(): string {
        return gmdate( 'Y-m-d', strtotime( '-7 days' ) );
    }

    /**
     * v4.20.24 (#1210) — Deep-link with `filter[date_from]=<-7d>` so the
     * destination evaluations list matches the KPI 1:1. Lands live in
     * the dominant kpi_card placement via v4.20.22's `KpiCardWidget`
     * → `linkUrl()` routing fix (#1207).
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        return add_query_arg(
            [ 'filter' => [ 'date_from' => self::cutoffDate() ] ],
            $ctx->viewUrl( $view )
        );
    }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        // v3.110.182 (#781) — demo-mode scope so the count matches every
        // other evaluation surface under the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );
        $since = self::cutoffDate() . ' 00:00:00';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} e WHERE e.created_at >= %s {$scope}",
            $since
        ) );
        return KpiValue::of( (string) $count );
    }
}
