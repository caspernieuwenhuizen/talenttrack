# TalentTrack v4.3.18 — Team planner Export PDF + XLSX buttons (closes #947)

## What changed

New action row at the top of `?tt_view=team-planner` carries two side-by-side form-POST buttons that download a printable schedule for the currently-picked team over the planner's currently-visible date range. Coach picks the team + range once in the planner; the buttons pre-fill those values into the export request.

## Building blocks — what's free vs. what's new

| Piece | Status |
|---|---|
| `TeamActivitiesCsvExporter` — XLSX format | **Already shipped** (`supportedFormats = ['csv', 'xlsx']`). Reused as-is for the XLSX button. |
| `XlsxRenderer` (PhpSpreadsheet) | Already shipped (v3.110.0). |
| `PdfRenderer` (DomPDF) | Already shipped. |
| `TeamPlanningPdfExporter` | **NEW**. |
| Buttons on the planner | **NEW** — two form-POST `<form>`s in a new `renderExportActions()` helper. |

## New exporter — `TeamPlanningPdfExporter`

`src/Modules/Export/Exporters/TeamPlanningPdfExporter.php`. Implements `ExporterInterface`:

- `key()` = `'team_planning'`
- `supportedFormats()` = `['pdf']`
- `requiredCap()` = `'tt_view_activities'` (matches the CSV/XLSX exporter)
- `validateFilters()` — `team_id` required (> 0), `date_from` / `date_to` default to today → +28 days
- `collect()` — one row per `tt_activities` row in (club_id, team_id, date range), ordered by date + kickoff_time. Returns `['html' => …, 'options' => ['paper' => 'A4', 'orientation' => 'portrait']]`. The `PdfRenderer` generates the filename + mime from the exporter key + ISO date.

PDF layout (single page if the range fits, multi-page otherwise):

- Title block: team name + ISO date range.
- Single table: Date · Day · Time · Type · Title · Opponent · Location.
- Footer: generated-at timestamp.

Registered in `ExportModule::boot()` alongside the existing exporters.

## Planner UI — `renderExportActions()`

`src/Modules/Planning/Frontend/FrontendTeamPlannerView.php` — new private static method called from `render()` after the toolbar, before the range grid. Two side-by-side forms targeting `admin-post.php?action=tt_export` (the form-POST architecture from #939):

```html
<div class="tt-planner-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
  <form method="POST" action="<admin-post.php>">
    <!-- wp_nonce_field('tt_export', '_tt_export_nonce') -->
    <input type="hidden" name="action"               value="tt_export">
    <input type="hidden" name="tt_export_key"        value="team_planning">
    <input type="hidden" name="format"               value="pdf">
    <input type="hidden" name="team_id"              value="<id>">
    <input type="hidden" name="date_from"            value="<yyyy-mm-dd>">
    <input type="hidden" name="date_to"              value="<yyyy-mm-dd>">
    <input type="hidden" name="tt_export_return_url" value="<planner-url>">
    <button type="submit" class="tt-btn tt-btn-secondary">Export PDF</button>
  </form>

  <form method="POST" action="<admin-post.php>">
    <!-- same shape, tt_export_key=team_activities, format=xlsx -->
    <button type="submit" class="tt-btn tt-btn-secondary">Export XLSX</button>
  </form>
</div>
```

Buttons wrap to a vertical stack below ~480px via the parent flex container's `flex-wrap`; each at 48px min-height so they meet the mobile-first tap-target floor.

## CI gate compatibility

- #940 Scan A — every new hidden-field name (`action`, `tt_export_key`, `format`, `team_id`, `date_from`, `date_to`, `tt_export_return_url`) is outside the WP-reserved-public-query-var set. Pass.
- #940 Scan B — no `add_query_arg(['tt_view' => 'wizard', …])` introduced; the buttons go to `admin_url('admin-post.php')`. Pass.

## Permissions

- View access to the planner is already gated on `tt_view_plan` at `render()` entry.
- Both exporters declare `requiredCap = 'tt_view_activities'`. `ExportService` (the dispatcher invoked by the admin-post handler) re-checks the cap before running, so the page-level guard and the exporter-level guard line up.

## Out of scope

- iCal subscription model (explicitly excluded — coaches handle phone-calendar sync separately).
- Custom date-range picker on the planner (uses the planner's currently-visible range).
- Multi-team "all my teams' planning" — that's the bulk exporter on the central Exports page.
- Async export pipeline — sync is fine at the data volumes a team's schedule represents (max ~100 rows over a season).
- Per-export branding picker (uses the install's default brand-kit tokens).

## Why patch

Enhancement on an existing surface within the 4.3 minor. The team planner was the minor epic; adding two export buttons stays patch — consistent with how the VCT module's per-tile additions (v4.3.11/12/13) all bumped patch within the 4.3 minor. No new cap, no new schema, no new contract.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.17` → `4.3.18`.
