# TalentTrack v4.1.4 — Analytics explorer time-series chart (closes #873)

## Scope

First of three follow-ups under #0083 Child 3 — the dimension explorer at `?tt_view=explore`. The original ship landed the headline + filters + group-by table; this ship adds the Chart.js line chart that the placeholder text promised.

## Changes

### `FrontendExploreView::renderTimeSeriesChart()` (new)

Sits between the headline panel and the existing CSV export link. Runs `FactQuery::run( $factKey, [$primaryDimension], [$measureKey], $filters )` to bucket the measure by the KPI's `primaryDimension` (typically `month` or `week`).

- **Ungrouped path**: a single line.
- **Grouped path** (when the user picks a `group_by`): runs the query with `[$primaryDimension, $group_by]` and emits one line per group.
- **Series cap = 6**: when more than 6 groups appear, the tail (by series total) collapses into "Other" so the legend stays legible.
- **Missing buckets** render as `null` with Chart.js `spanGaps: true` — a line breaks rather than zero-dipping through a gap.
- **Empty data**: when `FactQuery` returns zero rows OR no usable bucket labels resolve, a `.tt-empty` panel renders in place of the canvas.

### KPI gate

Chart is gated on `Kpi::primaryDimension` being set AND the fact actually declaring that dimension. KPIs with `primaryDimension = null` (snapshot values with no temporal axis) skip the chart entirely.

### Chart.js wiring

Reuses Chart.js 4.4.0 from the same CDN URL `FrontendComparisonView` enqueues. Loaded on demand via `wp_enqueue_script`; no global enqueue. Init script is a small inline IIFE that polls `window.Chart` until the library lands then constructs the line chart from a JSON config in a sibling `<script type="application/json">`.

### URL state

No new query params. The existing `?tt_view=explore&kpi=…&filter_…=…&group_by=…` URL fully describes the chart — sharing a link reproduces the chart shape.

### Placeholder

The footer placeholder updates to drop "time-series chart" from the remaining-follow-ups list. Two follow-ups still ship: drilldown to fact rows (#874) and PDF export (#875).

## Out of scope

- Trend arrow + delta-vs-previous-period (separate concern; spec says "can ride along if cheap, but no commitment").
- Combobox filter chips over real dimension values (separate spec).
- Per-bucket drill-down (covered by #874).
- Mobile chart variant — the explorer view is `desktop_only` per #0084 Child 1.

## Verification

- Pick an attendance KPI with `primaryDimension = month`. Chart shows monthly bucket points; legend hidden (single series).
- Pick a group-by (team) → chart switches to one line per team, legend visible.
- Pick a group-by with 8 teams → 5 teams shown + "Other" rolled-up line.
- Filter to a window with no rows → `.tt-empty` panel appears in place of the canvas.
- Share the URL — reload reproduces the chart shape.

## Versioning

Patch bump (4.1.3 → 4.1.4). New behaviour but small + isolated; part of the existing 4.1.x feature series for the analytics + behaviour-discoverability epics.

## Closes

- #873 — Analytics explorer — time-series chart
