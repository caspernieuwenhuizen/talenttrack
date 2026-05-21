# TalentTrack v3.110.210 — AudienceType moves to `tt_lookups` + `tt_translations` (closes #844; fifth conversion from #803)

## Why

Fifth conversion from the #803 audit. `src/Modules/Reports/AudienceType.php` held eight report-audience values (`standard`, `parent_monthly`, `internal_detailed`, `player_personal`, `scout`, `trial_admittance`, `trial_denial_final`, `trial_denial_encouragement`) with the labels AND a per-audience description (the operator-facing gloss in the report wizard's audience picker). Both rendered through hardcoded `__()` switches — translatable via .po but not editable per academy.

## Stored keys stay sacred

`AudienceType::STANDARD` etc. remain PHP constants — the contract with `tt_reports.audience_type`. `isValid()`, `isTrialLetter()`, etc. keep comparing against the constants.

## Labels + descriptions move to the lookups store

- `AudienceType::label()` delegates to `LookupTranslator::byTypeAndName('audience_type', $value)`.
- `AudienceType::describe()` delegates to a new sibling helper **`LookupTranslator::descriptionByTypeAndName()`** that returns the description translation for the matching lookup row. The helper shares the per-request row cache with `byTypeAndName()` — no extra SELECTs.
- Both methods retain their canonical English switch as a pre-migration fallback.

## New LookupTranslator helper

`LookupTranslator::descriptionByTypeAndName( string $type, string $stored_name ): string`. Mirrors the `byTypeAndName()` label helper. Internally refactored both to share a `rowByTypeAndName()` private helper for the row lookup — no behaviour change for existing callers.

## Frontend admin tile

New **"Report audiences"** tile on Configuration → Lookups. `show_desc=true` because each audience carries a distinct operator-facing gloss; `show_color=false` (audiences aren't pilled with a colour anywhere).

## Migration 0114

Seeds 8 lookup rows + 40 `tt_translations` rows for the labels (8 × 5 locales) + 32 `tt_translations` rows for the descriptions (8 × 4 non-English locales — en_US is already the canonical `description` column on the lookup row). Idempotent (`INSERT IGNORE`). Operator-edited rows preserved.

## How to test

1. Apply migrations — confirm `0114_seed_audience_type_lookup` in `tt_migrations`; 8 lookup rows + ~72 translation rows exist.
2. Configuration → Lookups → "Report audiences" tile appears. Click → 8 rows render with per-locale description editors.
3. Edit Dutch label for `parent_monthly` → report wizard audience picker renders the new Dutch label.
4. Edit Dutch description for `scout` → the wizard's audience-picker explainer text renders the new Dutch description.
5. Pre-migration install renders the 8 English labels + descriptions via the switch fallbacks.

## Out of scope — still on #803

#840 IdeaStatus, #842 TrialCases, #845 MEDIUM batch.
