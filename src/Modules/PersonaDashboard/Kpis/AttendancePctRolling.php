<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class AttendancePctRolling extends AbstractKpiDataSource {
    public function id(): string { return 'attendance_pct_rolling'; }
    public function label(): string { return __( 'Attendance % (4-week)', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    /**
     * v4.20.24 (#1210) — Deep-link with `filter[date_from]=<-28d>` +
     * `filter[date_to]=<today>` + `filter[plan_state]=completed` so the
     * destination activities list matches the KPI's 4-week completed
     * window 1:1. Lands live in the dominant kpi_card placement via
     * v4.20.22's `KpiCardWidget` → `linkUrl()` routing fix (#1207).
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        return add_query_arg(
            [ 'filter' => [
                'date_from'  => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
                'date_to'    => gmdate( 'Y-m-d' ),
                'plan_state' => 'completed',
            ] ],
            $ctx->viewUrl( $view )
        );
    }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $att_table  = $wpdb->prefix . 'tt_attendance';
        $act_table  = $wpdb->prefix . 'tt_activities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $att_table ) ) !== $att_table ) {
            return KpiValue::unavailable();
        }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $act_table ) ) !== $act_table ) {
            return KpiValue::unavailable();
        }
        // tt_attendance.activity_id has been the canonical column since
        // migration 0027 (#0035 sessions → activities rename). Verify the
        // column exists; very old installs that haven't run migrations
        // get unavailable() and admins are nudged via SchemaStatus.
        $has_activity_col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'activity_id'",
            $att_table
        ) );
        if ( ! $has_activity_col ) return KpiValue::unavailable();

        // v3.108.5 — `club_id` threaded through so the rolling
        // percentage is tenant-scoped. Without it the KPI averaged
        // across every install on a multi-tenant deployment, and on a
        // single-tenant pilot it was a no-op but inconsistent with the
        // rest of the registry.
        $current_pct = self::pctInRange( $att_table, $act_table, '-28 days', 'today', $club_id );

        // Sparkline: 4 weekly buckets — each week's present% over its activities.
        $sparkline = [];
        for ( $w = 3; $w >= 0; $w-- ) {
            $from = '-' . ( ( $w + 1 ) * 7 ) . ' days';
            $to   = '-' . ( $w * 7 ) . ' days';
            $sparkline[] = self::pctInRange( $att_table, $act_table, $from, $to, $club_id );
        }

        if ( $current_pct === null ) return KpiValue::unavailable();

        $trend = null;
        if ( count( $sparkline ) >= 2 ) {
            $last = end( $sparkline );
            $prev = $sparkline[ count( $sparkline ) - 2 ];
            if ( $last !== null && $prev !== null ) {
                $trend = $last > $prev ? 'up' : ( $last < $prev ? 'down' : 'flat' );
            }
        }
        $sparkline = array_map( static fn( ?float $v ): float => $v === null ? 0.0 : $v, $sparkline );
        return KpiValue::of( number_format_i18n( $current_pct, 0 ) . '%', $trend, null, $sparkline );
    }

    private static function pctInRange( string $att_table, string $act_table, string $from, string $to, int $club_id ): ?float {
        global $wpdb;
        $start = gmdate( 'Y-m-d 00:00:00', strtotime( $from ) );
        $end   = $to === 'today' ? gmdate( 'Y-m-d 23:59:59' ) : gmdate( 'Y-m-d 00:00:00', strtotime( $to ) );
        // v3.110.182 (#781) — demo-mode scope on the activity row so the
        // rolling % matches the activities list / attendance pages.
        $scope = QueryHelpers::apply_demo_scope( 'act', 'activity' );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // v3.110.3 — `LOWER(a.status)='present'` to match both the
        // seeded capitalised lookup values ('Present') and any legacy
        // lowercase data from the v2.x present-int → status-string
        // backfill in `Activator::installSchema`.
        // #788 ship 1 — filter to actual rows on completed activities so
        // expected-attendance rows (added by ship 2) don't pollute the
        // rolling percentage. Mirrors the same fix shipped on
        // `MyTeamAttendancePct` in v3.110.177.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN LOWER(a.status) = 'present' THEN 1 ELSE 0 END) AS present
              FROM {$att_table} a
              JOIN {$act_table} act ON act.id = a.activity_id
             WHERE act.club_id = %d AND act.session_date >= %s AND act.session_date < %s
               AND a.record_type = 'actual'
               AND act.plan_state = 'completed' {$scope}",
            $club_id, $start, $end
        ) );
        if ( ! $row || (int) $row->total === 0 ) return null;
        return round( ( (int) $row->present / (int) $row->total ) * 100, 1 );
    }
}
