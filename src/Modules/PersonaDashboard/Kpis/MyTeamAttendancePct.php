<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Teams\TeamRoster;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class MyTeamAttendancePct extends AbstractKpiDataSource {
    public function id(): string { return 'my_team_attendance_pct'; }
    public function label(): string { return __( 'My team attendance %', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    /**
     * v3.110.175 (#771) — single source of truth for the rolling
     * window used by both `compute()` and `linkUrl()`. Keeping the
     * number on a constant means the deep-link filter can never drift
     * from the compute() window — the bug the pilot reported (KPI card
     * scoped to 28 days but link destination unfiltered) is structurally
     * impossible after this.
     */
    private const WINDOW_DAYS = 28;

    /**
     * v3.110.165 (#476) — real implementation. Returns the rolling
     * 4-week present-rate across every attendance row recorded against
     * a player on a team the coach head-coaches.
     *
     * Numerator: attended rows — present or late (#4041), from
     * `AttendanceFlagService`, case-insensitive. Guest rows are left out.
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
     *   - team_id IN (coach's teams) on the activity, counting the
     *     players currently on that team's roster (#4172)
     *   - 28-day window (today − 28 days through today, inclusive)
     *   - completed activities only, through
     *     `ActivityLifecycle::completedClause()` (#4086) — planned and
     *     cancelled activities don't contribute, even with an attendance
     *     row attached. "Only activities that actually happened count",
     *     decided by the status the coach set, the same rule as the Team
     *     attendance report the card opens.
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

        [ 'from' => $from, 'to' => $to ] = self::windowDates();
        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';
        $team_placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        $completed         = ActivityLifecycle::completedClause( 'act' );

        // v3.110.182 (#781) — demo-mode scope on the activity row so the
        // coach's team attendance % matches the activities list under
        // the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 'act', 'activity' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built from constant arrays.
        // #788 ship 1 — count actuals only; planned-attendance rows
        // (ship 2) are not part of the coach's "what already happened"
        // KPI. The completed-activity filter alone would not keep them
        // out: expected rows can land on a completed activity too.
        //
        // #4041 — attended is the one rule (present + late), guests left out.
        $attended = AttendanceFlagService::attendedSumSql( 'a.status' );
        // #4172 — the coach's teams' activities, counted for the players
        // currently on each team's roster: the figure the Team attendance
        // report behind the card adds up to.
        $on_roster = TeamRoster::memberOfClause( 'pl', 'act.team_id' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                {$attended} AS present
              FROM {$att} a
              JOIN {$act} act ON act.id = a.activity_id
              JOIN {$pl}  pl  ON pl.id  = a.player_id AND {$on_roster}
             WHERE act.club_id = %d
               AND act.team_id IN ({$team_placeholders})
               AND a.record_type = 'actual'
               AND a.is_guest = 0
               AND act.session_date >= %s
               AND act.session_date <= %s
               AND {$completed}
               {$scope}",
            array_merge(
                [ $club_id ],
                $team_ids,
                [ $start, $end ]
            )
        ) );

        if ( ! $row || (int) $row->total === 0 ) return KpiValue::unavailable();

        $pct = (float) AttendanceFlagService::presentPct( (int) $row->present, (int) $row->total );
        return KpiValue::of( number_format_i18n( $pct, 0 ) . '%' );
    }

    /**
     * The KPI opens the Team attendance statistics report
     * (`FrontendAttendanceTeamReportView`), which auto-scopes to the
     * coach's teams and renders the present-rate breakdown — the natural
     * "show me the detail behind this number" destination. (#1592 shipped
     * that report; before it existed, #771 sent the card to the activities
     * list as a stand-in.)
     *
     * linkView is kept for the back-compat default-URL builder, but
     * linkUrl() below is what KpiCardWidget actually calls — it adds the
     * 28-day window so the report opens over the same period the
     * percentage was computed.
     */
    public function linkView(): string { return 'attendance-report-team'; }

    /**
     * #1608 — point the card at the Team attendance statistics report
     * over the SAME 28-day window compute() rolls. The report
     * (`FrontendAttendanceTeamReportView`) reads `from` / `to` and
     * auto-scopes to the coach's teams from `get_teams_for_coach()`, so
     * no explicit team filter is needed on the URL — the destination
     * derives the same scope compute() uses.
     */
    public function linkUrl( RenderContext $ctx ): string {
        [ 'from' => $from, 'to' => $to ] = self::windowDates();
        return add_query_arg(
            [ 'from' => $from, 'to' => $to ],
            $ctx->viewUrl( $this->linkView() )
        );
    }

    /**
     * @return array{from:string,to:string} Date strings in `Y-m-d` (UTC).
     */
    private static function windowDates(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-' . self::WINDOW_DAYS . ' days' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }
}
