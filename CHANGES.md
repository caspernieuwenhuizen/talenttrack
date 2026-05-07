# TalentTrack v3.109.4 — Custom widget builder Phase 3: TalentTrack → Custom widgets admin page + multi-step builder (#0078 Phase 3)

Phase 3 of #0078 Custom widget builder. v3.106.2 shipped Phase 1 (data-source layer); v3.109.3 shipped Phase 2 (migration + REST CRUD + service). v3.109.4 adds the admin authoring UX on top so operators can build a widget without writing any code.

## What landed

### Admin page

`Modules\CustomWidgets\Admin\CustomWidgetsAdminPage` registered under TalentTrack → Custom widgets via the existing `AdminMenuRegistry` pattern:

- `parent: 'talenttrack'`, `group: 'configuration'`, `order: 35` — sits next to Dashboard layouts.
- Cap-gated on `tt_edit_persona_templates` (Phase 5 swaps for the dedicated `tt_author_custom_widgets` cap when the cap layer ships).
- Two views inside one slug:
  - **List view** (default) — every saved widget for the current club, with `Edit` / `Archive` buttons. Archive routes through `admin-post.php?action=tt_custom_widget_archive&id=N` behind a per-row nonce.
  - **Builder view** (`?action=new` or `?action=edit&id=N`) — the multi-step authoring UX.

### Multi-step builder

Six steps: **Source → Columns → Filters → Format → Preview → Save**.

Server-rendered shell (a stepper + body container + nav buttons) + 470-LOC vanilla JS state machine in `assets/js/custom-widgets-builder.js`. Each step renders dynamically from the active source's metadata — picking a different source on step 1 rewires the column / filter / aggregation inputs in steps 2-4 without a page reload.

The bootstrap blob the page localizes into `window.TTCustomWidgetsBootstrap` carries the full sources catalogue (id + label + columns + filters + aggregations) so the builder doesn't need a round-trip on first paint.

### Live preview

The Preview step calls a `saveDraft()` helper that POSTs (new) or PUTs (edit) the in-progress widget definition through the Phase 2 REST endpoint, then GETs `/wp-json/talenttrack/v1/custom-widgets/{uuid}/data?limit=20` and renders the rows. Two render modes:

- **Table preview** — `<table>` over the returned rows; columns auto-derived from the row keys.
- **KPI preview** — single big number drawn from the first row's first column.

Bar / line preview is text-rendered today; Phase 4 wires Chart.js for the persona-dashboard render path.

### Validation

Step-level validators surface inline before advancing:

- Step 1 (Source) — must pick one.
- Step 2 (Columns) — required for `table`; ignored for `kpi`/`bar`/`line`.
- Step 4 (Format) — must pick a chart type; non-table types must also pick an aggregation.
- Step 6 (Save) — name required (1-120 chars).

Server-side validation is the Phase 2 service layer; the JS validators just front-load the obvious failures so the operator sees them immediately.

### Configuration tile

`addBuilderTile()` filters into `tt_config_tile_groups` so admins discover the new page from the Configuration tile-landing the same way they reach Branding, Translations, and Dashboard layouts. Tile lands in the *Branding* group when present, falls back to a new *Personas* group otherwise.

### Mobile-first CSS

`assets/css/custom-widgets-builder.css` — stepper + radio source cards + checkbox column grid + per-filter row + chart-type cards + preview surface. The builder lives in wp-admin so the desktop floor is fine, but inputs honour the 16px font-size + 48px touch-target rules from CLAUDE.md §2.

## Translations

24 new NL msgids covering builder copy: stepper labels, step body headings, validation messages, preview status, save/saving/saved labels, archive confirmation. Dutch translations land in `nl_NL.po`.

## What's NOT in this PR (still in Phases 4-6)

- **Phase 4 — Rendering engine + persona-dashboard editor palette.** `CustomWidgetRenderer` for table / kpi / bar / line; Chart.js for bar / line. Editor palette gains a "Custom widgets" group sourced from `tt_custom_widgets`.
- **Phase 5 — Cap layer + cache + audit.** New `tt_author_custom_widgets` cap (top-up migration). Per-widget transient cache with the configurable TTL. Audit-log entries on save / publish / delete. Manual clear-cache button wired.
- **Phase 6 — Docs + i18n + README.** `docs/custom-widgets.md` (EN+NL). README link.

## Notes

No schema changes. No new caps. No cron. No license flips. The builder is still gated behind the `tt_custom_widgets_enabled` feature flag (default off).
