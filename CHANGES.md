# TalentTrack v3.109.3 — Custom widget builder Phase 2: migration + repository + REST CRUD + service (#0078 Phase 2)

Second phase of #0078 Custom widget builder. v3.106.2 shipped Phase 1 (the `CustomDataSource` interface + registry + 5 reference sources, feature-flag-gated). Phase 2 adds the persistence layer + REST surface on top so a future Phase 3 builder UI can persist authoring choices.

## What landed

### Migration `0076_custom_widgets`

`tt_custom_widgets` schema with `club_id` + `uuid` SaaS-readiness columns per CLAUDE.md §4. The `uuid` doubles as the slot-config foreign key once Phase 4 wires the persona-dashboard editor palette (a slot pointing at a custom widget stores `data_source: <uuid>` so renames don't break placements). `archived_at` is the soft-delete tombstone.

`data_source_id` is the registry key (e.g. `players_active`); not a foreign key — sources are PHP classes, not DB rows. `chart_type` is a `VARCHAR(16)` rather than `ENUM(...)` so adding a new chart type later doesn't require a schema migration; the service-layer whitelist enforces values today.

Idempotent `CREATE TABLE IF NOT EXISTS` via dbDelta.

### Domain value object

`Modules\CustomWidgets\Domain\CustomWidget` — immutable, with a `CHART_TYPES` constant (`table` / `kpi` / `bar` / `line` per spec decision 2; pie / donut / radar deferred) and a `toArray()` method for the REST envelope.

### Repository

`Modules\CustomWidgets\Repository\CustomWidgetRepository` — `listForClub` / `findById` / `findByUuid` / `create` / `update` / `softDelete`. Every read and write scopes to `CurrentClub::id()`. Definition JSON round-trips via `wp_json_encode()` / `json_decode()` so the caller sees hydrated arrays.

### Service layer

`Modules\CustomWidgets\CustomWidgetService` — validation + create/update/archive orchestrator on top of the repository. Validates:

- name length (1-120 chars),
- data-source id (must be registered in `CustomDataSourceRegistry`),
- chart type (must be one of `CustomWidget::CHART_TYPES`),
- columns (must intersect the source's declared `columns()`; required for `table`),
- filters (drops unknown keys; type-coerces values),
- aggregation (mandatory for `kpi`/`bar`/`line`; key must intersect the source's declared `aggregations()`),
- cache TTL (clamped to `[0, 1440]` minutes — 24h ceiling).

Throws `CustomWidgetException` with a discriminated kind on every validation rule. Phase 5 hooks the audit + cache-flush calls into this service.

### REST controller

`Modules\CustomWidgets\Rest\CustomWidgetsRestController` registers the 8 endpoints from the spec:

| Method | Path | Purpose |
|---|---|---|
| GET    | `/custom-widgets` | List (current club) |
| POST   | `/custom-widgets` | Create |
| GET    | `/custom-widgets/{id}` | Single (id or uuid) |
| PUT    | `/custom-widgets/{id}` | Update (id or uuid) |
| DELETE | `/custom-widgets/{id}` | Soft-delete (id or uuid) |
| GET    | `/custom-data-sources` | Catalogue for the builder UI |
| GET    | `/custom-widgets/{id}/data` | Render-time preview fetch |
| POST   | `/custom-widgets/{id}/clear-cache` | Manual cache flush |

Caps: all routes gated on `tt_edit_persona_templates` for Phase 2. Phase 5 swaps the write routes for the new `tt_author_custom_widgets` cap (added via top-up migration alongside the cap-layer ship).

The data-fetch route (`/custom-widgets/{id}/data`) calls into the registered source's `fetch()` directly — Phase 4 replaces the body with a future `CustomWidgetRenderer::fetchRows()` that adds caching + source-cap inheritance. Phase 2 exposes the route shape so the builder UI's preview can hit it.

The clear-cache endpoint emits a `tt_custom_widget_cache_flush_requested` action so Phase 5's transient-cache layer can listen without changing the route shape.

### Module wiring

`CustomWidgetsModule::boot()` — feature-flag-gated registration extended to call `CustomWidgetsRestController::init()`. Module stays opt-in via `tt_custom_widgets_enabled` (default off). Beta installs flip it on with `wp option update tt_custom_widgets_enabled 1`.

## Discriminated error → HTTP status mapping

The REST controller's `errorFromKind()` collapses the discriminated `CustomWidgetException` kinds into HTTP statuses so callers see the right code without knowing what each validation rule did:

```
not_found            → 404
forbidden            → 403
invalid_chart_type   → 400
unknown_data_source  → 400
missing_columns      → 400
missing_aggregation  → 400
bad_aggregation      → 400
bad_name             → 400
```

Anything else falls through to a 500.

## What's NOT in this PR (still in Phases 3-6)

- **Phase 3 — Builder admin page** (TalentTrack → Custom widgets). Multi-step UX: pick source → pick columns → configure filters → choose format → preview → name → save. Vanilla JS like the persona-dashboard editor.
- **Phase 4 — Rendering engine + persona-dashboard editor palette**. `CustomWidgetRenderer` for table / kpi / bar / line; Chart.js for bar / line. Editor palette gains a "Custom widgets" group sourced from `tt_custom_widgets`.
- **Phase 5 — Cap layer + cache + audit**. New `tt_author_custom_widgets` cap (top-up migration). Per-widget transient cache with the configurable TTL. Audit-log entries on save / publish / delete. Manual clear-cache button wired.
- **Phase 6 — Docs + i18n + README**. `docs/custom-widgets.md` (EN+NL). ~30 new translatable strings (mostly Phase 3 builder UI). README link.

## Translations

Zero new NL msgids — Phase 2 is internal infrastructure. The builder UI labels (Phase 3) ship the translatable copy.

## Notes

No new caps in this PR (Phase 5 ships them). No new wp-cron schedules. No license-tier flips. The route gate uses an existing cap so the route is invokable today on installs that flip the feature flag on.

Renumbered v3.109.2 → v3.109.3 mid-rebase after parallel-agent ship of v3.109.2 (#295 seed-review Excel export) took the v3.109.2 slot.
