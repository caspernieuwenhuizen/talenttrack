<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyTeamAttendancePct extends AbstractKpiDataSource {
    public function id(): string { return 'my_team_attendance_pct'; }
    public function label(): string { return __( 'My team attendance %', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    /**
     * v3.110.165 (#476) — real implementation. Returns the rolling
     * 4-week present-rate across every attendance row recorded against
     * a player on a team the coach head-coaches.
     *
     * Numerator: attendance rows where `LOWER(status) = 'present'`
     * (matches both seeded 'Present' and legacy lowercase data, same
     * pattern as `AttendancePctRolling`).
     *
     * Denominator: every attendance row in the window — i.e. the
     * "expected" count is what's been recorded, not what was scheduled.
     * If a coach hasn't marked attendance on an activity yet, that
     * activity isn't in the denominator. This matches the
     * academy-wide `attendance_pct_rolling` KPI's shape.
     *
     * Scoping:
     *   - club_id via the activities join (canonical filter, same as
     *     the rolling KPI)
     *   - team_id IN (coach's teams) via the players join
     *   - 28-day window (today − 28 days through today, inclusive)
     *
     * Empty states:
     *   - Coach has no teams → unavailable (the KPI doesn't apply).
     *   - Teams but zero attendance rows in window → unavailable.
     */
    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $p = $wpdb->prefix;
        $att = $p . 'tt_attendance';
        $act = $p . 'tt_activities';
        $pl  = $p . 'tt_players';

        // Schema sanity — the rolling KPI's pattern.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $att ) ) !== $att ) return KpiValue::unavailable();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $act ) ) !== $act ) return KpiValue::unavailable();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pl  ) ) !== $pl  ) return KpiValue::unavailable();

        $teams = QueryHelpers::get_teams_for_coach( $user_id );
        if ( empty( $teams ) ) return KpiValue::unavailable();
        $team_ids = array_map( static fn( $t ): int => (int) $t->id, $teams );
        if ( empty( $team_ids ) ) return KpiValue::unavailable();

        $start = gmdate( 'Y-m-d 00:00:00', strtotime( '-28 days' ) );
        $end   = gmdate( 'Y-m-d 23:59:59' );
        $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built from int array.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM( CASE WHEN LOWER(a.status) = 'present' THEN 1 ELSE 0 END ) AS present
              FROM {$att} a
              JOIN {$act} act ON act.id = a.activity_id
              JOIN {$pl}  pl  ON pl.id  = a.player_id
             WHERE act.club_id = %d
               AND pl.team_id IN ({$placeholders})
               AND act.session_date >= %s
               AND act.session_date <= %s",
            array_merge( [ $club_id ], $team_ids, [ $start, $end ] )
        ) );

        if ( ! $row || (int) $row->total === 0 ) return KpiValue::unavailable();

        $pct = round( ( (int) $row->present / (int) $row->total ) * 100, 0 );
        return KpiValue::of( number_format_i18n( $pct, 0 ) . '%' );
    }

    /**
     * v3.110.126 set this empty because the KPI was inert; v3.110.165
     * wires it up. Activities list is the right destination — its
     * coach-scoping filter (`pl.team_id IN coach_teams`) already
     * matches the KPI's compute() scope, so clicking the card lands
     * the coach on a list of exactly the activities feeding the
     * percentage.
     */
    public function linkView(): string { return 'activities'; }
}
