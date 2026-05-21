# TalentTrack v3.110.209 — TaskStatus moves to `tt_lookups` + `tt_translations` (closes #839; fourth conversion from #803)

## Why

Fourth conversion from the #803 audit. `src/Modules/Workflow/TaskStatus.php` held six workflow task statuses (`open`, `in_progress`, `completed`, `overdue`, `skipped`, `cancelled`) as PHP constants — already translatable via the `.po` files at every render site, but **not editable per academy**. This is the most-seen vocabulary across the dashboard (task lists, inbox, detail panels); pilot has asked specifically about Dutch translations.

## Stored keys stay sacred

`TaskStatus::OPEN` / `::IN_PROGRESS` / `::COMPLETED` / `::OVERDUE` / `::SKIPPED` / `::CANCELLED` remain PHP constants. They're the contract with `tt_workflow_tasks.status` and drive the state machine documented in the class docblock.

## Labels move to the constants class

- New `TaskStatus::label( string $status ): string` delegates to `LookupTranslator::byTypeAndName('task_status', $value)` with the canonical English switch retained as a pre-migration fallback.
- `FrontendTaskDetailView` swaps its inline `$status_map` array for a `TaskStatus::label()` call. Side benefit: the inline map silently omitted `skipped`, so a skipped task previously rendered the raw `skipped` key; the new delegate renders the proper translated label.

## Frontend admin tile

New **"Task statuses"** tile on Configuration → Lookups. `show_color=true` so academies can colour-code the status pills on task lists (`overdue` could be red, `completed` green, etc.). `show_desc=false` — status names are self-explanatory.

## Migration 0113

Seeds 6 lookup rows + 30 `tt_translations` rows (6 × 5 locales: en_US / nl_NL / fr_FR / de_DE / es_ES). Idempotent (`INSERT IGNORE`). Operator-edited rows preserved.

## How to test

1. Apply migrations — confirm `0113_seed_task_status_lookup` in `tt_migrations`; 6 lookup rows + 30 translation rows exist.
2. Configuration → Lookups → "Task statuses" tile appears. Click → six rows render with colour-swatch fields.
3. Edit Dutch label for `in_progress` (default "In behandeling") → workflow task list renders the new label.
4. Edit colour for `overdue` → task pills on the inbox / task list render with the new colour.
5. A task with `status = 'skipped'` (rare; check `wp db query "SELECT id FROM ${prefix}tt_workflow_tasks WHERE status='skipped' LIMIT 1"` if any exist) renders a proper "Skipped" label instead of the raw key (regression fix from the inline map's missing entry).
6. Pre-migration install renders all six English labels via the switch fallback inside `TaskStatus::label()`.

## Out of scope — still on #803

#840 IdeaStatus, #842 TrialCases, #844 AudienceType, #845 MEDIUM batch.
