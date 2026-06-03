<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class MyEvaluationsThisWeek extends AbstractKpiDataSource {
    public function id(): string { return 'my_evaluations_this_week'; }
    public function label(): string { return __( 'My evaluations this week', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    /**
     * v4.20.27 (#1213) — Deep-link with `?days=7` so the destination
     * `my-evaluations` view caps its window to the same 7-day cut
     * compute() aggregates. Pre-fix the destination defaulted to 30d
     * and the row count never matched the KPI's "3 this week" headline.
     * The view now honours the `days` query param (clamped to [1, 90]).
     * Lands live via v4.20.22's KpiCardWidget→linkUrl() routing fix
     * (#1207).
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        return add_query_arg( [ 'days' => 7 ], $ctx->viewUrl( $view ) );
    }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        // Some installs use `coach_id` for evaluation authorship rather than
        // `created_by`. Detect once per call and pick the right column.
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'created_by'",
            $table
        ) );
        $author_col = $col ? 'created_by' : 'coach_id';

        // v3.110.182 (#781) — demo-mode scope so coach's count matches
        // the evaluations list under the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );

        $since = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} e WHERE e.{$author_col} = %d AND e.created_at >= %s {$scope}",
            $user_id,
            $since
        ) );

        // Sparkline: 4 trailing weekly buckets of *my* evaluations.
        $sparkline = [];
        for ( $w = 3; $w >= 0; $w-- ) {
            $start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( ( $w + 1 ) * 7 ) . ' days' ) );
            $end   = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $w * 7 ) . ' days' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sparkline[] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} e WHERE e.{$author_col} = %d AND e.created_at >= %s AND e.created_at < %s {$scope}",
                $user_id, $start, $end
            ) );
        }
        $trend = null;
        if ( count( $sparkline ) >= 2 ) {
            $last = end( $sparkline );
            $prev = $sparkline[ count( $sparkline ) - 2 ];
            $trend = $last > $prev ? 'up' : ( $last < $prev ? 'down' : 'flat' );
        }
        return KpiValue::of( (string) $count, $trend, null, $sparkline );
    }
}
