# TalentTrack v3.110.212 — TrialCases statuses + decisions move to `tt_lookups` + `tt_translations` (closes #842; seventh conversion from #803)

## Why

Seventh conversion from the #803 audit — the biggest one. `src/Modules/Trials/Repositories/TrialCasesRepository.php` held two related vocabularies:

- **Statuses** (4): `open`, `extended`, `decided`, `archived`
- **Decisions** (6): `admit`, `deny_final`, `deny_encouragement`, `offered_team_position`, `declined_offered_position`, `continue_in_trial_group`

Heavy operator surface; trial workflow varies a lot by academy. Coaches have asked the most about this one.

## Adjacent bug fixed along the way

The status + decision strip in `FrontendTrialCaseView::renderOverview()` (and the same pair of columns in `FrontendTrialsManageView`'s list table) was echoing the raw stored key (`(string) $case->status`, `(string) $r->decision`) — bypassing any label or translation. So Dutch installs were seeing "open" / "extended" / "admit" / "deny_final" on the page. The conversion to lookups + the new `statusLabel()` / `decisionLabel()` helpers fixes this at the same time as exposing the operator-edit surface.

## Stored keys stay sacred

All 10 `STATUS_*` and `DECISION_*` constants stay PHP constants. Contracts with `tt_trial_cases.status` and `tt_trial_cases.decision`.

## Labels move to the repository

- New `TrialCasesRepository::statusLabel( string $status ): string` delegates to `LookupTranslator::byTypeAndName('trial_case_status', $value)` with the English switch as a pre-migration fallback.
- New `TrialCasesRepository::decisionLabel( string $decision ): string` — same chain against `trial_case_decision`.
- Three consumer sites updated to call the new helpers (two were echoing the raw key; one had an inline `__()` array for the decision-form radios).

## Frontend admin tiles

Two new tiles on Configuration → Lookups:
- **"Trial statuses"** — `show_color=true` so the trial list can colour-code status pills; `show_desc=false` (status names are self-explanatory).
- **"Trial decisions"** — `show_desc=true` so academies can gloss what each decision means in their context ("decline (with encouragement to re-apply) — the player can re-trial next season"); `show_color=false`.

## Migration 0116

Single migration that seeds both lookup_types (pattern mirrors `0098_tournament_lookups_seed.php`). 10 lookup rows + 50 `tt_translations` rows (10 × 5 locales: en_US / nl_NL / fr_FR / de_DE / es_ES). Idempotent (`INSERT IGNORE`). Operator-edited rows preserved.

## How to test

1. Apply migrations — confirm `0116_seed_trial_case_lookups` in `tt_migrations`; 10 lookup rows + 50 translation rows exist.
2. Configuration → Lookups → "Trial statuses" + "Trial decisions" tiles appear.
3. On a Dutch install, open the trials list (`?tt_view=trials-manage`). Confirm the Status + Decision columns now render translated labels ("Open" / "Verlengd" / "Toelaten (een plek bieden)" etc.) instead of raw stored keys.
4. Edit Dutch label for `decided` → trial list + trial detail page render the new label.
5. Edit colour for `extended` → status pill on the list renders with the new colour.
6. Open a trial case in `open` status, record an `admit` decision → the decision-form radio renders the per-locale label.
7. Pre-migration install renders the 10 English labels via the switch fallbacks inside `statusLabel()` / `decisionLabel()`.

## Out of scope — still on #803

#845 MEDIUM batch (InvitationKind / IdeaType / ScoutingVisits.status / ScheduledReports frequency + status) — final ship to close the audit.
