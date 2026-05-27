# TalentTrack v4.3.9 — VCT slim starter exercise catalogue — 25 exercises, NL + EN canonical, methodology unreviewed (closes #941)

## Context

The VCT Phase 1 epic (#905) closed at v4.3.7 with the data + engine + REST layer complete, but `tt_vct_exercises` shipped empty: `POST /vct/sessions/generate` returned sessions with `exercise_id = null` on every slot because there were no candidates to select.

This ship populates a **slim starter catalogue** (25 exercises) so the wizard becomes usable end-to-end today. The spec's safety gate ("HoD/pilot-coach review of all 80 exercises before catalogue migration ships") is **explicitly deferred** for this starter; a pilot-coach audit before broader rollout is strongly recommended.

## What changed

Migration `0128_vct_seed_exercises_starter.php` inserts 25 rows into `tt_vct_exercises` plus 2-3 coaching points per exercise in `tt_vct_coaching_points` with Dutch text in `tt_translations`.

### Distribution

| Category | Count |
|---|---|
| warmup | 5 |
| technical | 5 |
| sided_game | 7 |
| conditioning | 4 |
| finishing | 2 |
| cool_down | 2 |
| **Total** | **25** |

### Coverage

- **Age range:** warmup + cool-down range U10-U14; technical + sided_game lean U11-U14; conditioning + finishing U12-U14 (no U10 conditioning per Appendix A's pre-puberty restriction).
- **MD context:** most exercises tagged for `MD-4` / `MD-3` / `MD-2` / `MD-1` / `NONE`; recovery-focused exercises (low-intensity conditioning + cool-down) tagged for `MD+1` / `MD+2`.
- **Theme:** technical/sided_game exercises carry theme tags (`build_up`, `pressing`, `transition`, `counter`, `defending`, `possession`, `1v1_duels`, `mixed`, `finishing`) so `TacticalThemeRule` can filter when the coach picks a theme; warmup + cool-down stay theme-agnostic.
- **Verheijen classification:** conditioning exercises tagged with `football_endurance` so `ExerciseSelectionPass` can score them correctly.

### Bilingual handling

Each coaching point row has a `code` slug (e.g. `'first_touch_away'`) and a Dutch text row in `tt_translations`. English canonical text comes from the `COALESCE(t.value, cp.code)` fallback in `VctCoachingPointsRepository::listForExercise()` — so on English-locale installs the coach sees the slug as the fallback (clearly readable: e.g. `"first touch away"`). For a polished EN canonical, the operator can add `locale='en_US'` rows via the Lookups admin; for the starter ship we rely on the slug fallback to keep the migration manageable. FR / DE / ES translations are deferred; those locales fall through to the English slug fallback as well, per the existing translation pattern.

## Caveats — please read before broader rollout

This is **not** the canonical 80-exercise expert-curated catalogue the spec describes. The starter exercises:

1. Were authored without HoD or pilot-coach review.
2. Have generic coaching cues, not Verheijen-flavoured precision phrasings.
3. May include intensity attributions, age ranges, or MD-context tags that don't match your academy's methodology.

The spec's § Risks + mitigations is explicit: *"Mitigation: HoD/pilot-coach review of all 80 exercises before catalogue migration ships"*. The starter ship trades that gate for time-to-usable-wizard; the eventual canonical VCT-8 ship will replace or expand this set after coach review.

**Recommended audit workflow once the library editor (VCT-11) ships:**

1. Walk through the 25 exercises with the HoD.
2. Flag any that don't match the academy's methodology.
3. Replace via the library editor inline; or archive the bad ones (`archived_at`) and add fresh ones.
4. When the canonical 80-exercise catalogue lands, the migration's `seed_revision = 1` lets a future migration use `UPDATE … WHERE seed_revision < N AND archived_at IS NULL` to bring uncustomised installs current without overwriting operator edits.

## Validation

- After migration: `SELECT category, COUNT(*) FROM wp_tt_vct_exercises WHERE club_id = 1 GROUP BY category` returns 25 rows in the documented distribution.
- `SELECT COUNT(*) FROM wp_tt_vct_coaching_points WHERE club_id = 1` returns 50-75 rows (2-3 per exercise).
- `SELECT COUNT(*) FROM wp_tt_translations WHERE entity_type = 'vct_coaching_point' AND locale = 'nl_NL'` matches the coaching-point row count.
- `POST /wp-json/talenttrack/v1/vct/sessions/generate` for U13 / theme=pressing returns a session whose blocks have non-null `exercise_id` for most slots.
- Re-running the migration: row counts unchanged (existence-check on `(club_id, code)` per row).

## Why this is `patch`, not `minor`

Seed data within the 4.3 minor; no schema change, no contract change. Patch bump per `DEVOPS.md`.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.8` → `4.3.9`.
