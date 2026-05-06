# TalentTrack v3.104.1 — Analytics fact registry + query engine (#0083 Child 1)

First child of #0083 (Reporting framework). Builds the data-layer foundation the rest of the epic stands on: every analytical question goes through one engine. No bespoke aggregation SQL outside the framework from now on.

## What landed

### Value objects

- **`Modules\Analytics\Domain\Fact`** — a row that something happened (an evaluation, an attendance record, a journey event). Cataloguing of the underlying tt_* table plus dimensions and measures.
- **`Domain\Dimension`** — a column you can group by or filter on. Four types: `foreign_key`, `lookup`, `enum`, `date_range`.
- **`Domain\Measure`** — a column you can aggregate. Five aggregation kinds: `count`, `avg`, `sum`, `min`, `max`. Optional `unit` + `format` for display.
- **`Domain\DateTimeColumn`** — the timeline anchor. Some facts time-stamp themselves (`evaluations.created_at`); others need a join (`attendance.activity_id` → `tt_activities.start_at`).

### `FactRegistry`

Append-only catalogue keyed by fact key. Same shape as `WidgetRegistry` / `KpiDataSourceRegistry`. Methods:
- `register($fact)` — idempotent, last write wins.
- `find($key)` — single fact lookup.
- `all()` — wholesale dump for diagnostics + the upcoming static-analysis test.
- `forEntity($scope)` — facts with `entityScope === $scope`. Used by `KpiRegistry::forEntity()` in Child 2 to scope KPIs to per-entity views.

### `FactQuery::run( $factKey, $dimensionKeys, $measureKeys, $filters )`

The engine every KPI and explorer hits.

- **Single SQL statement.** SELECT measures + grouped-by dimensions, FROM fact table aliased as `f`, optional LEFT JOIN to the time-column's joined table, WHERE club + filter clauses, GROUP BY dimensions, LIMIT 5000.
- **Tenancy auto-injected** via `CurrentClub::id()`. Cross-club aggregation is deliberately impossible from this API — adding it later requires a separate method with an explicit cap check, not a parameter override.
- **60-second result cache** via `wp_cache_*` group `tt_analytics`. Cache key is an MD5 of the parameter set + current club id.
- **Filter operator vocabulary**: `<dim_key>_eq` / `_in` / `_not_eq` / `_not_in`, plus special-cased `date_after` / `date_before` against the fact's declared time column. Future operators (range, like, etc.) extend the vocabulary; today's surface is intentionally minimal.
- **SQL-injection prevention.** Every value is parameterised through `$wpdb->prepare()` — `%d` for ints, `%f` for floats, `%s` otherwise. Identifier names (table, column, alias) are author-controlled at registration time and never derived from user input. The aggregation function passes through a hard whitelist (`COUNT` / `AVG` / `SUM` / `MIN` / `MAX`).

### 8 initial fact registrations

In `AnalyticsModule::boot()` per spec §`feat-fact-registry`:

| Fact key | Underlying table | Entity scope |
|---|---|---|
| `attendance` | `tt_attendance` (joined to `tt_activities`) | player |
| `activities` | `tt_activities` | activity |
| `evaluations` | `tt_evaluations` | player |
| `goals` | `tt_goals` | player |
| `trial_decisions` | `tt_trial_cases` | player |
| `prospects` | `tt_prospects` | player |
| `journey_events` | `tt_player_events` | player |
| `evaluations_per_session` | `tt_evaluations` (joined to `tt_activities`) | activity |

Centralised in `AnalyticsModule::boot()` for Child 1 sequencing simplicity. A follow-up moves each into its owning module's `boot()` (Activities → attendance + activities; Evaluations → evaluations + evaluations_per_session; Goals → goals; Trials → trial_decisions; Prospects → prospects; Journey → journey_events).

### Module registration

`AnalyticsModule` registered in `config/modules.php`.

## What's NOT in this PR

- **`KpiRegistry` + new `Kpi` value object** (Child 2) — migrates the 26 existing KPIs + ships 55 new ones.
- **`?tt_view=explore` dimension explorer** (Child 3) — `desktop_only` per #0084.
- **Entity Analytics tab** on player/team/activity profiles (Child 4).
- **Central analytics surface** at `?tt_view=analytics` (Child 5) — new `analytics` matrix entity + `tt_view_analytics` cap.
- **Export + scheduled reports** (Child 6) — CSV / XLSX / PDF export, `tt_scheduled_reports` table, daily cron.

## Risk callouts

- **Fact-registry discipline.** A future module that adds a player-related table without registering loses analytics coverage silently. The static-analysis test for "every `tt_*` table with a `player_id` / `team_id` FK is registered (or explicitly opted out via comment)" is part of Child 2's definition of done — it ships next, not here.

## Affected files

- `src/Modules/Analytics/Domain/Fact.php` — new.
- `src/Modules/Analytics/Domain/Dimension.php` — new.
- `src/Modules/Analytics/Domain/Measure.php` — new.
- `src/Modules/Analytics/Domain/DateTimeColumn.php` — new.
- `src/Modules/Analytics/FactRegistry.php` — new.
- `src/Modules/Analytics/FactQuery.php` — new.
- `src/Modules/Analytics/AnalyticsModule.php` — new (module shell + 8 fact registrations).
- `config/modules.php` — register `AnalyticsModule`.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

Zero new translatable strings — every label inside the fact registrations is already wrapped in `__()` and gets surfaced when the explorer (Child 3) and KPI surfaces (Child 2 onward) render them.

## Player-centricity

Every fact registers an `entityScope` that names the entity it belongs to (player / team / activity). When Child 2's `KpiRegistry::forEntity('player', $playerId)` lands, the analytics tab on a player profile (Child 4) automatically pulls the right KPIs. The fact registry isn't analytics-for-analytics-sake — it's the catalogue that makes "show me how Lucas is doing" answerable from any surface that already has Lucas in front of the user.
