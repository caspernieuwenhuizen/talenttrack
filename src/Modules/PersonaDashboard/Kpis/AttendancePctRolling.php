<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class AttendancePctRolling extends AbstractKpiDataSource {
    public function id(): string { return 'attendance_pct_rolling'; }
    public function label(): string { return __( 'Attendance % (4-week)', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

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
        // Migration 0027 renamed session_id→activity_id; old installs may not have run it yet.
        $att_col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ('activity_id','session_id') LIMIT 1",
            $att_table
        ) );
        if ( ! $att_col ) return KpiValue::unavailable();

        $current_pct = self::pctInRange( $att_table, $act_table, (string) $att_col, '-28 days', 'today' );

        // Sparkline: 4 weekly buckets — each week's present% over its activities.
        $sparkline = [];
        for ( $w = 3; $w >= 0; $w-- ) {
            $from = '-' . ( ( $w + 1 ) * 7 ) . ' days';
            $to   = '-' . ( $w * 7 ) . ' days';
            $sparkline[] = self::pctInRange( $att_table, $act_table, (string) $att_col, $from, $to );
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

    private static function pctInRange( string $att_table, string $act_table, string $att_col, string $from, string $to ): ?float {
        global $wpdb;
        $start = gmdate( 'Y-m-d 00:00:00', strtotime( $from ) );
        $end   = $to === 'today' ? gmdate( 'Y-m-d 23:59:59' ) : gmdate( 'Y-m-d 00:00:00', strtotime( $to ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present
              FROM {$att_table} a
              JOIN {$act_table} act ON act.id = a.{$att_col}
             WHERE act.session_date >= %s AND act.session_date < %s",
            $start, $end
        ) );
        if ( ! $row || (int) $row->total === 0 ) return null;
        return round( ( (int) $row->present / (int) $row->total ) * 100, 1 );
    }
}
