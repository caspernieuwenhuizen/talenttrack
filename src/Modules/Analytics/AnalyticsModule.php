<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Analytics\Domain\DateTimeColumn;
use TT\Modules\Analytics\Domain\Dimension;
use TT\Modules\Analytics\Domain\Fact;
use TT\Modules\Analytics\Domain\Measure;

/**
 * AnalyticsModule (#0083 Child 1) — central catalogue + query engine
 * for declarative reporting.
 *
 * The module owns the registry / engine / value objects. The 8 fact
 * registrations ship inside `boot()` for sequencing simplicity in
 * Child 1; a follow-up moves each to its owning module's `boot()`
 * so the Analytics module doesn't need to know about every other
 * module's tables.
 *
 * Subsequent #0083 children build on top:
 *   - Child 2 (`feat-kpi-platform`) introduces `KpiRegistry` reading
 *     from `FactRegistry`.
 *   - Child 3 (`feat-dimension-explorer`) introduces `?tt_view=explore`
 *     consuming the same engine via `FactQuery::run()`.
 *   - Children 4 + 5 add entity-tab + central analytics views.
 *   - Child 6 adds export + scheduled reports.
 */
class AnalyticsModule implements ModuleInterface {

    public function getName(): string { return 'analytics'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        self::registerInitialFacts();
    }

    /**
     * The 8 facts documented in `specs/0083-epic-reporting-framework.md`
     * §`feat-fact-registry`. Centralised here for Child 1; intended to
     * decentralise (each module declares its own facts at boot) in a
     * follow-up.
     */
    private static function registerInitialFacts(): void {
        // ── attendance ─────────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'attendance',
            tableName:  'tt_attendance',
            label:      __( 'Attendance', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'player_id', __( 'Player', 'talenttrack' ),    Dimension::TYPE_FOREIGN_KEY, 'tt_players' ),
                new Dimension( 'team_id',   __( 'Team', 'talenttrack' ),      Dimension::TYPE_FOREIGN_KEY, 'tt_teams', null, 'a.team_id' ),
                new Dimension( 'activity_type', __( 'Activity type', 'talenttrack' ), Dimension::TYPE_LOOKUP, null, 'activity_type', 'a.session_type' ),
                new Dimension( 'status',    __( 'Attendance status', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'month',     __( 'Month', 'talenttrack' ),     Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(a.start_at, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count_present', __( 'Present', 'talenttrack' ), Measure::AGG_COUNT, "CASE WHEN f.status='present' THEN 1 END" ),
                new Measure( 'count_absent',  __( 'Absent', 'talenttrack' ),  Measure::AGG_COUNT, "CASE WHEN f.status='absent' THEN 1 END" ),
                new Measure( 'attendance_pct', __( 'Attendance %', 'talenttrack' ), Measure::AGG_AVG, "CASE WHEN f.status='present' THEN 100 ELSE 0 END", Measure::UNIT_PERCENT, Measure::FORMAT_PERCENT ),
            ],
            timeColumn:  new DateTimeColumn( 'a.start_at', 'tt_activities a', 'activity_id' ),
            entityScope: 'player',
        ) );

        // ── activities ─────────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'activities',
            tableName:  'tt_activities',
            label:      __( 'Activities', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'team_id',       __( 'Team', 'talenttrack' ),       Dimension::TYPE_FOREIGN_KEY, 'tt_teams' ),
                new Dimension( 'activity_type', __( 'Activity type', 'talenttrack' ), Dimension::TYPE_LOOKUP, null, 'activity_type', 'f.session_type' ),
                new Dimension( 'status',        __( 'Status', 'talenttrack' ),     Dimension::TYPE_ENUM ),
                new Dimension( 'plan_state',    __( 'Plan state', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'month',         __( 'Month', 'talenttrack' ),      Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.start_at, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
                new Measure( 'total_minutes', __( 'Total minutes', 'talenttrack' ), Measure::AGG_SUM, 'f.duration_minutes', Measure::UNIT_MINUTES ),
            ],
            timeColumn:  new DateTimeColumn( 'f.start_at' ),
            entityScope: 'activity',
        ) );

        // ── evaluations ────────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'evaluations',
            tableName:  'tt_evaluations',
            label:      __( 'Evaluations', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'player_id',    __( 'Player', 'talenttrack' ),    Dimension::TYPE_FOREIGN_KEY, 'tt_players' ),
                new Dimension( 'evaluator_id', __( 'Evaluator', 'talenttrack' ), Dimension::TYPE_FOREIGN_KEY, 'wp_users' ),
                new Dimension( 'team_id',      __( 'Team', 'talenttrack' ),      Dimension::TYPE_FOREIGN_KEY, 'tt_teams' ),
                new Dimension( 'month',        __( 'Month', 'talenttrack' ),     Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.created_at, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
            ],
            timeColumn:  new DateTimeColumn( 'f.created_at' ),
            entityScope: 'player',
        ) );

        // ── goals ──────────────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'goals',
            tableName:  'tt_goals',
            label:      __( 'Goals', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'player_id', __( 'Player', 'talenttrack' ), Dimension::TYPE_FOREIGN_KEY, 'tt_players' ),
                new Dimension( 'status',    __( 'Status', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'priority',  __( 'Priority', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'month',     __( 'Month', 'talenttrack' ),  Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.created_at, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
                new Measure( 'count_completed', __( 'Completed', 'talenttrack' ), Measure::AGG_COUNT, "CASE WHEN f.status='completed' THEN 1 END" ),
                new Measure( 'completion_rate', __( 'Completion rate', 'talenttrack' ), Measure::AGG_AVG, "CASE WHEN f.status='completed' THEN 100 ELSE 0 END", Measure::UNIT_PERCENT, Measure::FORMAT_PERCENT ),
            ],
            timeColumn:  new DateTimeColumn( 'f.created_at' ),
            entityScope: 'player',
        ) );

        // ── trial decisions ────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'trial_decisions',
            tableName:  'tt_trial_cases',
            label:      __( 'Trial decisions', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'decision',    __( 'Decision', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'decided_by',  __( 'Decided by', 'talenttrack' ), Dimension::TYPE_FOREIGN_KEY, 'wp_users' ),
                new Dimension( 'month',       __( 'Month', 'talenttrack' ),    Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.decided_at, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
            ],
            timeColumn:  new DateTimeColumn( 'f.decided_at' ),
            entityScope: 'player',
        ) );

        // ── prospects ──────────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'prospects',
            tableName:  'tt_prospects',
            label:      __( 'Prospects', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'discovered_by_user_id', __( 'Discovered by', 'talenttrack' ), Dimension::TYPE_FOREIGN_KEY, 'wp_users' ),
                new Dimension( 'current_club',          __( 'Current club', 'talenttrack' ),  Dimension::TYPE_ENUM ),
                new Dimension( 'month',                 __( 'Month', 'talenttrack' ),         Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.created_at, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
                new Measure( 'count_promoted', __( 'Promoted', 'talenttrack' ), Measure::AGG_COUNT, 'f.promoted_to_player_id' ),
            ],
            timeColumn:  new DateTimeColumn( 'f.created_at' ),
            entityScope: 'player',
        ) );

        // ── journey events ─────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'journey_events',
            tableName:  'tt_player_events',
            label:      __( 'Journey events', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'event_type', __( 'Event type', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'player_id',  __( 'Player', 'talenttrack' ),     Dimension::TYPE_FOREIGN_KEY, 'tt_players' ),
                new Dimension( 'month',      __( 'Month', 'talenttrack' ),      Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.event_date, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
            ],
            timeColumn:  new DateTimeColumn( 'f.event_date' ),
            entityScope: 'player',
        ) );

        // ── evaluations per session (derived coverage) ────────────
        FactRegistry::register( new Fact(
            key:        'evaluations_per_session',
            tableName:  'tt_evaluations',
            label:      __( 'Evaluation coverage', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'evaluator_id', __( 'Evaluator', 'talenttrack' ), Dimension::TYPE_FOREIGN_KEY, 'wp_users' ),
                new Dimension( 'activity_id',  __( 'Activity', 'talenttrack' ),  Dimension::TYPE_FOREIGN_KEY, 'tt_activities' ),
                new Dimension( 'month',        __( 'Month', 'talenttrack' ),     Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(a.start_at, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count_evaluations', __( 'Evaluations', 'talenttrack' ), Measure::AGG_COUNT ),
            ],
            timeColumn:  new DateTimeColumn( 'a.start_at', 'tt_activities a', 'activity_id' ),
            entityScope: 'activity',
        ) );
    }
}
