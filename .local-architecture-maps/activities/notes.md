# Activities map — notes

## Capture method (2026-06-10, v4.20.79)

Hand-curated from a single explorer-agent pass over the codebase asking for
15 categories of surfaces (tables, migrations, repositories, services, REST
controllers, view-reads, view-writes, wizards, caps, events, cron, exporters,
lookups, CLI, hooks/filters).

Polish flags were added based on targeted greps after the structural pass:

- `inline-sql-in-view` — counted `$wpdb->(get_results|get_row|query|prepare)`
  call sites per view file. Flag set on any view file with ≥1.
- `cap-raw-current_user_can` — grep `current_user_can` against the same files;
  flag set if any site does NOT route through `AuthorizationService::userCanOrMatrix`.
- `missing-uuid` — set on root-entity tables that don't appear in any migration
  adding a `uuid CHAR(36)` column. **NB**: migration 0038 (`tenancy_scaffold`)
  already added `club_id` + `uuid` to the five root entities including
  `tt_activities`, so the activities map carries no `missing-uuid` flag. Keep
  the overlay vocabulary for future modules that may legitimately lack it.

## Decisions made during capture

- **Migrations**: only milestone migrations included (0001, 0027, 0073, 0100,
  0144). Including all 15 activity-touching migrations turned the graph into
  an unreadable cluster of orange nodes around `tt_activities`. The milestone
  picks tell the story (initial schema → rename → workflow → analytics → time
  fields); the rest are deltas best read in `git log database/migrations/`.
- **Exporters**: all 8 included as separate nodes even though they're shape-
  identical, because the "fan-out from `tt_activities` to 8 export consumers"
  visual makes a real point: any schema change on activities has 8 downstream
  rendering surfaces to verify.
- **Analytics-side reads** (`UpcomingActivityRepository`,
  `AttendancePlayerReportView`, etc.) are included as cross-module consumers
  so the polish work doesn't miss cross-module impact. They're tagged with the
  `analytics` type, separate from `view-read` so they can be filtered out
  when scoping pure-module work.

## Polish flags not yet captured

Things to add in the next pass if the time investment is worth it:

- `silent-fail` — needs a grep for `@$wpdb` and empty `catch`/short-circuit
  patterns; not yet done. Likely 2-5 sites across the module.
- `missing-hydration` — needs a grep for fields like `activity_type_key` in
  output without going through `LookupTranslator`; speculative without
  per-file review.
- `i18n-risk` — could spot-check by extracting strings from the main 3 surfaces
  and diffing against `languages/talenttrack-nl_NL.po` for missing `msgstr`.
- `no-rest-endpoint` — already implicit (all main writes have REST routes);
  worth marking specific PHP-only flows when next module surfaces them.

## Open questions

- **Auto-extraction story** — should the next module be hand-curated again or
  is it worth building a small PHP CLI that emits a `manifest.json` per
  module? Decision: hand-curate the second one (players) too. Three modules
  is enough data to know what an extractor would actually need.
- **Polish-flag escalation** — should `inline-sql-in-view` auto-file as a
  `ready-for-dev` issue with the repo-port recipe? Probably not yet —
  human triage is still cheap at this volume.

## Roadmap

- Next module: **players** (largest surface, biggest readiness payoff).
- Then: **evaluations**, **pdp**, **goals**.
- Per CLAUDE.md §4 SaaS-readiness focus, the map's most valuable column is
  probably `missing-uuid` + `no-rest-endpoint` once all 5 modules are mapped
  — that's the portability backlog.
