# TalentTrack v3.110.205 — InvitationStatus moves to `tt_lookups` + `tt_translations` (closes #808; first conversion from the #803 audit)

## Why

Follow-up to #798 + #803. `src/Modules/Invitations/InvitationStatus.php` held the four invitation-status labels (Pending / Accepted / Expired / Revoked) as a hardcoded `switch` returning `__()` strings — translatable via the `.po` files but **not editable per academy**.

## Stored keys stay sacred

`InvitationStatus::PENDING` / `::ACCEPTED` / `::EXPIRED` / `::REVOKED` remain PHP constants — they're the contract with `tt_invitations.status`. Every code-side comparison keeps working unchanged.

## Labels move to the lookups store

- New `lookup_type = 'invitation_status'` seeded by migration 0110.
- Lookup row `name` matches the lowercase stored value so `LookupTranslator::byTypeAndName('invitation_status', $stored_value)` resolves directly.
- Each row gets `tt_translations` entries for 5 locales: en_US + nl_NL / fr_FR / de_DE / es_ES.
- `InvitationStatus::label()` delegates to `LookupTranslator::byTypeAndName()` with the original `switch` retained as a pre-migration fallback.

## Frontend admin tile

New **"Invitation statuses"** tile on Configuration → Lookups. Same per-locale name editor as every other lookup category (master-detail layout, v3.110.203). Description + colour flags off.

## Migration 0110

Seeds 4 lookup rows + 20 `tt_translations` rows (4 × 5 locales). Idempotent (`INSERT IGNORE` on the unique indexes). Operator-edited rows preserved.

## How to test

1. Apply migrations — confirm `0110_seed_invitation_status_lookup` in `tt_migrations`; 4 lookup rows + 20 translation rows exist.
2. Configuration → Lookups → "Invitation statuses" tile appears. Click → list of four rows.
3. Edit Dutch label for `pending` → invitations list renders the new label.
4. Pre-migration install renders the four English labels via the switch fallback.

## Out of scope — still on #803, now individual tickets

#839 TaskStatus, #840 IdeaStatus, #841 GoalApprovalForm, #842 TrialCases, #843 PdpVerdicts, #844 AudienceType, #845 MEDIUM batch (InvitationKind / IdeaType / ScoutingVisits / ScheduledReports).
