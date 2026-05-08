# TalentTrack v3.110.12 — Sixth Export use case: evaluations multi-sheet XLSX (#0063 use case 6)

Per the user-direction shaping (2026-05-08): tabs partition by `(season × eval_type)` with season as the primary axis ("Season determines"). One row per evaluation, category-average columns rolled up from `tt_eval_ratings` sub-rows.

## What landed

### `EvaluationsXlsxExporter` (`exporter_key = evaluations_xlsx`, use case 6)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/evaluations_xlsx?format=xlsx`

**Filters**:

- `team_id` (optional) — restrict to one team via `tt_players.team_id`.
- `date_from` / `date_to` (optional ISO dates) — auto-swap reversed ranges per the same convention as the v3.109.0 attendance-register CSV.

**Cap**: `tt_view_evaluations`.

**Tab partitioning**: one tab per `(season, eval_type)` combination. Tab name format: `<season name> — <eval type name>`, truncated to Excel's 31-char limit by `XlsxRenderer::cleanSheetName()`.

- **Season** comes from `tt_seasons` — match by `eval_date BETWEEN season.start_date AND season.end_date`. Evaluations whose date doesn't fall in any seeded season window go to a fallback `_Unscoped` partition. The query orders seasons by `start_date ASC` so the resulting tab order is chronological.
- **Eval type** comes from `tt_lookups` where `lookup_type = 'eval_type'` (Match / Training / Tournament / etc.) — the same lookup the existing eval admin form populates. NULL `eval_type_id` lands in an `_AnyType` partition. The eval-type taxonomy is distinct from the Technical/Tactical/Physical/Mental main categories, which drive the row columns instead.

**Row shape**: one row per evaluation. Columns: Date / Player / Coach / Opponent / Competition / Result / Minutes played + one column per main `tt_eval_categories` parent (Technical / Tactical / Physical / Mental — averaged across each evaluation's sub-category ratings via `tt_eval_ratings`).

The per-main-category averages are computed in PHP after a single batched `WHERE evaluation_id IN (...)` query against `tt_eval_ratings`, so the multi-tab build is one SELECT against `tt_evaluations` + one SELECT against `tt_eval_ratings` regardless of evaluation count.

**Tenancy**: `tt_evaluations` doesn't carry `club_id` directly today — the exporter scopes via the joined `tt_players.club_id` to stay tenant-safe.

**Module wiring**: registered in `ExportModule::boot()` alongside the existing exporters. Foundation now at 11 of 15 use cases live.

## What's NOT in this PR

- **Per-evaluation sub-category detail tabs** — the per-row averages cover the typical "merge into our analytics" pilot use case; sub-row detail lands if a customer asks.
- **Per-club column customization** — the column set is the v1 default; per-club picks are a v2 if needed.
- **Eval-type filter at the route** — the partitioning already discriminates by eval type; clubs that want a single type, the date + team filters are sufficient at the route.

## Notes

- Zero new operator-facing strings — column headers reuse existing `__()` strings.
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.9 → v3.110.12 mid-rebase against parallel-agent ships v3.110.5–v3.110.11.
