# TalentTrack v4.1.6 — Analytics explorer PDF export (closes #875)

## Scope

Third + final follow-up under #0083 Child 3. With this ship, the trio of explorer follow-ups (chart in #873, drilldown in #874, PDF export here) is complete.

## Changes

### New `?action=export_pdf` branch on `FrontendExploreView`

Sibling to the existing `?action=export_csv`. Same URL shape (carries KPI + filters + group-by), different action key. New "Export PDF" button rendered next to the "Export CSV" button.

### `FrontendExploreView::streamPdf()`

Builds an HTML body containing:

- KPI label + ISO generation date
- Headline panel (current measure value, formatted)
- Filters summary line (compact)
- Either:
    - Grouped table (when the user picked a group-by) — dimension column + measure column
    - OR the top-50 drilldown rows + a "first N of M" count, when ungrouped

Hands the HTML to the shared `PdfRenderer` (from #0063, in tree since v3.110.0) along with an `ExportRequest` stub. The renderer wraps the body in its default DomPDF shell and returns an `ExportResult` carrying the bytes.

Failure mode: if `\Dompdf\Dompdf` isn't loadable (a dev environment that skipped `composer install`), the renderer throws `ExportException('no_renderer')`; the streamer catches and returns a 500 with a plain-text install hint.

### Filename

`<kpi-key>-<YYYY-MM-DD>.pdf` — matches the CSV pattern.

### Footer placeholder removed

All three follow-ups have landed (#873 chart, #874 drilldown, this ship). The placeholder banner is gone.

## Out of scope

- Chart.js canvas rasterised into the PDF — DomPDF can't run JS, and a server-side rasteriser is a much larger dependency than the analytical value justifies. The PDF carries the headline + tables which is what scheduled-reports cron will consume too.
- Brand-kit tokens beyond DomPDF's default body styles — a follow-up hooking `tt_pdf_render_html` can add the club's letterhead.
- XLSX export — covered by the Exports page (#864/#865) and not part of the explorer's per-view export set.

## Verification

- Open `?tt_view=explore` on any KPI. Click "Export PDF" → file downloads as `<key>-2026-05-21.pdf`.
- PDF opens; headline matches the on-screen headline; grouped table matches when a group-by is picked; drilldown rows match when ungrouped.
- Filter the view → "Filters" line in the PDF reflects the active filters.
- On a dev environment without DomPDF: clicking "Export PDF" returns a 500 with a plain-text install hint (no fatal).

## Versioning

Patch bump (4.1.5 → 4.1.6). Same 4.1.x epic-feature series as the chart + drilldown ships. The trio of #0083 Child 3 follow-ups is now complete.

## Closes

- #875 — Analytics explorer — PDF export
