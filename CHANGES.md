# TalentTrack v3.109.5 — Custom widget builder Phase 4: rendering engine + persona-dashboard editor palette (#0078 Phase 4)

Phase 4 of #0078 Custom widget builder. v3.109.4 shipped Phase 3 (admin builder UX). Phase 4 hooks the saved widgets into the front-end persona-dashboard render path so operators can drag a custom widget onto a dashboard and see real data.

## What landed

### Rendering engine

`Modules\CustomWidgets\Renderer\CustomWidgetRenderer` — central static renderer.

- Resolves `uuid` → `CustomWidget` value object via the Phase 2 repository.
- Looks up the registered `CustomDataSource` via `CustomDataSourceRegistry::find()`.
- Calls `$source->fetch( $user_id, $filters, $columns, $limit )` with the saved column subset + filters; per-chart-type limit (KPI = 1, bar/line = 50, table = 100).
- Emits HTML per `chart_type`:
  - `table` — semantic `<table>` over the saved columns. Each header reads from the source's column metadata; cells fall back to the row keys when no saved subset exists.
  - `kpi` — big-number frame. Prefers a numeric column over a label column from the first row; falls back to first column when nothing is numeric. Uses `number_format_i18n()` for locale-correct formatting.
  - `bar` / `line` — `<canvas>` + Chart.js boot script. Labels come from row column 0; values from row column 1 (or column 0 if there's only one).
- Empty data → empty-state message ("No rows to show.").
- Missing widget / source → graceful stub (renders the whole frame intact).

### Chart.js (CDN at v4.4.0)

`enqueueChartJsIfNeeded()` registers the same CDN URL the comparison-view + radar-card paths already use (`https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js`) — sharing the browser cache entry. The first custom widget render on a page enqueues it; subsequent renders are idempotent (`$chartjs_enqueued` flag).

The inline boot script per chart is small, idempotent (`el.dataset.ttBound`), and tolerates Chart.js loading later in the load order via a `setTimeout(b, 80)` retry. Bar / line charts default to `responsive: true`, `maintainAspectRatio: false`, no legend (single dataset is implicit from the title), `y.beginAtZero: true`.

### Persona-dashboard palette integration

`Modules\CustomWidgets\Widgets\CustomWidgetWidget` — synthetic Widget registered with the persona-dashboard `WidgetRegistry`:

- `id() = 'custom_widget'` — the editor's palette gets a single tile labelled "Custom widget."
- `dataSourceCatalogue()` returns `[uuid => name]` for every non-archived saved widget for the current club. The editor's data-source picker (already widget-aware via the `dataSourceCatalogue()` extension shipped in #0077 M1) doubles as the custom-widget picker. No registry-wide refactor needed.
- `render()` reads `slot->data_source` (the chosen widget's uuid), delegates to `CustomWidgetRenderer::render()`, and wraps the output in the standard bento-grid frame from `AbstractWidget::wrap()`.
- Empty `data_source` slot → "Pick a custom widget for this slot" stub. Operators see the issue and pick a widget from the properties panel.

### Render CSS

`assets/css/custom-widgets-render.css` — render-side styles paired with the renderer output:

- Outer `.tt-cw-render` flex frame.
- Title row + empty-state copy.
- `.tt-cw-render-table` semantic table with sticky uppercase header tracking, alternating row separators.
- `.tt-cw-kpi` centred big number (36px, 32px on phone) + small label.
- Chart-canvas host with min-height 200px so the chart frame survives empty-data fetches.
- Stub state (dashed border, muted text) for the missing-uuid / missing-source paths.

Mobile-first per CLAUDE.md §2; tokens (`--tt-bg-soft`, `--tt-line`, `--tt-ink`, `--tt-muted`) inherited from the brand-kit layer with hardcoded fallbacks.

### Module wiring

`CustomWidgetsModule::boot()`:

- Registers `CustomWidgetWidget` on `init@20` (after `WidgetRegistry` boots).
- Enqueues `tt-custom-widgets-render` on `wp_enqueue_scripts` so dashboards rendering custom widgets get the styles automatically. Per-row chart bindings come from the inline boot script (no global JS bundle for the renderer).

Module stays opt-in via `tt_custom_widgets_enabled` (default off).

## What's NOT in this PR (still in Phases 5-6)

- **Phase 5 — Cap layer + cache + audit.** New `tt_author_custom_widgets` cap (top-up migration). Per-widget transient cache with the configurable TTL. Audit-log entries on save / publish / delete. Manual clear-cache button wired (the Phase 2 `tt_custom_widget_cache_flush_requested` hook already exists; Phase 5 plugs the listener). Source-cap inheritance check at render time so a viewer without `tt_view_evaluations` can't see an evaluations-backed custom widget.
- **Phase 6 — Docs + i18n + README.** `docs/custom-widgets.md` (EN+NL). README link.

## Translations

2 new NL msgids: "Custom widget" (the widget label in the editor) and "Pick a custom widget for this slot." (the empty-data-source stub).

## Notes

No schema changes. No new caps. No cron. No license flips. The renderer emits inline `<script>` tags for chart bootstrapping — these are output through `wp_json_encode()` so the data is safely serialised. The CDN script for Chart.js itself only loads when a chart-type custom widget actually appears on the page.
