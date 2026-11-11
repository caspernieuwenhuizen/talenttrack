<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Shared\Frontend\Components\BackLink;

class MyEvaluationsThisWeek extends AbstractKpiDataSource {
    public function id(): string { return 'my_evaluations_this_week'; }
    public function label(): string { return __( 'My evaluations this week', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    /**
     * The evaluations list, filtered to the evaluations this coach wrote
     * over the same 7 days compute() counts (#1213). #5048 — the card used
     * to open `my-evaluations`, the player and parent view, which refuses a
     * coach. The list accepts `filter[coach_id]` for the caller's own id.
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        return BackLink::appendTo( add_query_arg( [
            'filter' => [
                'coach_id'  => $ctx->user_id,
                'date_from' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
            ],
        ], $ctx->viewUrl( $view ) ) );
    }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        // tt_evaluations (migration 0001) exists on every install; no table check.
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
