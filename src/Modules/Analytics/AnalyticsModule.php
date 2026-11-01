<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Domain\DateTimeColumn;
use TT\Modules\Analytics\Domain\Dimension;
use TT\Modules\Analytics\Domain\Fact;
use TT\Modules\Analytics\Domain\Kpi;
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

    /**
     * #1484 — the ad-hoc **Analytics Explorer** UI (the dashboard tile +
     * the `analytics` / `explore` / `scheduled-reports` views) is a
     * feature switch, independent of the module itself. The module always
     * stays on so the engine — facts, KPIs, the data the attendance /
     * minutes / standard reports query — keeps running; this only governs
     * whether the operator-facing ad-hoc explorer surface is shown.
     * Default OFF.
     */
    public const EXPLORER_CONFIG_KEY = 'analytics_explorer_enabled';

    /**
     * #1537 — the explorer toggle now lives in `FeatureRegistry` under the
     * `analytics_explorer` feature key; migration 0166 carried the prior
     * `analytics_explorer_enabled` config value into `tt_feature_state`.
     * The legacy `EXPLORER_CONFIG_KEY` + `setExplorerEnabled()` remain as a
     * thin shim so any caller predating the migration keeps working.
     */
    public const FEATURE_KEY = 'analytics_explorer';

    public static function explorerEnabled(): bool {
        return \TT\Core\FeatureRegistry::isEnabled( self::FEATURE_KEY );
    }

    public static function setExplorerEnabled( bool $on ): void {
        \TT\Core\FeatureRegistry::setEnabled( self::FEATURE_KEY, $on );
    }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        self::registerInitialFacts();
        self::registerInitialKpis();
        // #1063 standard-reports presets (closes #1096-#1101). Six
        // explorer-bound KPIs whose key matches the mockup vocabulary,
        // so the URL on the live "Explorer →" button is the URL the
        // pilot saw in the mockup.
        self::registerStandardReportPresetKpis();
        // #0083 Child 6 — scheduled-reports admin-post handlers + daily cron.
        \TT\Modules\Analytics\Admin\ScheduledReportsActionHandlers::init();
        \TT\Modules\Analytics\Cron\ScheduledReportsRunner::init();
        // #1367 — REST surface for the coach-evaluation-quality report
        // (the PHP renderer + this endpoint share CoachEvalQualityQuery).
        \TT\Infrastructure\REST\ReportsRestController::init();
        // #1380 — evaluation-window coverage report (HoD). REST surface
        // shares EvalCoverageService with the PHP renderer.
        \TT\Infrastructure\REST\EvalCoverageRestController::init();
        // #1383 — REST surface for the end-of-season cohort decision board
        // (the PHP renderer + this endpoint share CohortBoardService).
        \TT\Infrastructure\REST\CohortBoardRestController::init();
        // #3517 — taking a monthly-report snapshot and writing its section
        // notes. On `template_redirect` so it runs before any output and can
        // redirect after the post; every handler checks the capability for the
        // snapshot's own team and refuses a signed-out reader.
        add_action( 'template_redirect', [ \TT\Modules\Analytics\Frontend\TeamMonthlyReportSnapshotPage::class, 'handlePost' ], 5 );
        // #3890 — the same for a player report, checked on the snapshot's player.
        add_action( 'template_redirect', [ \TT\Modules\Analytics\Frontend\PlayerReportSnapshotPage::class, 'handlePost' ], 5 );
    }

    /**
     * The 8 facts documented in `specs/0083-epic-reporting-framework.md`
     * §`feat-fact-registry`. Centralised here for Child 1; intended to
     * decentralise (each module declares its own facts at boot) in a
     * follow-up.
     */
    private static function registerInitialFacts(): void {
        // ── attendance ─────────────────────────────────────────────
        // v3.110.3 — facts were authored against an idealised schema
        // (`a.start_at`, `a.session_type`, lowercase `status='present'`)
        // that doesn't match the actual one. The real schema uses
        // `session_date` for the time column on tt_activities and
        // `activity_type_key` for the activity-type lookup join key,
        // and the write paths (`ActivitiesRestController::write_attendance`,
        // `AttendanceStep::validate`) store status values capitalised
        // ('Present' / 'Absent' / 'Late' / 'Injured' / 'Excused' per
        // the seeded `attendance_status` lookup). The 30-day attendance
        // KPI on the player Analytics tab returned no data because the
        // CASE expression matched zero rows.
        $countable = AttendanceFlagService::countableRowClause( 'f', 'a', 'p' );
        FactRegistry::register( new Fact(
            key:        'attendance',
            tableName:  'tt_attendance',
            label:      __( 'Attendance', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'player_id', __( 'Player', 'talenttrack' ),    Dimension::TYPE_FOREIGN_KEY, 'tt_players' ),
                new Dimension( 'team_id',   __( 'Team', 'talenttrack' ),      Dimension::TYPE_FOREIGN_KEY, 'tt_teams', null, 'a.team_id' ),
                new Dimension( 'activity_type', __( 'Activity type', 'talenttrack' ), Dimension::TYPE_LOOKUP, null, 'activity_type', 'a.activity_type_key' ),
                new Dimension( 'status',    __( 'Attendance status', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'month',     __( 'Month', 'talenttrack' ),     Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(a.session_date, '%Y-%m')" ),
                // ISO week, e.g. 2026-W07, so a per-week block reads the
                // same rows and measures as every other attendance figure.
                new Dimension( 'week',      __( 'Week', 'talenttrack' ),      Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(a.session_date, '%x-W%v')" ),
            ],
            // #3451 — the MEASURES are scoped to the recorded register, the
            // FACT is not. A planned squad stores Expected as `Present`, so
            // an unscoped "Present" count answered a question about
            // selections, not about who turned up — the same defect the
            // migration 0121 reporting sweep closed everywhere else and this
            // fact was written after.
            //
            // Scoping the measure rather than the fact is deliberate: the
            // data browser exists to slice arbitrary rows, and a base filter
            // would hide the plan from a tool whose whole purpose is showing
            // what is there. The numbers are what had to be right.
            //
            // #4584 — the measures also count only the rows the attendance
            // reports count: a live, completed, past activity and a player on
            // that team's roster, through the clause AttendanceRankingQuery
            // reads. The condition is written positively so the LEFT JOINed
            // activity being absent leaves the row out instead of letting a
            // NULL fall through to the next WHEN.
            measures: [
                new Measure( 'count_present', __( 'Present', 'talenttrack' ), Measure::AGG_COUNT, "CASE WHEN LOWER(f.status)='present' AND f.record_type='actual' AND " . $countable . ' THEN 1 END' ),
                new Measure( 'count_absent',  __( 'Absent', 'talenttrack' ),  Measure::AGG_COUNT, "CASE WHEN LOWER(f.status)='absent' AND f.record_type='actual' AND " . $countable . ' THEN 1 END' ),
                // #4568 — "distinct" is a declared measure, never composed by a reader.
                new Measure( 'distinct_players', __( 'Players recorded', 'talenttrack' ), Measure::AGG_COUNT, "DISTINCT CASE WHEN f.record_type='actual' AND f.is_guest = 0 AND " . $countable . ' THEN f.player_id END' ),
                // The percentage needs the planned rows out of the
                // DENOMINATOR as well, so they resolve to NULL rather than
                // to 0 — `AVG` skips NULLs, and a selection scoring 0%
                // attendance would be the same error upside down.
                //
                // #4041 — the one rule from AttendanceFlagService: attended
                // is present or late, and guest rows are left out of the
                // denominator too. Changed in place rather than versioned, so
                // attendance figures in saved explorations move.
                new Measure( 'attendance_pct', __( 'Attendance %', 'talenttrack' ), Measure::AGG_AVG, "CASE WHEN f.record_type = 'actual' AND f.is_guest = 0 AND " . $countable . ' THEN CASE WHEN ' . AttendanceFlagService::attendedStatusClause( 'f.status' ) . ' THEN 100 ELSE 0 END END', Measure::UNIT_PERCENT, Measure::FORMAT_PERCENT ),
            ],
            timeColumn:  new DateTimeColumn( 'a.session_date', 'tt_activities a', 'activity_id' ),
            entityScope: 'player',
            readEntity:  'attendance',
            teamColumn:  'a.team_id',
            playerColumn: 'f.player_id',
        ) );

        // ── activities ─────────────────────────────────────────────
        FactRegistry::register( new Fact(
            key:        'activities',
            tableName:  'tt_activities',
            label:      __( 'Activities', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'team_id',       __( 'Team', 'talenttrack' ),       Dimension::TYPE_FOREIGN_KEY, 'tt_teams' ),
                new Dimension( 'activity_type', __( 'Activity type', 'talenttrack' ), Dimension::TYPE_LOOKUP, null, 'activity_type', 'f.activity_type_key' ),
                new Dimension( 'status',        __( 'Status', 'talenttrack' ),     Dimension::TYPE_ENUM ),
                new Dimension( 'plan_state',    __( 'Plan state', 'talenttrack' ), Dimension::TYPE_ENUM ),
                new Dimension( 'month',         __( 'Month', 'talenttrack' ),      Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.session_date, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
                new Measure( 'total_minutes', __( 'Total minutes', 'talenttrack' ), Measure::AGG_SUM, 'f.duration_minutes', Measure::UNIT_MINUTES ),
            ],
            timeColumn:  new DateTimeColumn( 'f.session_date' ),
            entityScope: 'activity',
            readEntity:  'activities',
            teamColumn:  'f.team_id',
        ) );

        // ── evaluations ────────────────────────────────────────────
        global $wpdb;
        $eval_team = "COALESCE("
            . "(SELECT NULLIF(ea.team_id, 0) FROM {$wpdb->prefix}tt_activities ea WHERE ea.id = f.activity_id AND ea.club_id = f.club_id), "
            . "(SELECT h.team_id FROM {$wpdb->prefix}tt_player_team_history h WHERE h.player_id = f.player_id AND h.club_id = f.club_id"
            . " AND h.joined_at <= f.eval_date AND ( h.left_at IS NULL OR h.left_at >= f.eval_date ) ORDER BY h.joined_at DESC, h.id DESC LIMIT 1))";
        FactRegistry::register( new Fact(
            key:        'evaluations',
            tableName:  'tt_evaluations',
            label:      __( 'Evaluations', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'player_id',    __( 'Player', 'talenttrack' ),    Dimension::TYPE_FOREIGN_KEY, 'tt_players' ),
                new Dimension( 'evaluator_id', __( 'Evaluator', 'talenttrack' ), Dimension::TYPE_FOREIGN_KEY, 'wp_users', null, 'f.coach_id' ),
                // `tt_evaluations` has no team column. The team is the one
                // at evaluation time: the evaluation's activity's team, else
                // the player's team on the evaluation date from the team
                // history, else none ("No team").
                new Dimension( 'team_id',      __( 'Team', 'talenttrack' ),      Dimension::TYPE_FOREIGN_KEY, 'tt_teams', null, $eval_team ),
                new Dimension( 'month',        __( 'Month', 'talenttrack' ),     Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(f.eval_date, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count', __( 'Count', 'talenttrack' ), Measure::AGG_COUNT ),
                new Measure( 'distinct_players', __( 'Players evaluated', 'talenttrack' ), Measure::AGG_COUNT, 'DISTINCT f.player_id' ),
            ],
            timeColumn:  new DateTimeColumn( 'f.eval_date' ),
            entityScope: 'player',
            readEntity:  'evaluations',
            playerColumn: 'f.player_id',
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
            readEntity:  'goals',
            playerColumn: 'f.player_id',
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
            // No team column to narrow on: club-wide readers only (#4568).
            readEntity:  'trial_cases',
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
            // No team column to narrow on: club-wide readers only (#4568).
            readEntity:  'prospects',
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
            readEntity:  'player_timeline',
            playerColumn: 'f.player_id',
            visibilityColumn: 'f.visibility',
        ) );

        // ── evaluations per session (derived coverage) ────────────
        FactRegistry::register( new Fact(
            key:        'evaluations_per_session',
            tableName:  'tt_evaluations',
            label:      __( 'Evaluation coverage', 'talenttrack' ),
            tableAlias: 'f',
            dimensions: [
                new Dimension( 'evaluator_id', __( 'Evaluator', 'talenttrack' ), Dimension::TYPE_FOREIGN_KEY, 'wp_users', null, 'f.coach_id' ),
                new Dimension( 'activity_id',  __( 'Activity', 'talenttrack' ),  Dimension::TYPE_FOREIGN_KEY, 'tt_activities' ),
                new Dimension( 'month',        __( 'Month', 'talenttrack' ),     Dimension::TYPE_DATE_RANGE, null, null, "DATE_FORMAT(a.session_date, '%Y-%m')" ),
            ],
            measures: [
                new Measure( 'count_evaluations', __( 'Evaluations', 'talenttrack' ), Measure::AGG_COUNT ),
            ],
            timeColumn:  new DateTimeColumn( 'a.session_date', 'tt_activities a', 'activity_id' ),
            entityScope: 'activity',
            readEntity:  'evaluations',
            playerColumn: 'f.player_id',
        ) );
    }

    /**
     * #0083 Child 2 — initial fact-driven KPI set.
     *
     * Six reference KPIs that exercise the platform end-to-end against
     * the fact registry. Bulk migration of the 26 legacy KPIs in
     * `Modules\PersonaDashboard\Kpis\` to fact-driven `Kpi`
     * declarations is a follow-up — they keep working unchanged
     * through the legacy `KpiDataSourceRegistry`, resolved via
     * `KpiResolver::value()`'s back-compat fallback.
     *
     * The 55 new KPIs from the spec (15 player + 15 team + 10 activity
     * + 10 season + 5 scout) ship in successive follow-ups. Six is
     * enough to validate the platform without forcing a sprint of
     * KPI authoring inside Child 2.
     */
    private static function registerInitialKpis(): void {
        // Player-scoped: attendance percentage over the last 30 days.
        KpiRegistry::register( new Kpi(
            key:               'fact_player_attendance_pct_30d',
            label:             __( 'Attendance % (30 days)', 'talenttrack' ),
            factKey:           'attendance',
            measureKey:        'attendance_pct',
            defaultFilters:    [ 'date_after' => '-30 days' ],
            primaryDimension:  'month',
            exploreDimensions: [ 'team_id', 'player_id', 'activity_type' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            threshold:         70.0,
            entityScope:       'player',
        ) );

        // Player-scoped: evaluation count over the last 30 days.
        KpiRegistry::register( new Kpi(
            key:               'fact_player_evaluations_count_30d',
            label:             __( 'Evaluations received (30 days)', 'talenttrack' ),
            factKey:           'evaluations',
            measureKey:        'count',
            defaultFilters:    [ 'date_after' => '-30 days' ],
            primaryDimension:  'month',
            exploreDimensions: [ 'evaluator_id', 'team_id', 'player_id' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            entityScope:       'player',
        ) );

        // Player-scoped: goal completion rate.
        KpiRegistry::register( new Kpi(
            key:               'fact_player_goal_completion_rate',
            label:             __( 'Goal completion rate', 'talenttrack' ),
            factKey:           'goals',
            measureKey:        'completion_rate',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [ 'player_id', 'priority' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            threshold:         50.0,
            entityScope:       'player',
        ) );

        // Activity-scoped: count of activities per team over the season.
        KpiRegistry::register( new Kpi(
            key:               'fact_activity_count_30d',
            label:             __( 'Activities (30 days)', 'talenttrack' ),
            factKey:           'activities',
            measureKey:        'count',
            defaultFilters:    [ 'date_after' => '-30 days' ],
            primaryDimension:  'month',
            exploreDimensions: [ 'team_id', 'activity_type' ],
            context:           Kpi::CONTEXT_ACADEMY,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            entityScope:       'activity',
        ) );

        // Academy-wide: prospects logged this season.
        KpiRegistry::register( new Kpi(
            key:               'fact_academy_prospects_logged_30d',
            label:             __( 'Prospects logged (30 days)', 'talenttrack' ),
            factKey:           'prospects',
            measureKey:        'count',
            defaultFilters:    [ 'date_after' => '-30 days' ],
            primaryDimension:  'month',
            exploreDimensions: [ 'discovered_by_user_id' ],
            context:           Kpi::CONTEXT_ACADEMY,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            entityScope:       null,
        ) );

        // Player-parent-facing: their child's goal completion (no
        // sensitive fields exposed; same data, different audience).
        KpiRegistry::register( new Kpi(
            key:               'fact_my_player_goal_completion_rate',
            label:             __( 'My goal completion', 'talenttrack' ),
            factKey:           'goals',
            measureKey:        'completion_rate',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [],
            context:           Kpi::CONTEXT_PLAYER_PARENT,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            threshold:         50.0,
            entityScope:       'player',
        ) );
    }

    /**
     * #1063 standard-reports preset KPIs (#1096-#1101).
     *
     * Six explorer-bound KPIs, one per preset. Keys match the mockup
     * vocabulary in `.local-mockups/standard-reports/<persona>/<slug>.html`
     * so the URL on the live "Explorer →" button is the URL the
     * pilot saw in the mockup. All defaults are intentionally empty —
     * the preset's entry-point call site builds the URL with the
     * relevant entity-scoped filter pre-applied.
     */
    private static function registerStandardReportPresetKpis(): void {
        // #1096 — Player Evaluations received
        KpiRegistry::register( new Kpi(
            key:               'evaluations_received',
            label:             __( 'Evaluations received', 'talenttrack' ),
            factKey:           'evaluations',
            measureKey:        'count',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [ 'evaluator_id', 'team_id', 'player_id' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            entityScope:       'player',
        ) );

        // #1097 — Player Goal progress
        KpiRegistry::register( new Kpi(
            key:               'goal_progress',
            label:             __( 'Goal progress', 'talenttrack' ),
            factKey:           'goals',
            measureKey:        'completion_rate',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [ 'player_id', 'priority', 'status' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            threshold:         50.0,
            entityScope:       'player',
        ) );

        // #1098 — Team Activity volume
        KpiRegistry::register( new Kpi(
            key:               'activity_volume',
            label:             __( 'Activity volume', 'talenttrack' ),
            factKey:           'activities',
            measureKey:        'count',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [ 'team_id', 'activity_type', 'status' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            entityScope:       'activity',
        ) );

        // #1099 — Activity Evaluation coverage
        KpiRegistry::register( new Kpi(
            key:               'evaluation_coverage',
            label:             __( 'Evaluation coverage', 'talenttrack' ),
            factKey:           'evaluations_per_session',
            measureKey:        'count_evaluations',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [ 'evaluator_id', 'activity_id' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            entityScope:       'activity',
        ) );

        // #1100 — Activity Attendance vs squad
        KpiRegistry::register( new Kpi(
            key:               'attendance_vs_squad',
            label:             __( 'Attendance vs squad', 'talenttrack' ),
            factKey:           'attendance',
            measureKey:        'attendance_pct',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [ 'team_id', 'player_id', 'status', 'activity_type' ],
            context:           Kpi::CONTEXT_COACH,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            threshold:         70.0,
            entityScope:       'activity',
        ) );

        // #1101 — Season Prospects logged per scout
        KpiRegistry::register( new Kpi(
            key:               'prospects_logged_per_scout',
            label:             __( 'Prospects logged per scout', 'talenttrack' ),
            factKey:           'prospects',
            measureKey:        'count',
            defaultFilters:    [],
            primaryDimension:  'month',
            exploreDimensions: [ 'discovered_by_user_id', 'current_club' ],
            context:           Kpi::CONTEXT_ACADEMY,
            goalDirection:     Kpi::GOAL_HIGHER_BETTER,
            entityScope:       null,
        ) );
    }
}
