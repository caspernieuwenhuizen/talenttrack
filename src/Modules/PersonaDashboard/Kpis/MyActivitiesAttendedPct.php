<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyActivitiesAttendedPct extends AbstractKpiDataSource {
    public function id(): string { return 'my_activities_attended_pct'; }
    public function label(): string { return __( 'My activities attended %', 'talenttrack' ); }
    public function context(): string { return PersonaContext::PLAYER_PARENT; }

    /** Mirror of MyTeamAttendancePct's window + counting universe. */
    private const WINDOW_DAYS = 28;
    private const ACTIVITY_STATES_COUNTING = [ 'completed', 'in_progress' ];

    public function compute( int $user_id, int $club_id ): KpiValue {
        $player_id = PlayerKpiResolver::playerId( $user_id );
        if ( $player_id <= 0 ) return KpiValue::unavailable();

        global $wpdb;
        $p   = $wpdb->prefix;
        $att = $p . 'tt_attendance';
        $act = $p . 'tt_activities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $att ) ) !== $att ) return KpiValue::unavailable();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $act ) ) !== $act ) return KpiValue::unavailable();

        $from  = gmdate( 'Y-m-d', strtotime( '-' . self::WINDOW_DAYS . ' days' ) ) . ' 00:00:00';
        $to    = gmdate( 'Y-m-d' ) . ' 23:59:59';
        $scope = QueryHelpers::apply_demo_scope( 'act', 'activity' );
        $state_placeholders = implode( ',', array_fill( 0, count( self::ACTIVITY_STATES_COUNTING ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built from a constant array.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM( CASE WHEN LOWER(a.status) = 'present' THEN 1 ELSE 0 END ) AS present
               FROM {$att} a
               JOIN {$act} act ON act.id = a.activity_id
              WHERE act.club_id = %d
                AND a.player_id = %d
                AND a.record_type = 'actual'
                AND act.session_date >= %s
                AND act.session_date <= %s
                AND act.plan_state IN ({$state_placeholders})
                {$scope}",
            array_merge( [ $club_id, $player_id, $from, $to ], self::ACTIVITY_STATES_COUNTING )
        ) );

        if ( ! $row || (int) $row->total === 0 ) return KpiValue::unavailable();

        $pct = round( ( (int) $row->present / (int) $row->total ) * 100, 0 );
        return KpiValue::of( number_format_i18n( $pct, 0 ) . '%' );
    }
}
