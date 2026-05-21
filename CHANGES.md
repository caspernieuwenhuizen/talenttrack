# TalentTrack v3.110.211 — IdeaStatus moves to `tt_lookups` + `tt_translations` (closes #840; sixth conversion from #803)

## Why

Sixth conversion from the #803 audit. `src/Modules/Development/IdeaStatus.php` held nine internal idea-board status values plus a `label()` switch. Workflow may evolve per academy — operators may want different terminology for `refining`, `ready-for-approval`, or the transient `promoting` state.

## Stored keys stay sacred

All nine `IdeaStatus::*` constants remain PHP constants — they're the contract with `tt_dev_ideas.status` and drive the kanban board's column structure (`boardColumns()`).

## Labels move

- `IdeaStatus::label()` delegates to `LookupTranslator::byTypeAndName('idea_status', $value)` with the canonical English switch retained as a pre-migration fallback.
- `IdeaStatus::authorFacingLabel()` is unchanged — it's a curated 4-bucket rollup (In review / Not accepted / Accepted) of the underlying 9 statuses, not a per-status label. If the operator renames `refining` to `In de revisie` via the lookup admin, `label()` returns the new Dutch text but `authorFacingLabel()` still returns "In review" (which itself goes through `__()` and lives in the .po).

## Frontend admin tile

New **"Idea statuses"** tile on Configuration → Lookups. `show_color=true` so the kanban columns can be colour-coded; `show_desc=false` (status names are self-explanatory).

## Migration 0115

Seeds 9 lookup rows + 45 `tt_translations` rows (9 × 5 locales). Idempotent (`INSERT IGNORE`). Operator-edited rows preserved.

## How to test

1. Apply migrations — confirm `0115_seed_idea_status_lookup` in `tt_migrations`; 9 lookup rows + 45 translation rows exist.
2. Configuration → Lookups → "Idea statuses" tile appears. Click → nine rows render with colour-swatch fields.
3. Edit Dutch label for `refining` → ideas board renders the new label on cards in that column.
4. Edit colour for `promoted` → kanban column pill renders with new colour.
5. Pre-migration install renders the nine English labels via the switch fallback inside `IdeaStatus::label()`.

## Out of scope — still on #803

#842 TrialCases (4 statuses + 6 decisions), #845 MEDIUM batch (5 lookup_types).
