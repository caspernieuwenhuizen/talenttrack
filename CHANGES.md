# TalentTrack v3.104.2 — Analytics KPI platform: registry + resolver + 6 reference KPIs (#0083 Child 2)

Second child of #0083 (Reporting framework). Builds the second layer on top of Child 1's fact registry: declarative `Kpi` value objects + `KpiRegistry` + a single `KpiResolver` that bridges new fact-driven KPIs and the legacy `Modules\PersonaDashboard\Registry\KpiDataSourceRegistry`. The 26 existing KPIs keep working unchanged via the resolver's back-compat fallback; bulk migration to fact-driven declarations lands in a follow-up.

## What landed

### `Modules\Analytics\Domain\Kpi` value object

Declarative replacement for the existing 26 `Modules\PersonaDashboard\Kpis\*` classes that each carry inline aggregation SQL.

Properties:
- `key`, `label`, `factKey`, `measureKey`
- `defaultFilters` — `array<string,mixed>` applied at every resolve
- `primaryDimension` — for the time-series chart on drilldown (Child 3)
- `exploreDimensions` — list of dimension keys surfaced as filter chips
- `context` — `ACADEMY` / `COACH` / `PLAYER_PARENT` for persona gating
- `goalDirection` — `higher_better` / `lower_better` for explorer flagging
- `threshold` — value below/above which the KPI flags red
- `entityScope` — `'player'` / `'team'` / `'activity'` / null

### `KpiRegistry`

Append-only catalogue. Mirrors `FactRegistry` and `WidgetRegistry` shapes:
- `register($kpi)`
- `find($key)`
- `all()`
- `byContext($context)`
- `forEntity($scope)` — used by Child 4's per-entity Analytics tab

### `KpiResolver`

Single resolution path. Methods:
- `value($key, $extraFilters)` — returns the headline number as `float|null`. Looks up `KpiRegistry::find()` first; if the new KPI exists, runs `FactQuery::run($factKey, [], [$measureKey], $defaultFilters + $extraFilters)`. If the key isn't in the new registry, falls back to the legacy `KpiDataSourceRegistry::get()` and calls its `compute()` method, coercing the legacy `KpiValue` to a float.
- `exists($key)` — true when either registry resolves the key.

The fallback is the migration bridge. The 26 legacy KPIs keep working unchanged through this resolver. As each KPI gets migrated to a fact-driven `Kpi` declaration, callers don't need to change — the resolver picks up the new registration first.

### 6 reference KPIs in `AnalyticsModule::boot()`

End-to-end smoke test for the platform against the fact registry:

| Key | Fact | Measure | Context | Entity scope |
|---|---|---|---|---|
| `fact_player_attendance_pct_30d` | attendance | attendance_pct | COACH | player |
| `fact_player_evaluations_count_30d` | evaluations | count | COACH | player |
| `fact_player_goal_completion_rate` | goals | completion_rate | COACH | player |
| `fact_activity_count_30d` | activities | count | ACADEMY | activity |
| `fact_academy_prospects_logged_30d` | prospects | count | ACADEMY | (none) |
| `fact_my_player_goal_completion_rate` | goals | completion_rate | PLAYER_PARENT | player |

Six is enough to validate the platform without forcing a 26-KPI migration sprint inside this PR. Bulk migration + the remaining 49 of the spec's "top 15 per entity" set (15 player + 15 team + 10 activity + 10 season + 5 scout) ships in successive follow-ups.

## What's NOT in this PR

- **Bulk migration of the 26 legacy KPIs** to fact-driven declarations — follow-up. Until then they keep working through the resolver's back-compat fallback.
- **The 55 new KPIs from the spec's "top 15 per entity"** — 6 ship here as reference; 49 to go, follow-up.
- **Static-analysis test** for "every `tt_*` table with a `player_id` / `team_id` FK is registered as a fact" — follow-up (introduced when the bulk migration starts so it doesn't fire on the partial state).
- **`?tt_view=explore` dimension explorer** (Child 3, `desktop_only` per #0084).
- **Entity Analytics tab** (Child 4).
- **Central analytics surface** + `analytics` matrix entity + `tt_view_analytics` cap (Child 5).
- **Export + scheduled reports** (Child 6).

## Affected files

- `src/Modules/Analytics/Domain/Kpi.php` — new.
- `src/Modules/Analytics/KpiRegistry.php` — new.
- `src/Modules/Analytics/KpiResolver.php` — new.
- `src/Modules/Analytics/AnalyticsModule.php` — `registerInitialKpis()` adds the 6 reference KPIs.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

Zero new translatable strings — labels inside KPI registrations are wrapped in `__()` and surface via Children 3-5 UIs.

## Player-centricity

The `entityScope` field on every KPI is what makes per-entity analytics tabs (Child 4) automatic. When a coach lands on a player's profile, the platform pulls every `entityScope: 'player'` KPI without per-template wiring. The catalogue does the work; the UI just renders. Same for team and activity profiles. The KPI registry isn't analytics-for-analytics-sake — it's the indexing layer that makes "show me how Lucas is doing" answerable from any surface that already has Lucas in front of the user.
