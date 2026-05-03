<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AbstractKpiDataSource — base for shipped KPIs.
 *
 * Concrete KPIs declare $id, $label_key, $context as constants and
 * override compute(). Most academy-wide KPIs share the QueryHelpers /
 * audit-log scaffold; the base class doesn't try to be clever about
 * that — each concrete class queries what it needs.
 */
abstract class AbstractKpiDataSource implements KpiDataSource {

    public function id(): string {
        $cls = static::class;
        $parts = explode( '\\', $cls );
        return self::camelToSnake( end( $parts ) );
    }

    public function label(): string {
        return static::class;
    }

    public function context(): string {
        return PersonaContext::ACADEMY;
    }

    abstract public function compute( int $user_id, int $club_id ): KpiValue;

    /**
     * Optional view-slug deep link target for the KPI card. KpiCardWidget
     * wraps the card in an <a href> when non-empty. Concrete KPIs may
     * override; the base class supplies sensible defaults keyed by KPI
     * id so the common case (every shipped KPI gets a click target) is
     * one line of catalogue work, not 25 file edits.
     */
    public function linkView(): string {
        return self::DEFAULT_LINK_VIEWS[ $this->id() ] ?? '';
    }

    /**
     * id → tt_view slug mapping. Empty here means the KPI isn't
     * clickable. Add new entries as KPIs ship.
     *
     * @var array<string,string>
     */
    private const DEFAULT_LINK_VIEWS = [
        // Academy-wide
        'active_players_total'        => 'players',
        'evaluations_this_month'      => 'evaluations',
        'attendance_pct_rolling'      => 'activities',
        'open_trial_cases'            => 'trials',
        'pdp_verdicts_pending'        => 'pdp',
        'goal_completion_pct'         => 'goals',
        'avg_evaluation_rating'       => 'evaluations',
        'players_top_quartile'        => 'players',
        'players_at_risk'             => 'players',
        'new_evaluations_this_week'   => 'evaluations',
        'cohort_distribution'         => 'players',
        'recent_academy_events'       => 'audit-log',
        'goals_by_principle_pct'      => 'goals',
        // Coach-context
        'my_evaluations_this_week'    => 'my-evaluations',
        'my_team_attendance_pct'      => 'my-activities',
        'pdp_planned_vs_conducted_block' => 'pdp',
        'my_open_workflow_tasks'      => 'my-tasks',
        'my_players_evaluated_season' => 'my-evaluations',
        'my_team_avg_rating'          => 'my-team',
        // Player / parent
        'my_rating_trend'             => 'my-evaluations',
        'my_team_podium_position'     => 'my-team',
        'my_goals_completed_season'   => 'my-goals',
        'my_activities_attended_pct'  => 'my-activities',
        'my_evaluations_received'     => 'my-evaluations',
        'my_pdp_conversations_done'   => 'my-pdp',
        'my_next_milestone'           => 'my-pdp',
    ];

    private static function camelToSnake( string $s ): string {
        $s = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $s ) ?? $s;
        return strtolower( $s );
    }
}
