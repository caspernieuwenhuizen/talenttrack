# TalentTrack v3.110.207 — GoalApprovalForm decisions move to `tt_lookups` + `tt_translations` (closes #841; second conversion from #803)

## Why

Second conversion from the #803 audit punch list (first was InvitationStatus, shipped as v3.110.205). `src/Modules/Workflow/Forms/GoalApprovalForm.php` held three decision labels (`approve`, `amend`, `reject`) as inline `__()` calls in the render method's radio loop — translatable via the `.po` files but **not editable per academy**.

## Stored keys stay sacred

`GoalApprovalForm::DECISION_APPROVE` / `::DECISION_AMEND` / `::DECISION_REJECT` remain PHP constants. They're the contract with `tt_workflow_tasks.response_json[*].decision`. `validate()` and `serializeResponse()` keep comparing against the constants — only the rendered label changes.

## Labels move to the lookups store

- New `lookup_type = 'goal_approval_decision'` seeded by migration 0111.
- New `GoalApprovalForm::label( string $decision ): string` delegates to `LookupTranslator::byTypeAndName('goal_approval_decision', $value)` with the canonical `switch` retained as a pre-migration fallback. The render loop calls `self::label(self::DECISION_*)` instead of the previous inline `__()` array.
- Each row gets `tt_translations` entries for 5 locales (en_US + nl_NL / fr_FR / de_DE / es_ES). All Dutch + French + German + Spanish labels match the previously-shipped `.po` strings, so the visible behaviour on a fresh install is identical.

## Frontend admin tile

New **"Goal approval decisions"** tile on Configuration → Lookups. `show_desc=true` because *"Approve with amendment"* benefits from a gloss ("back to the player to revise") that an academy might want to phrase differently. `show_color=false`.

## Migration 0111

Seeds 3 lookup rows + 15 `tt_translations` rows (3 × 5 locales). Idempotent (`INSERT IGNORE` on the unique indexes). Operator-edited rows preserved on re-run.

## How to test

1. Apply migrations — confirm `0111_seed_goal_approval_decision_lookup` in `tt_migrations`; 3 lookup rows + 15 translation rows exist.
2. Configuration → Lookups → "Goal approval decisions" tile appears. Click → three rows render with description fields.
3. Edit Dutch label for `amend` ("Goedkeuren met aanpassing" → academy's preferred phrasing) → goal-approval task radio renders the new label.
4. Pre-migration install renders the three English labels via the switch fallback inside `GoalApprovalForm::label()`.

## Out of scope — still on #803

#839 TaskStatus, #840 IdeaStatus, #842 TrialCases, #843 PdpVerdicts, #844 AudienceType, #845 MEDIUM batch (InvitationKind / IdeaType / ScoutingVisits / ScheduledReports).
