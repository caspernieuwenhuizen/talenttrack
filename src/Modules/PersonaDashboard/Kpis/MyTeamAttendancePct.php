<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
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
     * v3.110.177 (#775) — second source-of-truth constant. The KPI
     * counts attendance rows in the window; planned / draft / scheduled
     * / cancelled activities have no business contributing because the
     * coach hasn't run them (or won't). Both `compute()` and `linkUrl()`
     * consume this list so the KPI's universe and the destination
     * filter's universe are guaranteed to match.
     *
     * `completed` — the typical case: the session happened, attendance
     * marked.
     * `in_progress` — coach has begun marking attendance during the
     * session itself. Rows already exist and reflect real presence.
     *
     * Excluded: `draft`, `scheduled`, `planned` (pre-attendance),
     * `cancelled` (post-decision to not run). If any of those somehow
     * has an attendance row attached, the KPI ignores it.
     */
    private const ACTIVITY_STATES_COUNTING = [ 'completed', 'in_progress' ];

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
     *   - v3.110.177 (#775): plan_state IN ('completed', 'in_progress')
     *     — planned / draft / scheduled / cancelled activities don't
     *     contribute (even if they somehow have an attendance row
     *     attached, e.g. a session cancelled after attendance was
     *     marked). Matches the pilot's mental model: "only activities
     *     that actually happened count."
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
        $team_placeholders  = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        $state_placeholders = implode( ',', array_fill( 0, count( self::ACTIVITY_STATES_COUNTING ), '%s' ) );

        // v3.110.182 (#781) — demo-mode scope on the activity row so the
        // coach's team attendance % matches the activities list under
        // the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 'act', 'activity' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built from constant arrays.
        // #788 ship 1 — count actuals only; planned-attendance rows
        // (ship 2) are not part of the coach's "what already happened"
        // KPI. The existing plan_state filter narrows to in_progress +
        // completed but expected rows could land on those too.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM( CASE WHEN LOWER(a.status) = 'present' THEN 1 ELSE 0 END ) AS present
              FROM {$att} a
              JOIN {$act} act ON act.id = a.activity_id
              JOIN {$pl}  pl  ON pl.id  = a.player_id
             WHERE act.club_id = %d
               AND pl.team_id IN ({$team_placeholders})
               AND a.record_type = 'actual'
               AND act.session_date >= %s
               AND act.session_date <= %s
               AND act.plan_state IN ({$state_placeholders})
               {$scope}",
            array_merge(
                [ $club_id ],
                $team_ids,
                [ $start, $end ],
                self::ACTIVITY_STATES_COUNTING
            )
        ) );

        if ( ! $row || (int) $row->total === 0 ) return KpiValue::unavailable();

        $pct = round( ( (int) $row->present / (int) $row->total ) * 100, 0 );
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
