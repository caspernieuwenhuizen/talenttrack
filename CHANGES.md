# TalentTrack v3.110.17 — Tenth Export use case: match-day team sheet PDF (#0063 use case 4) — closes #0063

Last of the 15 #0063 use cases. Per user-direction shaping (2026-05-08): adds the match-specific fields on `tt_activities` (opponent / home_away / kickoff_time / formation) plus per-row lineup-role + position-played fields on `tt_attendance` via migration 0079, then ships the team-sheet exporter on top.

**Closes #0063**: 15 of 15 use cases live. Foundation epic complete.

## What landed

### Migration `0079_match_day_fields`

Adds six columns, additive-only, idempotent (SHOW COLUMNS guards):

| Table | Column | Type | Purpose |
| --- | --- | --- | --- |
| `tt_activities` | `opponent` | `VARCHAR(255) DEFAULT NULL` | Opposing team name for match-type activities |
| `tt_activities` | `home_away` | `VARCHAR(10) DEFAULT NULL` | `home` / `away` |
| `tt_activities` | `kickoff_time` | `TIME DEFAULT NULL` | Match kickoff time |
| `tt_activities` | `formation` | `VARCHAR(20) DEFAULT NULL` | Tactical formation, e.g. `4-3-3` |
| `tt_attendance` | `lineup_role` | `VARCHAR(10) DEFAULT NULL` | `start` / `bench` for the team-sheet split |
| `tt_attendance` | `position_played` | `VARCHAR(20) DEFAULT NULL` | Per-match override for the player's position |

No backfill — existing match activities (if any) read NULL until edited for the first time.

### `MatchDayTeamSheetPdfExporter` (`exporter_key = match_day_team_sheet`, use case 4)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/match_day_team_sheet?format=pdf&activity_id=42`

**Filters**: `activity_id` (REQUIRED) — tenant-scoped via `WHERE club_id = %d`.

**Cap**: `tt_view_activities`.

**Activity-type gate**: refuses to render for non-match activities (`activity_type_key !== 'match'`) — the team-sheet artifact only makes sense for matches.

**Layout**: A4 portrait, 14mm margins. Header with title + opponent (`vs <Name> (home/away)`), meta table (Team / Date / Kickoff / Location / Formation), per-section lineup tables (Starting XI / Bench), and signature lines for coach + referee.

**Lineup partition**: `tt_attendance.lineup_role = 'start'` → Starting XI; `'bench'` → Bench; NULL → falls through to a single "Squad" section if neither Start nor Bench has been populated yet (so the team sheet is useful even before the operator splits the squad).

**Position resolution**: `tt_attendance.position_played` (per-match override) → falls back to `tt_players.preferred_positions[0]` → empty cell.

**ORDER BY**: lineup_role (`start` < `bench` < other) → jersey number (NULL last) → last name. Matches the eyeball-readable ordering on a printed sheet.

**Module wiring**: registered in `ExportModule::boot()` as the 15th and final use-case exporter — **#0063 foundation now at 15 of 15 use cases live**.

## What's NOT in this PR (deferred to follow-ups)

- **Form-UI to populate the new columns** — operators populate via direct DB write or REST PATCH at v1; an "If this activity is a match" section on the activities form is the natural follow-up. Tracked in the exporter's class docblock.
- **Substitution tracking** — the team sheet is a snapshot at kickoff; mid-match substitutions don't update `lineup_role`. Sub-tracking is a v2 with its own data model.
- **Pitch diagram with formation** — the formation column carries the string ("4-3-3") but there's no rendered pitch. Lands with the same SVG follow-up that activates field diagrams in #0063 use case 8.
- **Brand-kit letterhead** — `tt_pdf_render_html` filter exists; consumers can hook today.

## Notes

- 11 new operator-facing strings (Match-day team sheet label / vs %s (%s) format / home / away / Team / Kickoff / Formation / Starting XI / Bench / Squad / "Match-only" stub). All translatable via `__()`.
- One new migration: `0079_match_day_fields`.
- No composer dependency changes.
- **Closes #0063 (Export module)**: 15 of 15 use cases live. Foundation epic complete.
