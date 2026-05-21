# TalentTrack v3.110.208 — PdpVerdicts decisions move to `tt_lookups` + `tt_translations` (closes #843; third conversion from #803)

## Why

Third conversion from the #803 audit. `src/Modules/Pdp/Repositories/PdpVerdictsRepository.php` held the four end-of-season verdict decision values (`promote`, `retain`, `release`, `transfer`) as a `private const ALLOWED_DECISIONS` whitelist; the rendered labels lived in two separate switch-statement helpers (`FrontendPdpManageView::decisionLabel` for the player profile pill, `PdpPrintRouter::pdpVerdictDecisionLabel` for the print page). Pilot has hinted at academy-specific terminology — *progressed* / *signed* / *released* / *moved*. Moving the labels into `tt_lookups` lets the operator rename per academy without a code change.

## Stored keys stay sacred

`ALLOWED_DECISIONS` keeps `promote / retain / release / transfer`. They're the contract with `tt_pdp_verdicts.decision`. Lookup row `name` matches the lowercase stored value so `LookupTranslator::byTypeAndName('pdp_verdict_decision', $decision)` resolves directly.

## Labels move to the repository

- New `PdpVerdictsRepository::label( string $decision ): string` is the canonical labeller. Delegates to `LookupTranslator::byTypeAndName()` with the English switch retained as a pre-migration fallback.
- `FrontendPdpManageView::decisionLabel` and `PdpPrintRouter::pdpVerdictDecisionLabel` both delegate to the repository's label — single source of truth.
- PdpPrintRouter's local switch retains the legacy `'review'` / `'pending'` codes ABOVE the delegate. Those values appear on historical rows but aren't in `ALLOWED_DECISIONS`, so the lookup table doesn't carry them and the local switch needs to handle them first.

## Frontend admin tile

New **"PDP verdict decisions"** tile on Configuration → Lookups. `show_desc=true` so academies can gloss the verdict in their context. `show_color=true` because the verdict pill is colour-coded on the player profile — operator picks the pill colour per row.

## Migration 0112

Seeds 4 lookup rows + 20 `tt_translations` rows (4 × 5 locales). Idempotent (`INSERT IGNORE`). Operator-edited rows preserved.

## How to test

1. Apply migrations — confirm `0112_seed_pdp_verdict_decision_lookup` in `tt_migrations`.
2. Configuration → Lookups → "PDP verdict decisions" tile appears. Four rows render with description + colour-swatch fields.
3. Edit Dutch label for `promote` ("Bevorderen" → academy preference) → player profile + PDP print page render the new label on files with a recorded verdict.
4. Edit colour for `release` → verdict pill on the player profile shows the new colour.
5. Pre-migration install renders the four English labels via the switch fallback inside `PdpVerdictsRepository::label()`.

## Out of scope — still on #803

#839 TaskStatus, #840 IdeaStatus, #842 TrialCases, #844 AudienceType, #845 MEDIUM batch.
