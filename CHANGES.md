# TalentTrack v4.3.2 — VCT module ship 3: lookup categories with direct translations (closes #908, partial epic #905)

## Context

Ship 3 of the VCT epic. VCT-1 (v4.3.0) landed the schema; VCT-2 (v4.3.1) landed the caps + matrix seed; this ship adds the five lookup categories the engine consumes for slot composition, with translated values for every locale on day one.

No UI, no REST, no engine yet — pure vocabulary that VCT-4 (seed defaults), VCT-5 (rules engine), VCT-6 (REST), VCT-7 (nightly task) all build on.

## What changed

### Five new lookup categories

Seeded into `tt_lookups` via new migration `0124_vct_seed_lookups.php`:

| Lookup type | Values | Where it's consumed |
|---|---|---|
| `vct_exercise_category` | warmup, technical, sided_game, conditioning, finishing, cool_down (6) | `tt_vct_exercises.category` + slot composition in VCT-5 |
| `vct_tactical_theme` | build_up, pressing, transition, counter, defending, finishing, set_pieces, 1v1_duels, possession, mixed (10) | `tt_vct_exercises.tactical_theme` + the wizard's theme picker (VCT-6) |
| `vct_md_context` | MD-4, MD-3, MD-2, MD-1, MD, MD+1, MD+2, NONE (8) | `tt_vct_sessions.md_context` + `tt_vct_session_templates.md_context` |
| `vct_intensity_band` | band_1 … band_10 (10) | Aligns with the `TINYINT` `intensity_band` column on `tt_vct_exercises` / `tt_vct_session_blocks` / `tt_vct_age_profiles.intensity_band_max` |
| `vct_session_status` | draft, published, completed, archived (4) | Matches the ENUM on `tt_vct_sessions.status` from migration 0122 |

### Translations for all five locales — written directly

Every value gets `tt_translations` rows for nl_NL / fr_FR / de_DE / es_ES — written from a PHP label map inside the migration. **No `.po` backfill**, no `switch_to_locale($loc); __($name)` round-trip. This is the explicit fix for the gap landed as #902 (`feedback_lookup_seed_translations` memory): the backfill pattern silently writes nothing when the `.po` lacks the string, leaving Dutch installs showing raw English. Direct writes guarantee every value lands in every locale on day one.

Example translations:

- `pressing` → NL "Drukzetten" / FR "Pressing" / DE "Pressing" / ES "Presión"
- `transition` → NL "Omschakeling" / FR "Transition" / DE "Umschalten" / ES "Transición"
- `build_up` → NL "Opbouw" / FR "Construction" / DE "Spielaufbau" / ES "Construcción"
- `set_pieces` → NL "Standaardsituaties" / FR "Coups de pied arrêtés" / DE "Standardsituationen" / ES "Balones parados"

Operators can override any per-locale label post-install via the Lookups admin.

### LabelTranslator companion methods

`src/Infrastructure/Query/LabelTranslator.php` gains five new methods so the `.pot` extractor picks up every canonical English value on next regeneration:

- `vctExerciseCategory()` — 6 cases + humanise fallback
- `vctTacticalTheme()` — 10 cases + humanise fallback
- `vctMdContext()` — special-cases the MD-N / MD+N abbreviations (universal football vocabulary, identical in every locale); expands the bare `MD` token to "Match day" and the `NONE` sentinel to "No match context"
- `vctIntensityBand()` — uses `sprintf( __('Intensity band %d', …) )` so the numeric value (1–10) stays identical across locales while the prefix is localised
- `vctStatus()` — 4 lifecycle states

This is the "extractor companion" pattern from #902: direct `tt_translations` writes give the operator the right values immediately; `__()` wrappers ensure future `.pot` regenerations also pick up the strings so the `.po`/`.mo` workflow stays in sync.

### Idempotency

- `tt_lookups` rows: existence-check on `(club_id, lookup_type, name)` before insert.
- `tt_translations` rows: `INSERT IGNORE` on the unique key.
- Re-running the migration on an install where the operator has edited values via the Lookups admin leaves their edits untouched.

Mirrors the pattern from `0116_seed_trial_case_lookups.php`.

## Out of scope

- Age profiles, session templates, phase profiles — VCT-4 (#909).
- Exercise catalogue — separate VCT-8 issue (gated on pilot-coach review per the spec).
- Rules engine + repositories — VCT-5 (#910).
- REST + UI — VCT-6 (#911).
- Workload aggregation task — VCT-7 (#912).

## Validation

- After migration runs: `SELECT lookup_type, COUNT(*) FROM wp_tt_lookups WHERE club_id = 1 GROUP BY lookup_type` shows the five new types with the right row counts (6 / 10 / 8 / 10 / 4 = 38 new rows).
- `SELECT COUNT(*) FROM wp_tt_translations WHERE entity_type = 'lookup' AND locale = 'nl_NL'` increases by 38; same for fr_FR / de_DE / es_ES.
- `?tt_view=configuration&config_sub=lookups` shows each of the five new types with the NL + fr/de/es translations populated per row.
- Switch site locale to French → frontend renders the French msgstr for each lookup value (via `LookupTranslator::byTypeAndName()` resolution).
- Re-running the migration: counts unchanged.

## Why this is `patch`, not `minor`

Seed data within the 4.3 minor that VCT-1 opened. No new feature epic; just the vocabulary VCT-4 onwards consume. Patch bump per `DEVOPS.md` § "When to bump what".

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.1` → `4.3.2`.
