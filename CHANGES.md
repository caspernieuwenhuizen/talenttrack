# TalentTrack v4.3.13 — VCT configuration tile (HoD-only) — completes the VCT MVP UI (closes #952)

## Context

Final UI ship of the VCT MVP slate. With this in place:

- **Coach flow**: wizard (v4.3.10) → detail view + publish (v4.3.11).
- **HoD curation**: library editor (v4.3.12) → **configuration tile (this ship)**.

The configuration tile gives HoDs the operator-tuning surface the spec calls for (macro-blocks calendar, age profiles, team schedules). Without it, the engine's reference data was set in stone at seed time + only editable via SQL.

## What changed

### Routable view — `?tt_view=vct-config`

`src/Modules/Vct/Frontend/FrontendVctConfigView.php` — single view with `?tab=` switcher. Three tabs:

#### Tab 1: Macro-blocks (`?tab=blocks`)

- Season + team picker (`team_id=0` = club-wide default; non-zero = per-team override).
- Reference phase profiles (the two seeded in migration 0126) listed for HoD reference + paste-as-template.
- Current block list table for the picked (team, season).
- Bulk-replace via JSON textarea in a `<details>` summary (v1 power-user form; richer per-week multiplier UI is Phase 2 polish).

#### Tab 2: Age profiles (`?tab=age-profiles`)

- Five collapsible cards (U10-U14).
- Inline PATCH form per row: `session_minutes_max`, `intensity_band_max` (1-10), MD logic enabled checkbox, `min_recovery_hours_between_high`, `growth_spurt_load_reduction_pct`, `weekly_load_envelope`, `match_load_multiplier_per_minute` (decimal).
- Numeric inputs declare `inputmode` per CLAUDE.md §2.

#### Tab 3: Team schedules (`?tab=schedules`)

- Season picker.
- One collapsible per team.
- Weekday bitmask via 7 checkboxes (Mon-Sun, bit 0 = Monday).
- `default_start_time` (`type="time"`) + `default_duration_minutes` (`type="number" inputmode="numeric"`).

### `PUT /vct/macro-blocks` — wired

`VctMacroBlocksRestController` was read-only in v4.3.6. This ship adds the PUT endpoint with the validation suite the spec asks for, mirroring `PdpBlocksRestController::validate()`:

- Contiguous sequences 1..N.
- Valid YYYY-MM-DD on every block.
- `end_date >= start_date`.
- No overlaps between blocks.

Returns the spec's `{error: {code: 'invalid_blocks', ...}}` 400 envelope on failure.

### `VctMacroBlocksRepository::replaceForSeason()`

Wipes the existing `(team_id, season_id)` rows + inserts the new set. UUID per row. Preserves the `season_id = 0` reference templates (they're not in the (team_id, season_id) tuple being replaced).

### Reused existing REST

- Age-profile tab POST → `PATCH /vct/age-profiles/{id}` (wired in v4.3.6).
- Team-schedule tab POST → `PUT /vct/teams/{id}/schedule` (wired in v4.3.6).

The view's POST handler routes inline through the repositories directly (same path as the REST handler logic); no HTTP round-trip needed for the form submit.

### Permission

Single cap guard at view entry: `tt_vct_admin_library` (HoD/admin only). Save+Cancel exempt per CLAUDE.md §6 (a) — settings sub-form with multiple independent forms on one page.

### Dispatcher wiring

New `case 'vct-config'` in `DashboardShortcode.php` alongside the existing `vct-session` (v4.3.11) and `vct-library` (v4.3.12) cases.

## VCT MVP UI — complete

| Flow | Where | Ship |
|---|---|---|
| Coach generates a training | Wizard at `?tt_view=wizard&slug=new-vct-session` | v4.3.10 |
| Coach reviews + publishes | `?tt_view=vct-session&id=N` (mobile + A4 print) | v4.3.11 |
| HoD curates the exercise library | `?tt_view=vct-library` | v4.3.12 |
| HoD tunes engine reference data | `?tt_view=vct-config` | **v4.3.13** |

## Out of scope

- **Per-week multiplier UI** for macro-blocks — v1 ships a JSON textarea; richer per-block per-week multiplier editor is Phase 2 polish.
- **SVG year timeline** for the season's macro-blocks — Phase 2 polish (the spec mentions it under § UI surfaces; deferred without prejudice).
- **Multi-locale label overrides** on age profiles — already handled by the Lookups admin for the per-locale `tt_vct_age_*` labels.

## Validation

- HoD visits `?tt_view=vct-config` → tab bar shows three tabs, default tab is `blocks`.
- Macro-blocks tab: pick a season, paste a JSON array of blocks → server validates → rows persisted.
- Bad JSON → "Save failed: blocks_json is not valid JSON." inline notice.
- Overlapping blocks → 400 + descriptive error from the server-side validator.
- Age profiles tab: edit a row's `intensity_band_max`, save → row updated; reload shows the new value.
- Team schedules tab: pick a season, tick Tue + Thu on a team, set 18:30 / 75 min, save → upserts; reload shows the chips ticked.
- Coach (no `tt_vct_admin_library`) → "Not authorised" notice on view entry.

## Why this is `patch`, not `minor`

UI + REST completion within the 4.3 minor. No schema change, no new caps, no new contract. Patch bump per `DEVOPS.md`.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.12` → `4.3.13`.
