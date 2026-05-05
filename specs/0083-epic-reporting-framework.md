<!-- type: epic -->

# #0083 — Reporting framework: entity-driven KPIs with consistent dimension explorer

## Problem

TalentTrack has reporting in name only. The `Reports` module produces narrative documents (PlayerReportRenderer, ScoutDelivery — outbound documents, not analytics). The `Stats` module has 7 admin pages — PlayerCardView, PlayerComparisonPage, PlayerRateCardView, PlayerRateCardsPage, PlayerReportView, UsageStatsPage, UsageStatsDetailsPage — each carrying its own bespoke aggregation SQL, its own filter UI, its own export buttons. The `PersonaDashboard` module ships 26 KPIs in `src/Modules/PersonaDashboard/Kpis/`, each computing one number with no drilldown. None of these connect — a coach cannot move from a dashboard KPI into a per-player view, then into a per-activity view of a problematic player. Each surface is a dead end.

The pilot meeting in early May 2026 was unambiguous: reporting must be flexible and very mature. Three concrete gaps the demo exposed:

**1. Every aggregation is one-off.** When a coach asks "how does my team's attendance compare across last three seasons" there is no surface that answers it. Building one means another bespoke SQL query, another filter UI, another export button — and the next question repeats the cost. The cost of every new question is constant and high; that's the inverse of how reporting platforms scale.

**2. Discovery is broken.** A user landing on a player profile sees the player's data; nothing tells them "you can ask analytical questions about this player from a central Analytics page" or "click here to see attendance over time." Every question requires the user to know a-priori which page to navigate to. The pilot academy already tracks this stuff in Excel — friction of switching to TT to discover an answer is higher than friction of opening their own spreadsheet.

**3. The same question gives different shapes from different surfaces.** `AttendancePctRolling` KPI shows 30-day attendance for a coach's teams. `PlayerRateCardsPage` shows per-player attendance percentage but for a season. The team-chemistry module mentions attendance in a per-pairing context. None of these connect. If a coach drills from "team attendance is dropping" into "which players is it" they have to navigate manually, lose context, and recompute filters.

What the pilot academy described as the right shape is what a mature reporting platform looks like: pick an entity, see headline KPIs, drill into the dimensions that matter (time, age group, position, scout, season), end up at the underlying fact rows. Same affordances every time. Learn the platform once, apply to every question.

A complicating factor: the academy is small enough that they don't want a "build your own dashboard" tool with 200 widgets. They want curated KPIs ("the top 15 things we should care about per entity") plus the ability to drill in when one of those KPIs raises a question. Predefined depth, customisable breadth — not "everything is configurable from day one."

You confirmed the layered architecture is the right path: build the OLAP-light layer once, ship predefined KPIs and an explorer on top, leave a v2 report-builder for later. The full epic is six children. It can ship for the academy's 2027/28 season if it starts H2 2026.

## Proposal

### Shape — six child specs, three layers

The reporting framework has three logical layers (data, KPIs, presentation) and three integration surfaces (entity-page integration, central analytics page, export). Each is a child:

- **`feat-fact-registry`** — the data layer. A `FactRegistry` cataloguing every fact table TalentTrack has — evaluations, attendance, trial decisions, prospect events, journey events — with their dimensions and measures declared in code. No new tables; this is metadata over existing ones. Every other child reads from this registry. Ships first; blocks everything else.

- **`feat-kpi-platform`** — the second layer. Augments the existing `KpiDataSourceRegistry` (used by `KpiCardWidget` and `KpiStripWidget`) with a richer model that connects to the fact registry. KPIs become declarative: "average rating, filtered by team, grouped by month, with drilldown into evaluations." All 26 existing KPIs migrate via a back-compat adapter — no widget changes needed. New KPIs (the "top 15 per entity" the pilot asked for) ship as part of this child.

- **`feat-dimension-explorer`** — the presentation layer. A reusable view at `?tt_view=explore&kpi={key}` that any KPI can hand off to. User clicks "explore" on a KPI → explorer opens with that KPI's data prepopulated, dimension chips at the top, drill-to-detail action below. Same component everywhere; consistent UX as the academy asked for.

- **`feat-entity-analytics-tab`** — entity-page integration. Player profile, team profile, activity detail each gain an "Analytics" tab that surfaces the relevant KPIs from this entity's perspective. Discovery solved — users find analytics where they already are.

- **`feat-central-analytics-surface`** — the central analytics page at `?tt_view=analytics`. Two-column: entity selector on left (player / team / activity / season / scout), KPI grid on right. Click any KPI → opens the explorer.

- **`feat-reporting-export-and-schedule`** — the operational layer. Export any KPI or explorer view to CSV / XLSX / PDF. Schedule recurring email reports (weekly attendance digest, monthly evaluation summary) to specific users or roles. Out of v1 is the full report-builder UI for arbitrary new reports — that's v2 once the platform proves itself. v1 ships only export and schedule for the predefined KPIs.

The six are sequenced. Fact registry first (it's the spine). KPI platform and explorer second (parallelisable; the explorer needs the fact registry, the KPI platform needs both). Entity tab and central surface third (parallel, both consume KPI platform + explorer). Export last (lower urgency, can land separately).

The whole epic is 14-18 weeks of work at conventional throughput. The codebase's documented ~1/2.5 estimate-to-actual ratio brings the realistic actual to ~7-8 weeks for one engineer paying attention. It can ship for the academy's 2027/28 season if it starts H2 2026.

## Scope

### 1. `feat-fact-registry`

**Purpose.** Catalogue every fact table with its dimensions and measures, declaratively. A fact is a row that something happened — an evaluation, an attendance record, a journey event. A dimension is something you can group or filter by. A measure is something you can aggregate (count, average, sum).

**Implementation.** A new module `src/Modules/Analytics/`. Within it:

```php
// src/Modules/Analytics/Domain/Fact.php
final class Fact {
    public function __construct(
        public string $key,                    // 'evaluations', 'attendance', 'journey_events'
        public string $tableName,              // 'tt_evaluations'
        public string $label,                  // 'Evaluations' (translatable)
        public array $dimensions,              // list<Dimension>
        public array $measures,                // list<Measure>
        public DateTimeColumn $timeColumn,     // for time-series queries
        public ?string $entityScope = null,    // 'player' | 'team' | 'activity' | null
    ) {}
}

final class Dimension {
    public function __construct(
        public string $key,                    // 'team_id', 'age_group', 'position'
        public string $label,                  // 'Team', 'Age group', 'Position'
        public string $type,                   // 'foreign_key' | 'lookup' | 'enum' | 'date_range'
        public ?string $foreignTable = null,   // for fk types: 'tt_teams'
        public ?string $lookupType = null,     // for lookup types: 'position'
        public ?string $sqlExpression = null,  // override SQL: e.g. 'YEAR(start_at)'
    ) {}
}

final class Measure {
    public function __construct(
        public string $key,                    // 'count', 'avg_rating', 'sum_minutes'
        public string $label,                  // 'Count', 'Average rating'
        public string $aggregation,            // 'count' | 'avg' | 'sum' | 'min' | 'max'
        public ?string $column = null,         // null for count
        public ?string $unit = null,           // 'rating', 'minutes', 'percent', null
        public ?string $format = null,         // 'integer' | 'decimal' | 'percent'
    ) {}
}
```

**Initial fact registrations (delivered in this child):**

| Fact key | Underlying table | Entity scope | Key dimensions | Key measures |
|---|---|---|---|---|
| `evaluations` | `tt_evaluations` + `tt_eval_ratings` | player | player, team, age_group, evaluator, activity_type, eval_category, season | count, avg_rating, count_per_category |
| `attendance` | `tt_attendance` (joined to `tt_activities`) | player | player, team, age_group, activity, activity_type, status, season | count_present, count_absent, attendance_pct |
| `activities` | `tt_activities` | activity | team, age_group, activity_type, location, status, season | count, total_minutes, count_with_attendance |
| `goals` | `tt_goals` (+ `tt_goal_links`) | player | player, team, status, priority, linked_principle, season | count, count_completed, completion_rate |
| `trial_decisions` | `tt_trial_cases` | player | decision, age_group, season, decided_by | count, count_per_decision |
| `prospects` | `tt_prospects` (joined to `tt_workflow_tasks`) | player | discovered_by, age_group, current_stage, current_club | count, count_promoted, conversion_rate |
| `journey_events` | `tt_player_events` | player | event_type, player, team, age_group, season | count, count_per_event_type |
| `evaluations_per_session` | derived: `tt_evaluations` joined to `tt_activities` | activity | activity, evaluator, age_group | coverage_pct (evaluated/present) |

The `journey_events` registration is the addition over the previous draft. Player events feed time-series naturally — joined-academy / evaluation-completed / goal-set / status-changed are all events with a date and a player, and they're the canonical "what happened to this player when" record. They belong alongside evaluations and attendance.

**Registration pattern.** Each module declares its own facts at boot, the same convention as `WidgetRegistry` and `KpiDataSourceRegistry`:

```php
// src/Modules/Activities/ActivitiesModule.php (extended)
FactRegistry::register(
    new Fact(
        key: 'attendance',
        tableName: 'tt_attendance',
        label: __('Attendance', 'talenttrack'),
        dimensions: [
            new Dimension('player_id', __('Player', 'talenttrack'), 'foreign_key', 'tt_players'),
            new Dimension('team_id',   __('Team', 'talenttrack'),   'foreign_key', 'tt_teams', sqlExpression: 'a.team_id'),
            new Dimension('activity_type', __('Activity type', 'talenttrack'), 'lookup', lookupType: 'activity_type'),
            new Dimension('age_group', __('Age group', 'talenttrack'), 'lookup', lookupType: 'age_group'),
            new Dimension('status', __('Attendance status', 'talenttrack'), 'lookup', lookupType: 'attendance_status'),
            new Dimension('month', __('Month', 'talenttrack'), 'date_range', sqlExpression: "DATE_FORMAT(a.start_at, '%Y-%m')"),
            new Dimension('season', __('Season', 'talenttrack'), 'date_range', sqlExpression: "tt_season_for_date(a.start_at)"),
        ],
        measures: [
            new Measure('count_present', __('Present', 'talenttrack'), 'count', column: "CASE WHEN status='present' THEN 1 END"),
            new Measure('count_absent', __('Absent', 'talenttrack'), 'count', column: "CASE WHEN status='absent' THEN 1 END"),
            new Measure('attendance_pct', __('Attendance %', 'talenttrack'), 'avg', column: "CASE WHEN status='present' THEN 100 ELSE 0 END", unit: 'percent', format: 'percent'),
        ],
        timeColumn: new DateTimeColumn('a.start_at', joinedTable: 'tt_activities a', joinKey: 'activity_id'),
        entityScope: 'player',
    ),
);
```

The verbosity is intentional — a fact is the union of "everything we care about asking about this thing", and every line is a question someone might want to answer. Refactoring this is cheaper than refactoring the bespoke aggregation SQL it replaces (Stats module's 7 admin pages plus the 26 KPI files).

**The shared query engine.** A new class `FactQuery` accepts a fact key, a list of dimensions to group by, a list of measures to compute, a list of filters (`where_player_id_in_team_X`, `where_date_after`, etc.), and returns a typed result set. Generates a single SQL statement with proper joins. Caches result for 60 seconds via WordPress object cache. This is the engine every KPI and every explorer instance hits — there is no other path to the data.

**Multi-tenancy.** Every query auto-scopes to the current `club_id` via `QueryHelpers::current_club_id()` (the same pattern already used across `tt_*` repositories). Cross-club aggregation is deliberately impossible from the FactQuery API — adding it later requires an explicit cap and a new method, not just a parameter.

**Tests.** Property-based test that for every registered fact, every dimension can be grouped by, every measure can be aggregated, and the result row count is non-negative. Static-analysis test that asserts every `tt_*` table containing a foreign key to a player or team is registered as a fact (or explicitly opted out via a comment). Catches drift when a new entity ships without registration.

**No schema changes.** Reads existing tables. The Fact, Dimension, Measure are PHP value objects.

### 2. `feat-kpi-platform`

**Purpose.** Augment the existing KPI infrastructure with a richer model. Today's KPI is a one-method class returning a number (see the 26 files in `src/Modules/PersonaDashboard/Kpis/` — `ActivePlayersTotal`, `AttendancePctRolling`, `AvgEvaluationRating`, `GoalCompletionPct`, etc.). The new KPI is a fact-driven specification that knows how to compute its number, what dimensions are relevant for its drilldown, and what defaults to apply.

**The new KPI class.**

```php
// src/Modules/Analytics/Domain/Kpi.php
final class Kpi {
    public function __construct(
        public string $key,
        public string $label,
        public string $factKey,                // which fact this KPI runs against
        public Measure $measure,
        public array $defaultFilters = [],     // e.g. ['date_after' => '-30 days']
        public array $primaryDimension = [],   // for the time-series chart on drilldown
        public array $exploreDimensions = [],  // dimensions surfaced in the explorer chips
        public ?string $context = null,        // 'ACADEMY' | 'COACH' | 'PLAYER_PARENT'
        public ?string $goalDirection = null,  // 'higher_better' | 'lower_better' | null
        public ?float $threshold = null,       // value below/above which to flag in red
        public ?string $linkedKpi = null,      // related KPI for cross-reference
    ) {}
}
```

**Migration of existing 26 KPIs.** All persona-dashboard KPIs get reimplemented as `Kpi` value objects pointing at the appropriate fact + measure. The compute method is gone — the KPI declares what it wants and the platform computes it. No more bespoke SQL per KPI.

The migration is largely mechanical because each existing KPI maps cleanly:

```php
KpiRegistry::register(new Kpi(
    key: 'attendance_pct_rolling',
    label: __('Attendance % (30 days)', 'talenttrack'),
    factKey: 'attendance',
    measure: $attendanceFact->measure('attendance_pct'),
    defaultFilters: ['date_after' => '-30 days'],
    primaryDimension: ['key' => 'month', 'as' => 'time-series'],
    exploreDimensions: ['team_id', 'age_group', 'activity_type', 'player_id'],
    context: 'COACH',
    goalDirection: 'higher_better',
    threshold: 70.0,  // below 70% flagged
));
```

**Backwards compatibility.** Existing widgets — `KpiCardWidget` and `KpiStripWidget`, plus `ActionCardWidget`'s KPI references — continue working unchanged. They internally call `KpiRegistry::resolve($key)->value()` — the contract is the same. The legacy `KpiDataSourceRegistry` interface is deprecated but kept; a thin adapter routes lookups to the new registry. Removed in a future major version. **No migration of dashboards or persona templates is required.**

**The "top 15 KPIs per entity" the pilot asked for.** New KPIs registered in this child, organised by entity:

**Player** (15 KPIs)
- `player_attendance_pct_season`, `player_attendance_pct_30d`
- `player_avg_rating_season`, `player_avg_rating_per_category`
- `player_evaluations_count`, `player_evaluations_count_30d`
- `player_goals_active`, `player_goals_completed_season`, `player_goal_completion_rate`
- `player_minutes_played_season`
- `player_status_age_in_status` (how long in current status pill)
- `player_evaluator_diversity` (how many different coaches have evaluated)
- `player_pdp_completion_rate`
- `player_attendance_trend_3mo` (slope of last 3 months)
- `player_rating_trend_3mo`

**Team** (15 KPIs)
- `team_attendance_pct_season`, `team_attendance_pct_30d`
- `team_avg_rating_season`, `team_avg_rating_per_category`
- `team_player_count_active`, `team_player_count_with_status`
- `team_evaluations_per_session`, `team_evaluations_per_player_30d`
- `team_session_count_season`, `team_session_count_30d`
- `team_goal_completion_rate`
- `team_concerning_player_count` (status amber/red)
- `team_chemistry_score_avg` (already-implemented module; expose its existing aggregate as a KPI)
- `team_attendance_variance` (how spread is attendance across players)
- `team_evaluator_coverage` (how many coaches evaluate this team)

**Activity** (10 KPIs — fewer because the unit is smaller)
- `activity_attendance_pct`, `activity_evaluations_count`
- `activity_evaluation_coverage` (evaluated/present)
- `activity_avg_rating_per_category`
- `activity_late_count`, `activity_no_show_count`
- `activity_evaluator_count`
- `activity_duration_minutes`
- `activity_per_type_count_season`
- `activity_planned_vs_completed` (per type, per season)

**Season** (10 KPIs)
- `season_player_count_total`, `season_player_count_promoted`, `season_player_count_offboarded`
- `season_evaluations_total`, `season_attendance_pct`
- `season_trial_decisions`, `season_trial_admit_rate`, `season_trial_decline_rate`
- `season_prospects_logged`, `season_prospects_promoted`

**Scout** (5 KPIs — narrow surface)
- `scout_prospects_active`, `scout_prospects_logged_season`
- `scout_prospects_promoted_season`, `scout_promotion_rate`
- `scout_avg_days_log_to_invite`

Total: 55 new KPIs across 5 entity scopes, on top of the 26 existing ones the platform inherits and migrates. The asymmetry across entities (15-15-10-10-5) reflects how much depth each one actually has — a scout has fewer interesting metrics than a player or team.

### 3. `feat-dimension-explorer`

**Purpose.** A single component that any KPI can hand off to for drilldown. Same UX everywhere.

**The component.** A new view at `?tt_view=explore&kpi={key}&filters={json}`, registered through `CoreSurfaceRegistration` like every other frontend view. Renders:

```
┌─────────────────────────────────────────────────────────────────────┐
│  ← Back   |   Average rating per player (last 30 days)              │
│                                                                       │
│  [Time: last 30 days ▼]   [Team: all ▼]   [Position: all ▼]   [+]   │
│  ─────────────────────────────────────────────────────────────────  │
│                                                                       │
│   6.84                                                                │
│   ↑ 0.3 vs previous period                                           │
│                                                                       │
│   ┌────────────────────────────────────────────────────────────┐    │
│   │  Time series chart (line + dots, primary dimension)         │    │
│   │                                                              │    │
│   └────────────────────────────────────────────────────────────┘    │
│                                                                       │
│  Group by: [None ▼]                                                  │
│  ─────────────────────────────────────────────────────────────────  │
│                                                                       │
│  When grouped → bar chart per group, table below                     │
│  When ungrouped → list of underlying fact rows (top 50, paged)       │
│                                                                       │
│  [Export CSV]  [Export PDF]  [Schedule]                              │
└─────────────────────────────────────────────────────────────────────┘
```

**Filter chips.** Top-row chips for each `exploreDimension` of the KPI. Each chip is a clickable dropdown. Adding a chip narrows the data; removing it broadens.

**Group-by selector.** A second-row selector that pivots the visualisation. Defaults to "None" — show the time series as one line. Selecting "Team" splits the line into one per team (legend appears). Selecting "Player" pivots to a bar chart of top 20 players (because line-per-player is too dense).

**Drilldown to fact rows.** When ungrouped, below the chart is a paginated table of the underlying rows. For an attendance KPI: one row per attendance record showing player name, activity, status, date. Clicking a row opens the activity detail. The chart and the table are the same data, two views.

**Charting library.** Reuses the Chart.js wiring already shipped via `#0077` M6 (the comparison radar chart) and the existing `renderChartScripts` helper. No new chart library introduced.

**State preservation.** The URL `?tt_view=explore&kpi=attendance_pct&filters={"team":[12,13],"date_after":"2026-01-01"}` fully describes the explorer state. Sharing a link reproduces the view. Browser back/forward works. No hidden client state.

**Mobile.** Per the mobile experience spec (#0084), the explorer is `desktop_only` — it shows a "This page is designed for desktop" placeholder on mobile. Reasoning: dense filtering and chart interaction are desktop work; nobody analyses attendance trends on a phone.

### 4. `feat-entity-analytics-tab`

**Purpose.** Wherever a user is already looking at an entity (player profile, team profile, activity detail), surface the relevant KPIs in an "Analytics" tab.

**Implementation.** Each entity detail view that exists today gains a new tab. The player detail view is already a six-tab case page (Profile/Goals/Evaluations/Activities/PDP/Trials — shipped via #0077 M8); this child adds a seventh tab (or replaces the most-similar existing one). Team detail and activity detail get analytics tabs added in parallel. The tab content is generated from `KpiRegistry::forEntity('player', $playerId)`, which returns the KPIs scoped to that entity scope, with the entity's ID baked in as a default filter.

**Visual.** A 5-column KPI grid (or 3-column on tablet, 1-column on mobile). Each card shows the KPI name, the current value, the trend (↑/↓ arrow + delta vs prior period), and a small sparkline if the KPI has a primary dimension. Click → opens the explorer pre-scoped to this entity.

**Discovery surface.** The Analytics tab is the discoverability fix. A coach looking at a player can now ask "how is Lucas doing on attendance" by clicking the tab — they don't need to know there's a separate Analytics page.

**Capability gating.** Reuses each KPI's existing context (`ACADEMY` / `COACH` / `PLAYER_PARENT`). A parent on their child's profile sees only `PLAYER_PARENT`-scoped KPIs (a curated subset — attendance percentage, goal completion rate, but not the full coach-facing detail). The `tab_count_badges` infrastructure shipped via #0082 (PlayerFileCounts) is the natural pattern — the Analytics tab gets a count badge if there's something noteworthy in this entity's KPIs.

### 5. `feat-central-analytics-surface`

**Purpose.** A central "Analytics" view at `?tt_view=analytics` for users who think "I want to explore data" rather than "I want to look at a specific player."

**Layout.** Two-column on desktop:

- **Left column (entity selector):** Five entity-type tiles — Player, Team, Activity, Season, Scout. Each tile expands to show entity instances (the user's accessible players, the user's teams, etc.). Selecting an instance is the same as navigating to that entity's detail page → analytics tab.
- **Right column (KPI grid):** When no entity is selected → "global" KPIs (academy-level overview). When an entity is selected → that entity's KPIs.

**Mobile.** Per mobile spec, this view is `desktop_only`.

**Capability gating.** Cap `tt_view_analytics` (new), granted to HoD and Academy Admin by default. Coaches see analytics through the per-entity tab on player/team profiles they have access to; they don't get the central exploration view because their analytical work is bounded to their teams. Power-coaches can be granted the cap manually via the matrix admin page.

**Matrix entity.** New entity `analytics`: HoD `R global`, Academy Admin `R global`. Other personas: no access via the central view. This matches the "controlled exploration" stance the pilot asked for — users discover analytics through their natural surfaces (entity pages); power-users get the central explorer. The seed extends `config/authorization_seed.php`; existing installs receive a top-up migration following the precedent set by `0063_authorization_seed_topup_0079.php`.

### 6. `feat-reporting-export-and-schedule`

**Purpose.** Operationalise reporting for users who want their numbers regularly.

**Export.** Three formats, all reachable from any explorer view or KPI card:

- **CSV** — raw data rows, with proper UTF-8 BOM for Excel-NL compatibility.
- **XLSX** — formatted, with an embedded chart using the `xlsx` skill, and the chart's source data on a second sheet.
- **PDF** — single-page printable using the existing browser-native `window.print()` path that #0077 M11 established (no Dompdf dependency added). Branded with TalentTrack tokens from #0075.

**Schedule.** A new surface at `?tt_view=scheduled-reports` (Academy Admin + HoD). Lets a user create a schedule:

- Pick a KPI (or a saved explorer view).
- Pick a frequency (weekly Monday morning, monthly first day, end of season).
- Pick recipients (users by email, or roles — "all coaches", "all HoDs").
- Pick a format (XLSX or PDF).

A daily cron `tt_scheduled_reports_cron` checks active schedules, runs them, attaches the file, sends via the existing communication infrastructure (when #0066 ships) or directly via `wp_mail()` until then. Each scheduled run is logged to `tt_audit_log` (audit trail).

**The `tt_scheduled_reports` table:**

```sql
CREATE TABLE {prefix}tt_scheduled_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(255) NOT NULL,
    kpi_key VARCHAR(64) DEFAULT NULL,           -- exclusive with explorer_state_json
    explorer_state_json LONGTEXT DEFAULT NULL,  -- saved filter+group state
    frequency VARCHAR(20) NOT NULL,             -- 'weekly_monday', 'monthly_first', 'season_end'
    recipients TEXT NOT NULL,                   -- JSON: list of emails or role keys
    format VARCHAR(10) NOT NULL,                -- 'xlsx' | 'pdf'
    last_run_at DATETIME DEFAULT NULL,
    next_run_at DATETIME NOT NULL,
    status VARCHAR(20) DEFAULT 'active',        -- 'active' | 'paused' | 'archived'
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_club_status (club_id, status),
    KEY idx_next_run (next_run_at, status)
);
```

**License-gating.** Scheduled reports is a Standard-or-higher feature per the LicenseGate convention shipped in v3.86.1 — Free tier gets export but not schedule. Operator can override per club. The KPIs themselves are unrestricted; scheduling is the gate.

**Out of v1.** A full report-builder UI where users craft their own KPI from scratch (pick fact, pick measure, pick filters, save as named KPI). Reserved for v2 once the platform proves itself with predefined KPIs. v1 covers export + schedule of existing KPIs and saved explorer states; that satisfies 90% of operational needs.

## Out of scope

- **Bespoke dashboards per user** ("my custom dashboard with these 6 KPIs"). The persona dashboard already provides this through its editor, and the custom widget builder (#0078) extends it further — KPIs from this platform plug into the existing widget infrastructure. No second dashboard system.
- **Cross-tenant benchmarking** ("how does our U13 attendance compare to other academies"). Would require either a shared anonymised dataset or per-club opt-in. Privacy-fraught, not v1.
- **Real-time / streaming dashboards.** All data is read with a 60-second cache. If a coach needs sub-minute latency, they can pull-to-refresh. No WebSocket infrastructure.
- **Predictive analytics / ML.** "Which players are likely to leave next season" is interesting but a separate spec involving model training, validation, and monitoring. v1 is descriptive, not predictive.
- **A natural-language query interface** ("show me U13 attendance"). Tempting but premature; the explorer's structured filtering is more reliable for academy users in their first year on the platform.
- **Custom KPI creation from the UI.** Adding a KPI requires a code change today. The custom widget builder (#0078) already covers user-extensible widgets; merging the two systems is its own future spec.
- **Automatic anomaly detection / alerting.** "Email me when attendance drops below 70%" — useful but its own spec involving notification preferences, threshold management, alert fatigue. Defer.
- **Multi-club rollup** for academies operating sister clubs. The fact registry's `club_id` filtering is per-club; cross-club aggregation requires explicit support and is reserved for the Admin Center (#0065) tier of the product.
- **Replacing the Stats module's bespoke pages immediately.** Stats module's PlayerCardView, PlayerComparisonPage, PlayerRateCardsPage, etc. continue working as-is. They get migrated opportunistically as they need maintenance — same pattern as the persona dashboard KPI migration. v1 of this spec doesn't touch them.
- **Replacing the Reports module's narrative documents.** Reports module owns outbound documents (player narrative reports, scout link delivery). Different concern; left alone.

## Acceptance criteria

**`feat-fact-registry`:**
- `FactRegistry::all()` returns the eight facts documented above (evaluations, attendance, activities, goals, trial_decisions, prospects, journey_events, evaluations_per_session). Each registered with the documented dimensions and measures.
- `FactQuery::run($factKey, $dims, $measures, $filters)` returns a typed result set in a single SQL statement, scoped to the current `club_id`.
- Result is cached for 60 seconds via `wp_cache_*`.
- Static-analysis test asserts every `tt_*` table with a `player_id` or `team_id` foreign key is either registered as a fact or explicitly opted out via a comment.

**`feat-kpi-platform`:**
- 26 existing KPIs are migrated to `Kpi` value objects, all values match what they computed before (regression test snapshots one academy's data and re-validates after migration).
- 55 new KPIs are registered across the documented entity scopes.
- `KpiRegistry::resolve($key)->value()` returns the same data shape as the old `KpiDataSourceRegistry` for back-compat — `KpiCardWidget`, `KpiStripWidget`, `ActionCardWidget` work without changes.
- `KpiRegistry::forEntity($entity, $entityId)` returns KPIs scoped to that entity with default filters applied.

**`feat-dimension-explorer`:**
- New view at `?tt_view=explore&kpi={key}` registered through `CoreSurfaceRegistration` and renders the documented layout with filter chips, time-series chart, group-by selector, and detail table.
- All filters, group-by, and date range round-trip through the URL.
- `mobile_class = desktop_only` (per #0084) — shows placeholder on mobile.

**`feat-entity-analytics-tab`:**
- Player profile, team profile, and activity detail pages each gain an "Analytics" tab.
- Tab renders a KPI grid scoped to that entity.
- Click any KPI card → opens explorer with entity filter pre-applied.
- Capability scoping: parents see `PLAYER_PARENT` KPIs only, coaches see `COACH` KPIs, HoD/Admin see all.

**`feat-central-analytics-surface`:**
- New view at `?tt_view=analytics` renders entity selector + KPI grid.
- `tt_view_analytics` cap exists, granted to HoD and Academy Admin.
- New matrix entity `analytics`: HoD `R global`, Admin `R global`. Top-up migration backfills existing installs (precedent: `0063_authorization_seed_topup_0079.php`).
- `mobile_class = desktop_only`.

**`feat-reporting-export-and-schedule`:**
- CSV / XLSX / PDF export buttons work from any explorer view.
- New `tt_scheduled_reports` table after migration.
- New view at `?tt_view=scheduled-reports` for managing schedules.
- Daily cron picks up due schedules, generates the report, sends via email, logs to audit trail.
- Schedule feature is gated via LicenseGate (`scheduled_reports` feature; Standard-and-up).

## Notes

**Documentation updates.**
- `docs/analytics.md` and Dutch mirror — new doc. Operator guide for the analytics platform. Walks through KPI discovery (entity tab + central view), the explorer, the export, the schedule.
- `docs/access-control.md` — note the new `analytics` matrix entity and the `tt_view_analytics` cap.
- `docs/modules.md` — new entry for the Analytics module.
- `docs/persona-dashboard.md` — note that KPIs are now `Kpi` value objects from the new platform; widget editing still happens through the same editor.
- `languages/talenttrack-nl_NL.po` — labels for all 81 KPIs (26 existing + 55 new), plus dimension labels, plus explorer UI strings.
- `SEQUENCE.md` — append `#0083-epic-reporting-framework.md` to Ready.

**`CLAUDE.md` updates.**
- §3 (data model) — add the Analytics module's pattern: "If your module owns a fact table — evaluations, attendance, etc. — register it with `FactRegistry` at boot. KPIs derive from facts; do not write bespoke aggregation SQL outside the framework."
- §4 (Module conventions) — note that the existing `KpiDataSourceRegistry` is deprecated in favour of `KpiRegistry`. New KPIs use the new platform.

**Effort estimate at conventional throughput.**
- Fact registry: ~600 LOC (Fact / Dimension / Measure value objects + FactQuery engine + 8 fact registrations + tests)
- KPI platform: ~900 LOC (new Kpi class + migration of 26 existing + 55 new KPI registrations + tests)
- Dimension explorer: ~1,200 LOC (the view + chart components + filter chip UI + state serialization + tests)
- Entity analytics tab: ~400 LOC (3 entity views extended + KPI grid component)
- Central analytics surface: ~500 LOC (the view + entity selector + KPI grid)
- Export and schedule: ~700 LOC (export adapters + scheduled-reports CRUD + cron + table)
- Docs + translations: ~400 LOC

Total at conventional rates: ~4,700 LOC across six PRs. Largest is the explorer (UI complexity). Most architecturally consequential is the fact registry. **Applying the codebase's documented ~1/2.5 estimate-to-actual ratio: realistic actual is ~1,800-2,000 LOC across the six PRs**, ~7-8 weeks for one engineer.

**One product decision worth flagging.** The 55 new KPIs are a deliberate v1 commitment. They're meant to be the "you don't need to ask, here's what an academy of this size cares about" curation. If during implementation a particular KPI proves uninteresting (say `team_evaluator_coverage` — clubs with one head coach per team always get a coverage of 100%), drop it without ceremony. The 55 is a target, not a contract.

**One architectural risk worth flagging.** The fact registry pattern depends on every module being disciplined about registering its facts. If a future module adds a player-related table without registering, the central analytics surface won't see it — silent data loss. The static-analysis test catches the obvious cases but is fallible. Worth a quarterly architecture review of "are all our facts registered" until the pattern becomes muscle memory.
