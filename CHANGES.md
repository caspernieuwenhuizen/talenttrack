# TalentTrack v3.110.4 — First binary export use case: player evaluation PDF (#0063 use case 1)

First real consumer of the v3.110.0 `PdfRenderer`. Wraps the existing `Modules\Reports\PlayerReportRenderer::renderStandard()` HTML through the new exporter pipeline, producing the canonical "player evaluation report" PDF deliverable from #0014 — but reachable from anywhere via the central Export module rather than only from the on-screen Reports page.

## What landed

### `PlayerEvaluationPdfExporter` (`exporter_key = player_evaluation_pdf`, use case 1)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/player_evaluation_pdf?format=pdf&player_id=42`

**Filters**:

- `player_id` (REQUIRED) — the player to render the report for; tenant-scoped via `QueryHelpers::get_player()` so a logged-in user can't fetch a report for a player in another club by guessing the id.
- `date_from` / `date_to` (optional ISO dates) — restrict the evaluation window.
- `eval_type_id` (optional positive int) — filter to one evaluation type.

**Cap**: `tt_view_evaluations` — same gate as the on-screen Reports view.

**Layout**: reuses the existing `PlayerReportRenderer` shell (header / player card / headline ratings / category breakdown / charts placeholder / goals / attendance / activities / coach notes / footer). The PDF and the on-screen view stay in lockstep without forking the layout — fixes to the renderer propagate to both surfaces simultaneously.

**Chart caveat (v1 limitation)**: the renderer's HTML carries a `<script>` block that drives Chart.js client-side. DomPDF doesn't execute JavaScript, so the radar + line-chart canvases render empty in the PDF. The exporter strips the script block before handoff (so the PDF doesn't carry dead bytes and so the same HTML can't accidentally execute when reused in a browser preview). A future ship swaps the canvases for server-rendered SVG; the brand-kit template-inheritance work already deferred from v3.110.0 is the natural place for that pass.

**Module wiring**: registered in `ExportModule::boot()` alongside the v3.105.0 / v3.109.0 / v3.110.0 entries.

## What's NOT in this PR

- **Server-side chart rendering** (SVG / pre-baked PNG) — deferred to the brand-kit template-inheritance ship; lands when the renderer earns it across multiple PDF use cases.
- **Brand-kit letterhead inheritance** — `tt_pdf_render_html` filter exists today (v3.110.0); the exporter doesn't prepend letterhead, individual consumers can hook the filter when they need it.
- **Async dispatch** — synchronous-only via the standard REST stream-and-exit path. The 11 remaining export use cases that genuinely need async still gate on the Action Scheduler integration (deferred to first big-export use case, likely the GDPR subject-access ZIP).
- **Audience variants** beyond `STANDARD` — `ReportConfig::standard()` is the v1 audience; per-audience tone variants land when the calling surfaces ask for them.
- **The 8 other deferred Export use cases** (2, 4, 6, 8, 9, 10, 13, 14, 15 — i.e. all of #0063's 15 minus already-shipped 1, 3, 5, 7, 11, 12) — each lands behind its first consumer.

## Notes

- Zero new operator-facing strings — exporter label uses an existing `__()` pattern; the report body's strings are all in the existing `PlayerReportRenderer` translation set.
- No new migrations.
- No composer dependency changes (DomPDF was added in v3.110.0).
- Renumbered v3.110.2 → v3.110.4 mid-rebase against parallel-agent ship of v3.110.3 (player profile polish: profile-tab table + tab bug fixes + analytics 30-day card + notes wiring) which took the v3.110.3 slot.
