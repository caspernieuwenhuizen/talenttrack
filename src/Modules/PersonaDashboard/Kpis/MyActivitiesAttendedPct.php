<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyActivitiesAttendedPct extends AbstractKpiDataSource {
    public function id(): string { return 'my_activities_attended_pct'; }
    public function label(): string { return __( 'My activities attended %', 'talenttrack' ); }
    public function context(): string { return PersonaContext::PLAYER_PARENT; }

    /** Mirror of MyTeamAttendancePct's window + counting universe. */
    private const WINDOW_DAYS = 28;

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

        // #4041 — attended is the one rule (present + late), over the
        // player's own team's activities: guest appearances are left out.
        $attended = AttendanceFlagService::attendedSumSql( 'a.status' );
        // #4086 — only activities that happened, decided by the status the
        // coach set rather than the planner's plan_state.
        $completed = ActivityLifecycle::completedClause( 'act' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — literal SQL from helpers, no user input.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    {$attended} AS present
               FROM {$att} a
               JOIN {$act} act ON act.id = a.activity_id
              WHERE act.club_id = %d
                AND a.player_id = %d
                AND a.record_type = 'actual'
                AND a.is_guest = 0
                AND act.session_date >= %s
                AND act.session_date <= %s
                AND {$completed}
                {$scope}",
            $club_id, $player_id, $from, $to
        ) );

        if ( ! $row || (int) $row->total === 0 ) return KpiValue::unavailable();

        $pct = (float) AttendanceFlagService::presentPct( (int) $row->present, (int) $row->total );
        return KpiValue::of( number_format_i18n( $pct, 0 ) . '%' );
    }
}
