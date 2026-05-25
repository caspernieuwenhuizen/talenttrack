# TalentTrack v4.3.3 — VCT module ship 4: age profiles + session templates + phase profiles seeded (closes #909, partial epic #905)

## Context

Ship 4 of the VCT epic. Three foundation ships have already landed: VCT-1 (v4.3.0) schema, VCT-2 (v4.3.1) caps + matrix, VCT-3 (v4.3.2) lookup vocabularies. This ship seeds the three reference datasets the rules engine (VCT-5) consumes for slot composition + age-safe load decisions.

No UI, no REST, no engine yet. VCT-5 (#910) is the next ship and reads these tables.

## What changed

### Migration 0125 — age profiles + session templates

Five `tt_vct_age_profiles` rows (one per age U10-U14) + 20 `tt_vct_session_templates` rows (one per valid `(age × md_context)` combination).

#### Age-profile defaults (Appendix A as revised by domain review)

| age | session_minutes_max | intensity_band_max | md_logic_enabled | min_recovery_hours_between_high | growth_spurt_load_reduction_pct | weekly_load_envelope |
|---|---|---|---|---|---|---|
| U10 | 70 | 3 | 0 | 72 | 25 | 420 |
| U11 | 85 | 4 | 0 | 72 | 25 | 680 |
| U12 | 85 | 5 | 1 (simplified) | 72 | 25 | 1275 |
| U13 | 90 | 7 | 1 (full) | 48 | 30 | 1890 |
| U14 | 90 | 7 | 1 (full) | 48 | 30 | 1890 |

`match_load_multiplier_per_minute = 7.0` across the board — marked in code as needing domain-expert sign-off, exposed via the Phase 2 age-profile editor for per-club override.

`weekly_load_envelope` derived as `session_minutes_max × intensity_band_max × expected_sessions_per_week` with cadence 2/2/3/3/3.

#### Session-template MD coverage

- **U10 + U11**: `NONE` only (no MD logic — pre-puberty supercompensation limited per Appendix A).
- **U12**: `NONE` + `MD-3` + `MD-1` + `MD+1` (simplified ladder).
- **U13 + U14**: full eight-state ladder — `NONE` + `MD-4` + `MD-3` + `MD-2` + `MD-1` + `MD+1` + `MD+2`.

Total: 1 + 1 + 4 + 7 + 7 = 20 templates per club. MD itself (the match day) is omitted — the match is the training stimulus on the day, no separate VCT session.

#### Slot pattern (per spec § Exercise Taxonomy)

Five slots per template: `warmup → technical → sided_game → conditioning → cool_down`. On MD-2 / MD-1 for U13-U14, `conditioning` is replaced by `finishing` (sharpening bias as match approaches).

Each slot carries:

- `category` — matches `tt_lookups.vct_exercise_category` value
- `intensity_band_min` / `intensity_band_max` — engine selects exercises within this range; respects the age's `intensity_band_max` ceiling so the rules engine can't propose an over-age exercise
- `duration_target` / `duration_tolerance` — minutes; tolerance is the engine's swap-budget when rebalancing
- `theme_filter` — `true` for technical / sided_game / payload (coach's tactical theme applies); `false` for warm-up / cool-down (theme-agnostic per spec § 4)

Slot duration allocation: ~14% warm-up, ~22% technical, ~28% sided_game, ~25% payload, ~12% cool-down. Adjusted per-template so the slot durations sum exactly to the template's total. Variable-MD templates use a reduced total (60–85 min depending on MD context — e.g. MD+1 drops to 60 min to model the recovery day).

### Migration 0126 — phase-profile reference rows

Two reference rows seeded into `tt_vct_macro_blocks` at the `(club_id = 1, team_id = 0, season_id = 0)` sentinel — `season_id = 0` is the "this is a template, not a real block" marker; the engine's per-season lookup never accidentally matches these rows.

Profile 1 (sequence 1, 4-week):
```
[introductie 0.85, opbouw 1.00, opbouw 1.00, deload 0.70]
```

Profile 2 (sequence 2, 6-week default):
```
[introductie 0.85, opbouw 0.95, opbouw 1.00, piek 1.00, piek 1.00, deload 0.70]
```

`start_date` / `end_date` are placeholders in the year 2000 (NOT NULL columns; meaningless for reference rows — the rows are recognised as templates by the sentinel, not by dates). HoDs clone these via "Use as template" in the Phase 2 configuration tile, which `INSERT`s a copy at the real `season_id`.

## Out of scope

- Exercise catalogue — VCT-8 (Phase 2, pilot-coach review).
- Rules engine + repositories — VCT-5 (#910).
- REST + UI — VCT-6 (#911).
- Workload aggregation task — VCT-7 (#912).
- Phase 2 configuration tiles for editing these values — VCT-12 (later ship).

## Provisional flag

Spec defaults ship as **provisional pending HoD/coach sign-off** — an epic-level approval gate per #905. When sign-off lands and a value changes, a follow-up migration will `UPDATE … WHERE` the row isn't operator-edited (existence-check + content-check). Operator edits via the Phase 2 editor are preserved.

## Idempotency

Both migrations use existence-check on natural keys before insert:

- 0125 age profiles: `(club_id, age_group)`.
- 0125 session templates: `(club_id, age_group, md_context)`.
- 0126 phase profiles: `(club_id, team_id, season_id, sequence)`.

Re-running is a no-op. Operator-edited rows survive.

## Validation

- After 0125: `SELECT age_group, session_minutes_max, intensity_band_max FROM wp_tt_vct_age_profiles WHERE club_id = 1 ORDER BY age_group` returns 5 rows matching the table above.
- After 0125: `SELECT age_group, md_context FROM wp_tt_vct_session_templates WHERE club_id = 1 ORDER BY age_group, md_context` returns 20 rows: U10[NONE], U11[NONE], U12[NONE,MD-3,MD-1,MD+1], U13[NONE,MD-4,MD-3,MD-2,MD-1,MD+1,MD+2], U14[same as U13].
- After 0126: `SELECT sequence, label FROM wp_tt_vct_macro_blocks WHERE club_id = 1 AND season_id = 0 AND team_id = 0` returns the two reference rows.
- Re-running either migration: counts unchanged (idempotent).

## Why this is `patch`, not `minor`

Seed data within the 4.3 minor that VCT-1 opened. No new feature epic; foundation data for VCT-5 onwards. Patch bump per `DEVOPS.md`.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.2` → `4.3.3`.
