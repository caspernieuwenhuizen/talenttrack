# TalentTrack v3.110.214 — Match preparation surface for head coaches (closes #838)

## Why

Pilot 2026-05-21: head coach asked for a match-preparation form that mirrors the spreadsheet they currently use to set the starting XI, plan substitutes between halves, write the game goals, and mark which players have specific goals + an appointed video analyst. The spec was iterated through multiple rounds on the same day; this ship implements the agreed v1 (desktop-only, screenshot-for-distribution).

## What's in

### New module: `src/Modules/MatchPrep/`

- `MatchPrepModule` — registers the REST controller, the wizard with `WizardRegistry`, and the PDF exporter with `ExporterRegistry`.
- `Repositories/MatchPrepRepository` — one class spanning the four prep tables.
- `Rest/MatchPrepRestController` — `PUT /talenttrack/v1/match-prep/<activity_id>` accepting the full form payload in one shot; cap `tt_edit_activities`.
- `Frontend/FrontendMatchPrepView` — main editing surface at `?tt_view=match-prep&activity_id=N`. Redirects to the wizard if no prep row exists.
- `Wizards/MatchPrepWizard` + `Wizards/AvailabilityStep` — one-step wizard that collects Present/Absent + reason per roster player. Filters `Late` via `tt_lookups.meta.hide_from_prep`.
- `Export/MatchPrepPdfExporter` — landscape A4 print sheet.

### Migration 0118

Four new tables:

- `tt_match_prep` (1:1 with the match activity) — formation, half length, the five goals fields, audit timestamps + `created_by`. Carries `uuid CHAR(36) UNIQUE` per SaaS-ready guidance.
- `tt_match_prep_availability` (`match_prep_id × player_id`) — Present/Absent/Excused/Injured snapshot + free-text reason.
- `tt_match_prep_lineup` (`match_prep_id × half × slot_number`) — per-half pitch assignment with both `(prep, half, slot)` and `(prep, half, player)` unique keys.
- `tt_match_prep_player_goals` (`match_prep_id × player_id`) — attention text + `is_specific_goal` + `analyst_appointed`.

All four carry `club_id BIGINT UNSIGNED DEFAULT 1`. Migration uses `CREATE TABLE IF NOT EXISTS`.

The migration also sets `tt_lookups.meta.hide_from_prep = true` on the canonical `Late` row of `attendance_status` so the AvailabilityStep chip set hides it.

### Activity detail integration

A new page-header action **"Plan match prep"** appears on the activity detail page when `activity_type_key` is `match` or `game` AND the user has `tt_edit_activities`. Targets `?tt_view=match-prep&activity_id=N`; the view redirects to the wizard if no prep row exists yet.

### REST contract

`PUT /talenttrack/v1/match-prep/<activity_id>` — body: `formation_template_id`, `half_length_minutes`, the five `goals_*` columns, a `lineup` object keyed by half → `{ slot: player_id }`, a `player_goals` object keyed by player_id → `{ attention_text, is_specific_goal, analyst_appointed }`, plus an optional `availability` object for the form's "Manage availability" round-trip.

All sub-sets are full replacements (delete + re-insert). Partial saves are allowed — only the wizard's AvailabilityStep enforces a minimum of 11 Present players.

### Frontend behaviour

Vanilla JS. The form recomputes minutes on every slot change, enforces slot uniqueness per half by clearing the previous occupant when a clash happens, offers a "→ Copy 1e to 2e" button that duplicates the first-half slot map, and persists via one PUT.

## How to test

- [ ] Apply migrations — confirm `0118_match_prep` in `tt_migrations`; four new tables exist.
- [ ] `Late` row of `attendance_status` carries `meta.hide_from_prep = true`.
- [ ] On a match-type activity, "Plan match prep" appears in the page-header.
- [ ] Wizard defaults all roster to Present; mark a few Absent with a reason; blocked from advancing if fewer than 11 Present.
- [ ] Form: pick per-half slots, conflicts clear the previous occupant, minutes track live, Copy 1e→2e works.
- [ ] Per-player attention text + Specific-goal flag + Analyst-appointed flag persist on save and round-trip on reload.
- [ ] PDF link opens a landscape A4 sheet with the per-half slot list, bench, goals, unavailable players, and per-player attention.

## Player-centric framing

Helps answer **"Who's available, who's starting, what are we focused on for this match, and who's watching the video back?"** — the head coach gets a single screen instead of a side spreadsheet.

## Out of scope (v1 — per pilot direction)

- Pitch / formation diagram rendering in the PDF (v1 uses a list layout).
- Mobile UX — desktop-only v1.
- Mid-half substitutions.
- Player-facing surfaces.
- In-app analyst-feedback capture (the flag persists the appointment).
- Match-prep templates.
- History / versioning of prep edits.
- `MatchDayTeamSheetPdfExporter` reads from prep data (deferred; the existing exporter still reads from `tt_attendance.lineup_role`).

## Follow-ups worth filing

- Pitch-diagram SVG in the PDF.
- MatchDayTeamSheetPdfExporter prep integration.
- Bottom-sheet slot-picker UX (mirrors the team-blueprints picker pattern).
- Activity edit form gains a `half_length_minutes` column on match-type activities (currently the prep-header field overrides per-prep, defaulting to 35).
