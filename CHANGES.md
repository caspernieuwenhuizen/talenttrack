# TalentTrack v3.110.205 — InvitationStatus moves to `tt_lookups` + `tt_translations` (closes #808; first conversion from the #803 audit)

## Why

Follow-up to #798 + #803. `src/Modules/Invitations/InvitationStatus.php` held the four invitation-status labels (Pending / Accepted / Expired / Revoked) as a hardcoded `switch` returning `__()` strings — translatable via the `.po` files but **not editable per academy**. The #803 audit identified this as a HIGH-priority candidate for the operator-extensible + translatable lookups infrastructure introduced in v3.110.191 (#798) and extended by v3.110.201 / v3.110.203.

## Stored keys stay sacred

`InvitationStatus::PENDING` / `::ACCEPTED` / `::EXPIRED` / `::REVOKED` (lowercase strings) remain PHP constants. They're the contract with `tt_invitations.status` — every `if ($row->status === InvitationStatus::PENDING)` callsite keeps working unchanged.

## Labels move to the lookups store

- New `lookup_type = 'invitation_status'` seeded by migration 0110.
- Lookup row `name` matches the lowercase stored value (e.g. `'pending'`) so `LookupTranslator::byTypeAndName('invitation_status', $stored_value)` resolves directly.
- Each row gets `tt_translations` entries for 5 locales: `en_US` (canonical "Pending" / "Accepted" / "Expired" / "Revoked") + `nl_NL` / `fr_FR` / `de_DE` / `es_ES`.
- `InvitationStatus::label()` delegates to `LookupTranslator::byTypeAndName()`. The original `switch` is retained as a fallback for pre-migration installs.

## Frontend admin tile

New **"Invitation statuses"** tile on `?tt_view=configuration&config_sub=lookups`. Same per-locale name editor as every other lookup category, in the master-detail layout that landed in v3.110.203. Description + colour flags off (status labels are self-explanatory; the invitations list doesn't render colour pills today).

## Migration 0110

Renamed from 0108 on rebase — main had taken 0108 (`fix_scout_prospects_scope_global`) and 0109 (`backfill_lookup_translations_fr_de_es_v2`) in the meantime. The new number is 0110.

The migration seeds 4 `tt_lookups` rows + 20 `tt_translations` rows (4 statuses × 5 locales). Idempotent (`INSERT IGNORE` on the unique indexes). Operator-edited rows are preserved on re-run.

## Files touched

- `database/migrations/0110_seed_invitation_status_lookup.php` (new)
- `src/Modules/Invitations/InvitationStatus.php` — `label()` now delegates to `LookupTranslator`
- `src/Shared/Frontend/FrontendConfigurationView.php` — tile + registry entry
- `talenttrack.php` + `readme.txt` — version bump

## How to test

1. Apply migrations: `wp tt migrate`. Confirm `0110_seed_invitation_status_lookup` lands in `tt_migrations` and 4 `tt_lookups` rows + 20 `tt_translations` rows exist.
2. Open Configuration → Lookups → **Invitation statuses**. List of four rows; click any → form on the right pre-fills with the canonical label + per-locale translations.
3. Edit the Dutch label for `pending` ("In behandeling" → "Wachtend op antwoord") → save → invitations list now renders the new Dutch label.
4. Pre-migration install (migration 0110 not run) still renders "Pending" / "Accepted" / "Expired" / "Revoked" via the `switch` fallback inside `InvitationStatus::label()`.

## Out of scope — still on #803

Same pattern still to land for `TaskStatus`, `GoalApprovalForm` decisions, `TrialCases` statuses + decisions, `PdpVerdicts`, `AudienceType`, `IdeaStatus`. Each is its own small PR; pick them up incrementally as appetite allows.
