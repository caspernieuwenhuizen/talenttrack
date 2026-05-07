# TalentTrack v3.110.5 — Second binary export use case: PDP / development plan PDF (#0063 use case 2)

Second consumer of the v3.110.0 `PdfRenderer`, sibling to v3.110.4's player evaluation PDF. The PDP / development plan PDF is the formal-plan deliverable from #0044, often printed for parent meetings; reaching it via the central Export module rather than only through `?tt_pdp_print=1` lets future surfaces (a "Send PDP to parent" button, a "Generate end-of-cycle PDFs" batch action) consume the same artifact through the standard exporter pipeline.

## What landed

### `PdpPdfExporter` (`exporter_key = pdp_pdf`, use case 2)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/pdp_pdf?format=pdf&file_id=42`
Optional `&include_evidence=1` appends a second-A4 evidence page (last 5 evaluations + last 10 attendance rows) — same toggle the on-screen print path ships.

**Filters**:

- `file_id` (REQUIRED) — the PDP file id to render.
- `include_evidence` (optional bool) — append the evidence second page.

**Cap**: `tt_view_pdp` at the route level, plus the per-file `PdpPrintRouter::canAccess()` check inside `collect()` mirroring the print path's authorization (admin / coach-of-this-player / linked self-player / linked parent). Without the per-file gate a logged-in user with global `tt_view_pdp` could fetch any PDP in the club; the per-file check narrows to the access matrix.

**Layout reuse — `PdpPrintRouter::renderHtml()` extracted**: the on-screen print path's HTML-emit logic was lifted into a public `PdpPrintRouter::renderHtml( object $file, bool $include_evidence ): string` so the exporter and the print path share their layout instead of forking. `PdpPrintRouter::canAccess()` was also promoted from `private` to `public` for the exporter's per-file check. The print path's behaviour is unchanged — `maybeRender()` now does `echo self::renderHtml(...)` instead of `self::emit(...)` directly.

**Toolbar strip**: the print layout's `<div class="toolbar">` carries the browser-side Print / Re-render / Close buttons; `@media print` hides them when the user prints from a browser, but DomPDF doesn't honour print-media queries. The exporter strips the toolbar `<div>` before handing the HTML to `PdfRenderer` — same pattern as v3.110.4's `<script>` strip on the player-evaluation PDF.

**Module wiring**: registered in `ExportModule::boot()` alongside the v3.105.0 / v3.109.0 / v3.110.0 / v3.110.4 entries.

## What's NOT in this PR

- **The `?tt_pdp_print=1` print path** stays in place — this exporter is additive. Operators using the existing print + browser-print flow continue to work; the exporter route opens the PDF up to other surfaces (Comms attachments, batch generation).
- **Brand-kit letterhead inheritance** — `tt_pdf_render_html` filter exists today; consumers can hook it.
- **Per-club PDP layout variants** — the layout is the existing print template; per-club overrides land if a customer asks.
- **Async dispatch** — synchronous-only via the standard REST stream-and-exit path.
- **The 7 other deferred Export use cases** (4, 6, 8, 9, 10, 13, 14, 15) — each lands behind its first consumer.

## Notes

- Zero new operator-facing strings — exporter label uses an existing `__()` pattern; the print template's strings are all already in `PdpPrintRouter`'s translation set.
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.5 against any parallel-agent ship that took a v3.110.x slot during build.
