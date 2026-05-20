<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class MyTeamAvgRating extends AbstractKpiDataSource {
    public function id(): string { return 'my_team_avg_rating'; }
    public function label(): string { return __( 'My team average rating', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    /**
     * v3.110.175 (#771) — shared window between compute() and linkUrl()
     * so the deep-link's filter can never drift from the KPI's compute
     * window. Same pattern as `MyTeamAttendancePct`.
     */
    private const WINDOW_DAYS = 90;

    /**
     * v3.110.165 (#477) — real implementation. Returns the average
     * rating across every `tt_eval_ratings` row recorded for a player
     * on a team the coach head-coaches in the last 90 days.
     *
     * 90-day window picked to match the head-coach mental model: an
     * evaluation per player every 1–2 months is the typical rhythm,
     * so 90 days captures roughly the last two assessment cycles.
     * Configurable via the standard rating scale (`rating_max`).
     *
     * Scoping:
     *   - club_id via the evaluations join (e.club_id = %d)
     *   - team_id IN (coach's teams) via the players join
     *   - e.archived_at IS NULL (no archived evaluations)
     *   - 90-day window on e.eval_date
     *
     * Output formatted to 1 decimal place; locale-aware via
     * number_format_i18n.
     *
     * Empty states:
     *   - Coach has no teams → unavailable.
     *   - Teams but zero non-archived rating rows in window → unavailable.
     */
    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $p = $wpdb->prefix;
        $r = $p . 'tt_eval_ratings';
        $e = $p . 'tt_evaluations';
        $pl = $p . 'tt_players';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $r  ) ) !== $r  ) return KpiValue::unavailable();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $e  ) ) !== $e  ) return KpiValue::unavailable();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pl ) ) !== $pl ) return KpiValue::unavailable();

        $teams = QueryHelpers::get_teams_for_coach( $user_id );
        if ( empty( $teams ) ) return KpiValue::unavailable();
        $team_ids = array_map( static fn( $t ): int => (int) $t->id, $teams );
        if ( empty( $team_ids ) ) return KpiValue::unavailable();

        [ 'from' => $cutoff ] = self::windowDates();
        $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

        // v3.110.182 (#781) — demo-mode scope on the evaluation row so
        // this KPI doesn't aggregate over untagged evals when the demo
        // toggle hides them from the list page. The widget bypassing the
        // filter while the list applied it was the original symptom that
        // surfaced this whole audit.
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built from int array.
        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(rat.rating)
               FROM {$r} rat
               JOIN {$e}  e  ON e.id  = rat.evaluation_id
               JOIN {$pl} pl ON pl.id = e.player_id
              WHERE e.club_id = %d
                AND e.archived_at IS NULL
                AND pl.team_id IN ({$placeholders})
                AND e.eval_date >= %s
                {$scope}",
            array_merge( [ $club_id ], $team_ids, [ $cutoff ] )
        ) );

        if ( $avg === null ) return KpiValue::unavailable();

        return KpiValue::of( number_format_i18n( (float) $avg, 1 ) );
    }

    /**
     * v3.110.126 set this empty; v3.110.165 wires it up. Evaluations
     * list is the right destination — its coach-scoping filter
     * (`(pl.team_id IN coach_teams OR e.coach_id = uid)` since
     * v3.110.126) already aligns with the KPI's compute() scope.
     *
     * v3.110.175 (#771): kept for back-compat; linkUrl() below is what
     * KpiCardWidget actually calls and includes the 90-day filter.
     */
    public function linkView(): string { return 'evaluations'; }

    /**
     * v3.110.175 (#771) — deep-link carries `filter[date_from]` so the
     * destination list scopes to the same 90-day window the KPI rolled
     * over. The evaluations REST endpoint filters by `e.eval_date`, the
     * same column compute() uses. `date_to` is omitted because the
     * default destination ordering is "newest first" and there's no
     * upper bound on the compute() window either (today inclusive).
     */
    public function linkUrl( RenderContext $ctx ): string {
        [ 'from' => $from ] = self::windowDates();
        return add_query_arg(
            [ 'filter' => [ 'date_from' => $from ] ],
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
