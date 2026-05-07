# TalentTrack v3.110.7 — Fourth binary export use case: player one-pager A5 PDF (#0063 use case 13)

Fourth PDF use case in the family started by v3.110.4 (player evaluation), v3.110.5 (PDP), v3.110.6 (scouting report). This one ships a bespoke compact A5 layout — the spec calls for a "trial card" / "scout visit handout" with a tight set of fields (photo, name, age, position, status, jersey, team), and the multi-section eval-report shell would overflow and crowd at A5. Built standalone instead of wrapping `PlayerReportRenderer`, while still using the v3.110.0 `PdfRenderer` pipeline.

## What landed

### `PlayerOnePagerPdfExporter` (`exporter_key = player_onepager_pdf`, use case 13)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/player_onepager_pdf?format=pdf&player_id=42`

**Filters**:

- `player_id` (REQUIRED) — tenant-scoped via `QueryHelpers::get_player()`.

**Cap**: `tt_view_players` — same gate as the squad-list export. The one-pager carries less data than the eval report and goes to trial / scout visits where a broader read group needs it.

**Fields surfaced** (per spec):

- Photo (rounded, 32mm × 32mm)
- Name (large headline)
- Team name (subtitle under name when set)
- Date of birth + computed age in years
- Primary position (first comma-separated value of `preferred_positions`)
- Preferred foot
- Jersey number
- Status (translated label — `active` / `archived` / `trial` / `released` / `contracted` / `inactive`)
- Generated date footer

**Layout**: A5 portrait, 12mm margins, header row with photo + identity block, then a 5-row facts table. Self-contained CSS in the document `<head>` (DomPDF can't follow external stylesheet refs reliably; the inline approach keeps the artifact portable). DejaVu Sans font matches the v3.110.0 `PdfRenderer` default. Mobile-first concerns don't apply — this is a print artifact.

**No ratings, no goals, no contact details**: those live in the eval report (use case 1) and the scouting report (use case 14) respectively. The one-pager is deliberately compact.

**Module wiring**: registered in `ExportModule::boot()` alongside the v3.105.0 / v3.109.0 / v3.110.0 / v3.110.4 / v3.110.5 / v3.110.6 entries. Foundation now at 9 of 15 use cases live.

## What's NOT in this PR

- **Brand-kit letterhead** — `tt_pdf_render_html` filter exists today; consumers can hook.
- **Per-club layout variants** — the spec's field list is the v1 layout; per-club variants land if a customer asks.
- **Async dispatch** — synchronous-only via the standard REST stream-and-exit path.
- **The 6 remaining deferred Export use cases** (4, 6, 8, 9, 10, 15) — each lands behind its first consumer.

## Notes

- 8 new operator-facing strings (the field labels: *Date of birth*, *Position*, *Preferred foot*, *Jersey*, *Status* + the status-label set + the "Generated %s" footer + the "%1$s (age %2$s)" DOB format). Most were already in other surfaces but a few were new for the compact-card context.
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.7 against any parallel-agent ship that took a v3.110.x slot during build.
