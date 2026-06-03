<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class EvaluationsThisMonth extends AbstractKpiDataSource {
    public function id(): string { return 'evaluations_this_month'; }
    public function label(): string { return __( 'Evaluations this month', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    /** Cutoff used by both compute() + linkUrl() — single source of truth. */
    private static function cutoffDate(): string {
        return gmdate( 'Y-m-01' );
    }

    /**
     * v4.20.24 (#1210) — Deep-link with `filter[date_from]=<1st of month>`
     * so the destination evaluations list matches the KPI 1:1. Lands live
     * in the dominant kpi_card placement via v4.20.22's `KpiCardWidget`
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
        $first = self::cutoffDate() . ' 00:00:00';
        // v3.108.5 — added `club_id` filter. Without it the count
        // aggregated across every tenant on the install (returning
        // either 0 on a fresh pilot, or a misleading global total in
        // a multi-tenant test). Sparkline gets the same scope.
        //
        // v3.110.182 (#781) — demo-mode scope so the club-wide count
        // matches every other evaluation surface.
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} e WHERE e.club_id = %d AND e.created_at >= %s {$scope}",
            $club_id, $first
        ) );

        // Sparkline: 4 trailing weekly buckets so the strip + cards
        // render a real trend without per-render history queries.
        $sparkline = [];
        for ( $w = 3; $w >= 0; $w-- ) {
            $start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( ( $w + 1 ) * 7 ) . ' days' ) );
            $end   = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $w * 7 ) . ' days' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sparkline[] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} e WHERE e.club_id = %d AND e.created_at >= %s AND e.created_at < %s {$scope}",
                $club_id, $start, $end
            ) );
        }
        $trend = $this->trendFromSparkline( $sparkline );
        return KpiValue::of( (string) $count, $trend, null, $sparkline );
    }

    /** @param list<float> $values */
    private function trendFromSparkline( array $values ): ?string {
        if ( count( $values ) < 2 ) return null;
        $last = end( $values );
        $prev = $values[ count( $values ) - 2 ];
        if ( $last > $prev ) return 'up';
        if ( $last < $prev ) return 'down';
        return 'flat';
    }
}
