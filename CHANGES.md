# TalentTrack v2.6.0 — Delivery Changes

## What this ZIP does

**Sprint 1b Part 1 — custom fields foundation.** Ships the infrastructure for admin-defined custom fields on players. This release adds:

- Two new database tables (polymorphic — scoped by entity_type so future sprints can add custom fields for teams / sessions / goals without schema changes).
- A new Configuration tab "Player Custom Fields" where admins can create, edit, deactivate/reactivate, and drag-to-reorder fields.
- Reusable primitives (OptionSetEditor, CustomFieldRenderer, CustomFieldValidator, admin-sortable.js) that v2.6.1 and future sprints will consume.

**What does NOT change for users in this release:** nothing outside of the new admin tab. Creating fields here does not yet affect the Player form or dashboard. That integration ships in v2.6.1.

## Install

1. Extract the ZIP.
2. Copy the **contents** of `talenttrack-v2.6.0/` into your local `talenttrack/` folder. Allow overwrites.
3. GitHub Desktop → commit `v2.6.0 — custom fields foundation` → push.
4. GitHub → Releases → new release tagged `v2.6.0`.
5. WordPress auto-updates.
6. After update, open any TalentTrack admin page once so the migration runs (it creates the two new tables idempotently).

## Files in this delivery

### Added

- `database/migrations/0003_create_custom_fields.php` — creates `tt_custom_fields` and `tt_custom_values`.
- `src/Infrastructure/CustomFields/CustomFieldsRepository.php` — field definitions CRUD.
- `src/Infrastructure/CustomFields/CustomValuesRepository.php` — value CRUD + typed output.
- `src/Shared/Validation/CustomFieldValidator.php` — per-type validation.
- `src/Shared/Frontend/CustomFieldRenderer.php` — renders inputs per type + display helpers.
- `src/Shared/Admin/OptionSetEditor.php` — reusable option-list UI block.
- `src/Modules/Configuration/Admin/CustomFieldsTab.php` — admin tab logic.
- `assets/js/admin-sortable.js` — vanilla drag-reorder.

### Modified

- `talenttrack.php` — version bumped to 2.6.0.
- `readme.txt` — stable tag + changelog entry.
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — registers the new "Player Custom Fields" tab and delegates rendering to `CustomFieldsTab`.
- `src/Shared/Admin/Menu.php` — registers `admin-sortable.js` so the custom-fields admin UI can enqueue it.
- `languages/talenttrack-nl_NL.po` — 40+ new Dutch strings for custom-fields UI + validation messages.
- `languages/talenttrack-nl_NL.mo` — recompiled (292 total messages).

### Unchanged

- Kernel, all modules except Configuration, every other admin page, the frontend dashboard, REST API, all existing data tables.

## Post-install verification

1. Log in as an administrator.
2. Go to **TalentTrack → Configuration**. A new tab **"Player Custom Fields"** appears alongside Evaluation Categories, Positions, etc.
3. Click the tab. Empty state: "No custom fields defined yet."
4. Click "Add New". Fill in:
   - Label: "Favorite Drill"
   - Type: Text
   - Leave Required unchecked
   - Save.
5. Return to the list. See the new field with auto-generated key `favorite_drill`.
6. Click "Add New" again, create a select-type field:
   - Label: "Dominant Leg Focus"
   - Type: Select (dropdown)
   - Click "+ Add option" twice, add "Strong" and "Weak"
   - Save.
7. Return to list — both fields visible. Drag the rows to reorder them, click "Save Order". The list remembers the order next time.
8. Click "Edit" on either field. The Label can be changed; Key and Type are locked (as designed).
9. Click "Deactivate" on one field — status column changes to "Inactive". Click "Activate" to reverse.
10. Switch site language to Nederlands — tab label "Extra spelervelden", labels all translate.

**No effect on players yet.** Visit Players → Edit → you'll see the same form as before. That integration is v2.6.1.

## Architectural notes

### Why polymorphic?

The spec asked for `tt_player_custom_fields` and `tt_player_custom_values`. We built `tt_custom_fields` and `tt_custom_values` with an `entity_type` column (default `player`) so a future sprint can enable team-level or session-level custom fields without another migration. All repository methods accept an `$entity_type` parameter; today it's always `player` in callers, but the schema is ready.

### What's queued for v2.6.1

- Player form integration (admin: Additional Fields section; frontend coach form: same).
- Player dashboard Overview tab displays custom field values read-only.
- REST API extension: GET `/players/{id}` returns `"custom_fields": {...}`; POST/PUT accept and validate them.
- "Go to Admin" link in the dashboard user-menu dropdown for administrators.
- Hook custom-values delete on player soft-delete.

### What's queued further out

- **Visual form designer** (drag-to-place with layout) — noted on the backlog, deferred to its own future sprint. The drag-to-reorder we ship today covers the 80% case.
