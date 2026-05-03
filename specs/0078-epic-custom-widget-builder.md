---
id: 0078
type: epic
status: ready
title: Custom widget builder — admin-authored persona-dashboard widgets backed by registered data sources
shipped_in: ~
---

# 0078 — Custom widget builder

The "many widgets show no data" complaint from the v3.80.0 (#0077) follow-ups
is structural: most KPIs and table widgets are stubs returning
`KpiValue::unavailable()`. The user picked **Path B** over Path A — instead
of just wiring real queries into the closed catalogue, build the surface
that lets an admin compose their own widgets.

This spec locks the architecture for that surface.

## Decisions

All ten architecture forks were resolved in the shaping conversation. They
are repeated here so future readers see the locked answer alongside its
reasoning.

| # | Question | Decision |
|---|---|---|
| 1 | Data source layer | **Registered data-source classes only.** No free-text SQL, no visual SQL builder. Each data source is a PHP class implementing `CustomDataSource`; admins configure (filters, columns, label) but cannot author the underlying query. Free-text SQL on a multi-tenant SaaS-bound system is unsafe to walk back. |
| 2 | Output formats | **table + kpi + bar + line** for v1. Pie / donut / radar deferred (already covered by existing widgets). |
| 3 | Storage shape | **New table `tt_custom_widgets`** with a `definition` JSON column. Reusable across persona templates AND directly droppable on a player/team detail page later. |
| 4 | Authoring vs viewing permission | **`tt_author_custom_widgets`** (admin + tt_head_dev) for create/edit/delete; rendered widget inherits the viewer's existing tile/cap gates so a custom widget over `tt_players` only shows to viewers who can see players. |
| 5 | Tenancy / scoping | Locked by decision 1 — every data source class declares its tenancy via the existing `apply_demo_scope` + `club_id` helpers. |
| 6 | UX flow for authoring | **Dedicated admin page** under TalentTrack → Custom widgets. Inline right-rail editing in the persona-dashboard editor would crowd that surface. Preview lives on the builder page. |
| 7 | Caching | Per-widget transient cache, **5-minute TTL by default, configurable per-widget**, with a "clear cache now" button on the builder page. |
| 8 | Versioning + audit | Audit-log entry on save / publish / delete. **No per-version snapshots in v1.** Flagged as a future-add if operators ask. |
| 9 | Builder page location | TalentTrack → Custom widgets submenu (peer of "Dashboard layouts"). |
| 10 | Sequencing | Six phases as below; total ~120h. |

## Architecture overview

```
┌────────────────────────────────────────────────────────────────┐
│  TalentTrack admin → Custom widgets                           │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  Builder page — pick data source → configure columns +   │  │
│  │  filters + label + format → live preview → save          │  │
│  └──────────────────────────────────────────────────────────┘  │
│                            │                                   │
│              writes to     ▼                                   │
│        ┌──────────────────────────────────────┐                │
│        │  tt_custom_widgets (definition JSON) │                │
│        └──────────────────────────────────────┘                │
└────────────────────────────────────────────────────────────────┘
                              │
                              │ rendered via persona dashboard
                              ▼
┌────────────────────────────────────────────────────────────────┐
│  Persona-dashboard editor                                      │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  Palette: shipped widgets + "Custom widgets" (each       │  │
│  │  authored widget shows up as a draggable tile)           │  │
│  └──────────────────────────────────────────────────────────┘  │
│                            │ drag onto canvas                  │
│                            ▼                                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  CustomWidgetRenderer fetches data via the registered    │  │
│  │  data source, applies filters, formats per chart type    │  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘
```

### Data source class contract

```php
interface CustomDataSource {
    /** Stable id, e.g. 'players_active'. Used as foreign key in tt_custom_widgets. */
    public function id(): string;

    /** Human label for the picker. */
    public function label(): string;

    /** Columns the source exposes. Drives the column picker UI. */
    /** @return list<array{key:string,label:string,kind:'string'|'int'|'float'|'date'|'pill'}> */
    public function columns(): array;

    /** Filter declarations (which filters the source supports). */
    /** @return list<array{key:string,label:string,kind:'date_range'|'team'|'player'|'enum',...}> */
    public function filters(): array;

    /**
     * Fetch rows respecting tenancy + the supplied filters + the
     * requested column subset. Returns `list<array<string,mixed>>`
     * keyed by column id. The class enforces club_id and demo_scope.
     */
    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array;

    /** Aggregations the source exposes for KPI/bar/line widgets. */
    /** @return list<array{key:string,label:string,kind:'count'|'avg'|'sum'|'distinct'}> */
    public function aggregations(): array;
}
```

v1 ships **5 reference sources**:

1. `players_active` — columns: name, team, age, position, status; filters: team, age_range
2. `evaluations_recent` — columns: player, eval_date, type, overall; filters: date_range, team, eval_type
3. `goals_open` — columns: player, title, status, due_date, principle; filters: status, principle
4. `activities_recent` — columns: title, type, team, date, attendance_pct; filters: date_range, team, activity_type
5. `pdp_files` — columns: player, season, status, conversations_done, cycle_size; filters: season, status

Plugin authors can register additional sources via `CustomDataSourceRegistry::register()` — same pattern as `WidgetRegistry`.

### Schema — `tt_custom_widgets`

```sql
CREATE TABLE tt_custom_widgets (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    club_id         INT UNSIGNED NOT NULL DEFAULT 1,
    uuid            CHAR(36) NOT NULL UNIQUE,
    name            VARCHAR(120) NOT NULL,
    data_source_id  VARCHAR(80) NOT NULL,
    chart_type      ENUM('table','kpi','bar','line') NOT NULL,
    definition      JSON NOT NULL,
    -- definition shape: { columns: [...], filters: {...}, aggregation: {...},
    --                    cache_ttl_minutes: 5, format: {...} }
    created_by      BIGINT UNSIGNED,
    updated_by      BIGINT UNSIGNED,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    archived_at     DATETIME NULL,
    KEY club_idx (club_id),
    KEY source_idx (data_source_id)
);
```

Tenancy + uuid columns follow CLAUDE.md § 4 SaaS-readiness rules.

### REST endpoints

| Method | Path | Purpose | Cap |
|---|---|---|---|
| GET    | `/wp-json/talenttrack/v1/custom-widgets` | List all (current club) | `tt_view_persona_templates` |
| POST   | `/wp-json/talenttrack/v1/custom-widgets` | Create | `tt_author_custom_widgets` |
| GET    | `/wp-json/talenttrack/v1/custom-widgets/{id}` | Single definition | `tt_view_persona_templates` |
| PUT    | `/wp-json/talenttrack/v1/custom-widgets/{id}` | Update | `tt_author_custom_widgets` |
| DELETE | `/wp-json/talenttrack/v1/custom-widgets/{id}` | Soft-delete | `tt_author_custom_widgets` |
| GET    | `/wp-json/talenttrack/v1/custom-widgets/{id}/data` | Render-time data fetch | inherits source's view cap |
| GET    | `/wp-json/talenttrack/v1/custom-data-sources` | Catalogue for the builder UI | `tt_author_custom_widgets` |
| POST   | `/wp-json/talenttrack/v1/custom-widgets/{id}/clear-cache` | Manual cache flush | `tt_author_custom_widgets` |

### Persona-dashboard integration

`WidgetRegistry` gains a synthetic `custom_widget` widget id whose `data_source` slot field carries the `tt_custom_widgets.uuid`. `CustomWidgetRenderer::render()` resolves the uuid → definition → data fetch → chart-type-specific HTML.

In the editor's palette, registered custom widgets appear in a new "Custom widgets" group below the existing widgets/KPIs tabs. Each shows up as a draggable tile labelled with the widget's `name`. Drag onto canvas → standard slot placement.

## Phase plan

| Phase | Scope | Estimate |
|---|---|---|
| 1 | `CustomDataSource` interface + registry + 5 reference sources (players/evaluations/goals/activities/pdp). Each source implements columns / filters / fetch / aggregations honouring `club_id` + `apply_demo_scope`. | ~25h |
| 2 | Migration `0061_custom_widgets` (schema + uuid + tenancy column). REST CRUD on widget definitions (`/custom-widgets`). REST list of available sources (`/custom-data-sources`). | ~15h |
| 3 | Builder admin page — TalentTrack → Custom widgets. Multi-step UX: pick source → pick columns → configure filters → choose format (table/kpi/bar/line) → preview → name → save. JS-heavy, vanilla JS like the persona-dashboard editor. | ~25h |
| 4 | Rendering engine. `CustomWidgetRenderer` class handles all 4 chart types. Chart.js for bar/line; native HTML for table/kpi. Persona-dashboard editor palette gains a "Custom widgets" group sourced from `tt_custom_widgets`. | ~25h |
| 5 | Cap layer (new `tt_author_custom_widgets`), per-widget transient cache with TTL, audit-log entries on save/publish/delete, manual clear-cache button. | ~20h |
| 6 | Docs (`docs/custom-widgets.md` + nl_NL), i18n (~30 new strings), README link, SEQUENCE.md update. | ~10h |
| | **Total** | **~120h** |

## Out of scope (deferred)

- Free-text SQL access — security risk too high for a multi-tenant install.
- Visual SQL builder (drag tables, joins) — significant additional UI work; revisit if data-source classes prove too rigid.
- Per-version widget history — audit log captures who/when/what change-type, but no rollback in v1.
- Pie / donut / radar charts — already covered by shipped widgets where useful.
- Cross-source joins — each widget reads exactly one data source. Operators wanting joined data ask for a new data source class.
- Author-defined custom data sources via UI — only PHP-registered sources in v1.
- Per-row drilldown links from a custom widget table → record detail page. v1 ships read-only; clickable rows in v2.

## Definition of done

A reviewer should be able to answer yes to all of:

- Operator can create a "Top 10 active players" custom widget from the Players source, save it, and drag it onto a persona dashboard.
- Operator can create a "Average evaluation rating per coach (last 30 days)" KPI from the Evaluations source.
- Operator can create a "Goals per principle" bar chart from the Goals source.
- Custom widget data respects `club_id` (multi-tenant) AND `apply_demo_scope` (demo mode).
- Cap-revoked viewer (no `tt_view_evaluations`) can't see an evaluations-backed custom widget.
- Cache flushes on save/edit and manual button click.
- Audit log shows `custom_widget.published` / `custom_widget.deleted` entries.
- Spec is shipped under a single feature flag `tt_custom_widgets_enabled` (default off) so beta installs can opt in.

## Open questions for the next session

None at architecture level. Per-phase shaping happens at start of each phase.

## Trigger to start

Free now (no upstream blockers). Recommend kicking off Phase 1 in a fresh session.
