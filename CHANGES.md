# TalentTrack v3.106.2 — Custom widget builder Phase 1: data-source layer (#0078 Phase 1)

First phase of #0078 (Custom widget builder). Ships the data-layer foundation Phases 2-6 build on top of. Feature-flag-gated via `tt_custom_widgets_enabled` (default off; beta installs opt in). No user-visible surface yet — Phase 3 ships the admin builder page; Phase 4 ships the persona-dashboard rendering integration.

## What landed

### `Modules\CustomWidgets\Domain\CustomDataSource` interface

Per spec decision 1: registered data-source classes only — no free-text SQL, no visual SQL builder. Admins configure (filters, columns, label, format) but cannot author the underlying query.

The interface declares five methods:

- **`id()`** — stable snake_case id used as foreign key in the future `tt_custom_widgets.data_source_id`.
- **`label()`** — translatable picker label.
- **`columns()`** — list of `[key, label, kind]`. The builder UI renders one checkbox per column; widgets persist the chosen subset. `kind` ∈ `string` / `int` / `float` / `date` / `pill` drives column formatting.
- **`filters()`** — list of `[key, label, kind, ...]`. `kind` ∈ `date_range` / `team` / `player` / `enum` / `season`. The builder UI renders one input per filter; widgets persist the chosen values in `definition.filters`.
- **`fetch( $user_id, $filters, $column_keys, $limit )`** — returns `list<array<string,mixed>>` keyed by column id. **Implementations MUST** filter by current `club_id` via `CurrentClub::id()` + apply demo-mode scope + validate filter values against the declared `filters()` metadata. The renderer (Phase 4) calls this with the operator's chosen subset.
- **`aggregations()`** — list of `[key, label, kind, column?]`. `kind` ∈ `count` / `avg` / `sum` / `distinct`. Used by KPI widgets (single number) + bar / line widgets (one aggregated value per group).

### `CustomDataSourceRegistry`

Append-only catalogue keyed by source id. Mirrors the registration shape of `WidgetRegistry` / `KpiDataSourceRegistry` / `FactRegistry` — `register($source)` / `find($id)` / `all()` / `catalogue()` (the builder-UI shape) / `clear()` (test helper).

### Five reference data sources

In `Modules\CustomWidgets\DataSources\`:

| Class | Underlying table | Filters | Aggregations |
|---|---|---|---|
| `PlayersActive` | `tt_players` | team_id, age_range | count, distinct_teams |
| `EvaluationsRecent` | `tt_evaluations` | date_from, date_to, team_id | count, avg_overall |
| `GoalsOpen` | `tt_goals` | status | count, distinct_players |
| `ActivitiesRecent` | `tt_activities` | date_from, date_to, team_id | count |
| `PdpFiles` | `tt_pdp_files` | season_id, status | count |

Each enforces `club_id` in its `fetch()` and parameterises every value via `$wpdb->prepare()`. Column / aggregation surfaces match the spec's Phase 1 acceptance scenarios:
- "Top 10 active players from the Players source" → `PlayersActive` with `limit=10`.
- "Average evaluation rating per coach (last 30 days) KPI from the Evaluations source" → `EvaluationsRecent` + `avg_overall` aggregation + `date_from = -30 days`.
- "Goals per principle bar chart from the Goals source" → `GoalsOpen` + `count` aggregation grouped by principle.

### `CustomWidgetsModule`

Registered in `config/modules.php`. `boot()` is feature-flag-gated:

```php
if ( ! self::isFeatureEnabled() ) return;
self::registerInitialDataSources();
```

`isFeatureEnabled()` reads `tt_custom_widgets_enabled` from `tt_config` via `ConfigService::getBool()` (per-club), with a `wp_options` fallback for installs predating the per-club config layer. Default off.

When the flag is off, no data sources register, no admin pages exist (Phase 3 honours the gate too), and the rest of the platform is unaware of the module.

## What's NOT in this PR

- **Migration `0061_custom_widgets`** + REST CRUD on `tt_custom_widgets` — Phase 2.
- **Admin builder page** (TalentTrack → Custom widgets) with multi-step UX (pick source → columns → filters → format → preview → save) — Phase 3.
- **Rendering engine** + persona-dashboard editor palette integration — Phase 4.
- **`tt_author_custom_widgets` cap** + per-widget transient cache (5-min TTL configurable, manual flush) + audit-log integration on save / publish / delete — Phase 5.
- **Docs** (`docs/custom-widgets.md` + Dutch twin) + ~30 new translatable strings + README link — Phase 6.

## Affected files

- `src/Modules/CustomWidgets/Domain/CustomDataSource.php` — new (interface).
- `src/Modules/CustomWidgets/CustomDataSourceRegistry.php` — new.
- `src/Modules/CustomWidgets/DataSources/PlayersActive.php` — new.
- `src/Modules/CustomWidgets/DataSources/EvaluationsRecent.php` — new.
- `src/Modules/CustomWidgets/DataSources/GoalsOpen.php` — new.
- `src/Modules/CustomWidgets/DataSources/ActivitiesRecent.php` — new.
- `src/Modules/CustomWidgets/DataSources/PdpFiles.php` — new.
- `src/Modules/CustomWidgets/CustomWidgetsModule.php` — new (module shell).
- `config/modules.php` — register `CustomWidgetsModule`.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

Zero new translatable strings — labels inside the source declarations are already wrapped in `__()` and surface via the Phase 3 builder UI (which doesn't ship until then).

## Player-centricity

Phase 1 is the catalogue layer for "what an admin can build a custom widget about." Every reference source registers around a player-centric or activity-centric table — players, evaluations, goals, activities, PDP files. The widget builder doesn't expose any data without a clear relationship to a player record. By the time Phase 4 lights up the rendering, every authored widget is answering a player-centric question by construction.
