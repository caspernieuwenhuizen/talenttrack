# TalentTrack v3.110.14 — Seventh Export use case: activity brief PDF (#0063 use case 8)

Per user-direction shaping (2026-05-08): ship v1 without field diagrams (the spec's "A4 with field diagrams" — diagrams need a `tt_session_drills` sub-entity that doesn't exist today). v1 prints the activity meta + attendance roster, which covers the pitch-side "who's coming, what's the plan" use case.

## What landed

### `ActivityBriefPdfExporter` (`exporter_key = activity_brief_pdf`, use case 8)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/activity_brief_pdf?format=pdf&activity_id=42`
### Methodology-link wizard step (LinkStep) — fixes + context-driven label + translations

- `activity_id` (REQUIRED) — tenant-scoped via `WHERE club_id = %d` on `tt_activities`.

**Cap**: `tt_view_activities` — same gate as the on-screen activities admin.

**Layout**: A4 portrait, 16mm margins. Sections:

- Header — activity title (large), date / team / location / type meta table.
- Notes section — pre-formatted `white-space: pre-wrap` block (only rendered when notes exist).
- Attendance roster table — Jersey / Player name / Primary position / Status / Notes columns; one row per `tt_attendance` row joined to `tt_players`.
- Generated-date footer.

**Layout choices**: inline CSS in the document `<head>` (DomPDF can't follow external stylesheets reliably); DejaVu Sans default; alternating-row striping on the roster for readability at A4.

**Module wiring**: registered in `ExportModule::boot()` alongside the existing exporters. Foundation now at 12 of 15 use cases live.

## What's NOT in this PR (deferred field-diagrams work)

Spec calls for "A4 with field diagrams" — this v1 ships the brief shape without diagrams. Field-diagram support requires:

- A drills sub-entity (`tt_session_drills` with `title`, `duration`, `positions`, `notes`).
- A position-grid widget shareable with the team-blueprint editor.
- SVG output (DomPDF doesn't render `<canvas>` or execute JS).

The deferred follow-up is tracked in `ActivityBriefPdfExporter`'s class docblock and surfaces at `?tt_view=activities`'s manage view when a drill editor lands. Until then, the pitch-side use case is well-served by the meta + roster brief.

## Notes

- 5 new operator-facing strings: `Notes`, `Attendance roster`, `No attendance recorded.`, `Jersey`, `Position` — all translatable via `__()`.
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.14 against any parallel-agent ship that took a v3.110.x slot during build.