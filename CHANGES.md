# TalentTrack v3.110.6 — Third binary export use case: scouting report PDF (#0063 use case 14)

Third PDF use case in the family started by use case 1 (player evaluation, v3.110.4) and use case 2 (PDP, v3.110.5). All three reuse the existing `Modules\Reports\PlayerReportRenderer` + the standard PDF wrap pipeline; this one builds a SCOUT-audience `ReportConfig` so the rendered output enforces the scout privacy floor, the `formal` tone, and the spec's `[ profile, ratings ]`-only section list.

## What landed

### `ScoutingReportPdfExporter` (`exporter_key = scouting_report_pdf`, use case 14)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/scouting_report_pdf?format=pdf&player_id=42`

**Filters**:

- `player_id` (REQUIRED) — tenant-scoped via `QueryHelpers::get_player()`.
- `date_from` / `date_to` (optional ISO dates) — overrides the SCOUT-audience default `all_time` scope when the operator wants a tighter window (e.g. "this season only").
- `eval_type_id` (optional positive int) — filter to one evaluation type.

**Cap**: `tt_generate_scout_report` — same gate as the existing `?tt_view=scout-access` view that drives the email-the-link flow.

**Audience config**: builds a `ReportConfig` with `audience = SCOUT`, `tone_variant = formal`, sections `[ profile, ratings ]`, and `PrivacySettings(false, false, true, false)` — no contact details, no full DOB, photo opt-in stays at the renderer-config default, no coach notes. This is the same `AudienceDefaults::defaultsFor( SCOUT )` the existing `ScoutDelivery::emailLink()` flow uses, so the PDF and the emailed-link artifact stay in lockstep.

**Brand-kit letterhead deferred**: the spec calls for "on club letterhead" but brand-kit template inheritance is the deferred-from-v3.110.0 follow-up. Consumers that need letterhead today can hook the `tt_pdf_render_html` filter from v3.110.0 to prepend their letterhead. Automatic inheritance lands when the PDF renderer earns it across multiple use cases.

**Chart-script strip**: same pattern as v3.110.4's player-evaluation PDF — DomPDF doesn't execute JavaScript, so the renderer's Chart.js `<script>` block becomes dead bytes that should not ship in the PDF. Stripped via the same regex.

**Module wiring**: registered in `ExportModule::boot()` alongside the v3.105.0 / v3.109.0 / v3.110.0 / v3.110.4 / v3.110.5 entries. Foundation now at 8 of 15 use cases live.

## What's NOT in this PR

- **The existing scout email-link flow** stays in place — this exporter is additive. Operators using `?tt_view=scout-access` to email a one-time link continue to work; the exporter route opens the PDF up to other surfaces (a "Save as PDF" affordance in scout-history, a future "Generate cohort scouting pack" batch action, external-system polls).
- **Brand-kit letterhead** — `tt_pdf_render_html` filter exists today; consumers can hook.
- **Per-club layout variants** — the layout is the existing renderer's SCOUT audience; per-club overrides land if a customer asks.
- **Async dispatch** — synchronous-only via the standard REST stream-and-exit path.
- **The 7 remaining deferred Export use cases** (4, 6, 8, 9, 10, 13, 15) — each lands behind its first consumer.

## Notes

- Zero new operator-facing strings — exporter label uses an existing `__()` pattern; the report body's strings are all already in `PlayerReportRenderer`'s translation set.
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.6 against any parallel-agent ship that took a v3.110.x slot during build.
