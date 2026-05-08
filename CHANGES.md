# TalentTrack v3.110.16 — Ninth Export use case: demo-data round-trip XLSX (#0063 use case 15)

Per spec: "Round-tripped demo data so #0020 / #0059 can re-import it. Exists informally; Export formalizes it." Walks every sheet in `SheetSchemas::all()` (the same schema `TemplateBuilder::streamDownload()` uses for the import template) and dumps the matching live rows into a multi-sheet XLSX whose layout is identical to the import template — so an operator can download a snapshot from one club, hand it to a fresh install, and re-import without column-mapping work.

## What landed

### `DemoDataXlsxExporter` (`exporter_key = demo_data_xlsx`, use case 15)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/demo_data_xlsx?format=xlsx`

**Filters**: none at v1 — the export is "everything in the current club."

**Cap**: `tt_edit_settings` — same gate as the v3.109.2 seed-review surface. The export carries every player's PII.

### Sheets

Walks `SheetSchemas::all()` and emits one tab per schema entry, in schema-declared order:

- **Master tier**: `Teams`, `People`, `Players`, `Trial_Cases`.
- **Transactional tier**: `Activities` (post-#0027 rename — schema key still `sessions`), `Session_Attendance`, `Evaluations`, `Evaluation_Ratings`, `Goals`, `Player_Journey`.
- **Config tier**: `Eval_Categories`, `Category_Weights`, `Generation_Settings` (intentionally empty — the import side reads it as a generator hint, not a tracked entity), `_Lookups`.

### Round-trip design (per user-direction shaping 2026-05-08)

- **Q1 — `auto_key` strategy**: numeric `id`. Deterministic, collision-free, idempotent. "John_Doe" string slugs would collide on real data; numeric ids round-trip cleanly through the import → export → re-import path. The importer's existing numeric-id lookup handles the resolution.
- **Q2 — filter scope**: every live row in the current club via `WHERE club_id = ?` (or via a join through `tt_players.club_id` for tables that scope through the player).
- **Q3 — cap**: `tt_edit_settings` (admin only) — full PII surface; same gate as the seed-review export from v3.109.2.

### FK columns

The schema's `xxx_key` columns (`team_key`, `player_key`, `session_key`, `head_coach_key`, etc.) carry the FK target's numeric `id` directly. Sheets the importer doesn't yet consume (`Eval_Categories` / `Category_Weights` / `_Lookups`) are still emitted so a clean re-import recreates the same configuration alongside the data.

### Module wiring

Registered in `ExportModule::boot()`. Foundation now at 14 of 15 use cases live.

## What's NOT in this PR

- **Operator-facing import button** that takes the exported file as input — the existing demo-data importer admin page (`?page=tt-demo-data`) consumes the same file shape; that's the operator surface.
- **Filtered-by-team export** — no per-team filter at v1; operators who want a subset filter post-export.
- **Generation_Settings round-trip** — left empty by design; `Generation_Settings` is a generator hint (target counts per entity), not a tracked entity.
- **Per-row demo-mode flag** — most tables don't carry a `is_demo` column; "everything in the current club" is the v1 scope.

## Notes

- 1 new operator-facing string (the exporter label "Demo-data round-trip (XLSX)"). All sheet names + column headers reuse the `SheetSchemas` strings already in `nl_NL.po`.
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.16 against any parallel-agent ship that took a v3.110.x slot during build.
