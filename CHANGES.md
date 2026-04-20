# TalentTrack v2.11.0 — Sprint 1H: Custom fields framework

## What this release does

Lets a club admin define custom fields on any of five entities (Players, People, Teams, Sessions, Goals) and place each field at an arbitrary position on that entity's edit form — between two native fields, right after a specific native field, or at the end of the form.

Before 2.11.0, custom fields existed only for Players, as a fixed "Additional Fields" block at the bottom of the edit page. The feature had a working foundation (`tt_custom_fields` and `tt_custom_values` tables from v2.6.0, a `CustomFieldRenderer` and `CustomFieldValidator` already wired into `PlayersPage`) but never got further.

2.11.0 generalizes what was there, extends it with five more field types and four more entities, and lets admins position fields wherever they want on the form.

## What's new for end users

**TalentTrack → Custom Fields** — a new top-level admin page. Pick the entity from a tab bar (Players / People / Teams / Sessions / Goals), see the list of defined custom fields for that entity, add or edit fields.

Each custom field now has:

- **Type** — one of text, long text (textarea), number, date, select (dropdown), multi-select, checkbox, URL, email, phone. Type is chosen at creation and can't be changed afterward (changing a field's type mid-life would corrupt stored data).
- **Insert after** — a dropdown listing every native field slug for the target entity, plus "(at end of form)". The chosen slug decides where on the edit form the custom field appears.
- **Sort order** — tiebreaker when two custom fields target the same "Insert after" slug. Lower renders first.
- **Required** flag — validated on save with a per-field error message.
- **Options** (for select and multi-select) — one line per option, `value|Label` syntax.

Existing custom fields from pre-2.11.0 installs continue to work. They retain their `sort_order` but have `insert_after = NULL`, which renders them at the end of the form (same position as before). Admins can open each existing field and re-position it if desired.

## What's new for developers

Three new framework pieces, all entity-agnostic:

- **`FormSlugContract`** (`src/Modules/Configuration/Admin/`) — the single source of truth for native-field slugs per entity type. Each entity publishes an ordered `[slug => label]` map. Powers the "Insert after" dropdown on the admin page AND the slot positioning at render time.
- **`CustomFieldsSlot`** (`src/Infrastructure/CustomFields/`) — the form-injection point. Each entity's edit page calls `CustomFieldsSlot::render( $entity, $id, $slug )` after each native `<tr>` row. Fields with `insert_after` matching the slug render inline. At the bottom of the form, `CustomFieldsSlot::renderAppend()` picks up fields with `insert_after IS NULL`.
- **`CustomFieldValidator::persistFromPost()`** — new static entry point that combines validate + upsert for save handlers. Each module's save handler now calls this once after the native save succeeds. Validation errors accumulate but do not undo the native save — the save handler redirects with a `tt_cf_error` query flag which the edit form renders as a warning notice.

The existing `TT\Shared\Frontend\CustomFieldRenderer` was extended with support for the five new field types (textarea, multi_select, url, email, phone) and a new `inputRow()` convenience that produces the full `<tr><th>…</th><td>…</td></tr>` wrapper for WP-admin `.form-table` layouts. The existing `input()` and `display()` methods keep working for anyone rendering in other contexts.

The existing `TT\Shared\Validation\CustomFieldValidator` was rewritten to cover all ten field types and to distinguish "field wasn't on this form" (skip — don't touch stored value) from "field was on the form but submitted empty" (clear). The latter matters for multi-select, where no checkbox ticked means "clear"; the form emits a hidden marker input so the save layer can tell the two cases apart.

The old `Infrastructure\CustomFields\CustomFieldRenderer` + `CustomValuesService` files that early drafts of this sprint created were removed — maintaining two parallel systems would have been a nightmare. Everything flows through `Shared\Frontend\CustomFieldRenderer` and `Shared\Validation\CustomFieldValidator` now.

The old player-only `CustomFieldsTab` (configuration sub-tab) was retired. Its handlers were renamed into `CustomFieldsPage` under a new admin menu entry.

## Bugs fixed along the way

**`GoalsPage::handle_save()` didn't capture `$wpdb->insert_id` on new goal creation.** Pre-existing since v2.6.x. Any post-save integration that needed the new goal's ID (audit log, downstream hooks, and now custom fields) silently no-op'd on create. The 2.11.0 save handler captures `$id = (int) $wpdb->insert_id` after a successful insert. Existing goals are unaffected.

## Schema changes

Additive, non-destructive.

- `tt_custom_fields.insert_after VARCHAR(64) NULL` — the positioning slug. NULL means "append at end".
- `idx_insert_after (entity_type, insert_after)` — index on the new column. Speeds up the slot lookup performed on every form render.

Migration `0007_custom_fields_positioning.php` applies both changes with idempotent helpers. Per the v2.10.1 learning, this uses explicit ALTER and checks for pre-existing column/index — no `dbDelta` surprises.

`Activator::ensureSchema()` has been updated to match so fresh installs get the column from the beginning.

## UI changes per entity

Native forms for Players, People, Teams, Sessions, and Goals now call `CustomFieldsSlot::render()` after every native field, and `renderAppend()` at the bottom of the form. The slug names exactly match what the "Insert after" dropdown offers. Forms without any custom fields render identically to pre-2.11.0.

For Players, the "Additional Fields" block at the bottom of the edit form was removed — custom fields are now rendered via the slot mechanism throughout the form. The detail/view page uses `CustomFieldsSlot::renderReadonly()` which outputs an "Additional information" section showing only fields with non-empty values.

For Teams, Sessions, Goals, and People, custom field values show up directly on the edit form (you see them as inputs when editing). None of these have a standalone detail view, so there's no separate readonly rendering.

## Scope boundaries

What's explicitly NOT in 2.11.0:

- **Custom fields on Evaluations** — ships in 2.12.0 (Sprint 1I) alongside the evaluation subcategory work. Doing both at once on the same form is cleaner than doing it twice in consecutive sprints.
- **Drag-and-drop reorder** of custom fields within an "Insert after" group. Sort-order numeric input works today; drag-and-drop can come later.
- **Search/filter** on list pages by custom field values. Not in scope. Edit pages show values; detail pages show values; list pages still show only the native columns.
- **Custom fields in the REST API** response. Not touched this sprint.
- **File upload, rich text, repeater field types.** The ten types shipped cover 99% of real-world use; the remaining types are each their own project.
- **Audit log of custom value writes.** `tt_audit_log` doesn't capture `tt_custom_values` writes in 2.11.0.

## Files in this release

### New
- `src/Modules/Configuration/Admin/CustomFieldsPage.php` — top-level admin page
- `src/Modules/Configuration/Admin/FormSlugContract.php` — native slug map per entity
- `src/Infrastructure/CustomFields/CustomFieldsSlot.php` — form injection point
- `database/migrations/0007_custom_fields_positioning.php` — schema migration

### Modified
- `talenttrack.php` — version 2.11.0
- `src/Core/Activator.php` — `ensureSchema()` adds `insert_after` + `idx_insert_after`
- `src/Infrastructure/CustomFields/CustomFieldsRepository.php` — five entity constants, ten type constants, `allowedEntityTypes()`, `typeIsMulti()`, `insert_after` in `normalise()`, new `getActiveGroupedByInsertAfter()`
- `src/Infrastructure/CustomFields/CustomValuesRepository.php` — `castForOutput()` handles multi_select
- `src/Shared/Frontend/CustomFieldRenderer.php` — 10 types, new `inputRow()` method
- `src/Shared/Validation/CustomFieldValidator.php` — 10 types, `persistFromPost()` entry point
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — dropped `custom_fields` tab + switch case; wired new handlers in `init()`
- `src/Shared/Admin/Menu.php` — added "Custom Fields" submenu
- `src/Modules/Players/Admin/PlayersPage.php` — slot calls at every native slug, `renderReadonly` on detail view, old "Additional Fields" block removed
- `src/Modules/People/Admin/PeoplePage.php` — slot calls + `persistFromPost` + error notice
- `src/Modules/Teams/Admin/TeamsPage.php` — slot calls + `persistFromPost` + error notice
- `src/Modules/Sessions/Admin/SessionsPage.php` — slot calls + `persistFromPost` + error notice
- `src/Modules/Goals/Admin/GoalsPage.php` — slot calls + `persistFromPost` + error notice + `insert_id` bug fix
- `languages/talenttrack-nl_NL.po` + `.mo` — Dutch translations for 42 new strings

### Removed
- `src/Modules/Configuration/Admin/CustomFieldsTab.php` — superseded by `CustomFieldsPage`

## Install

Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting. Commit, push, tag `v2.11.0`, release.

On reactivation, `Activator::ensureSchema()` adds the new column (no-op on fresh installs where the column is already in the CREATE TABLE statement). Migration `0007` applies for existing sites and is idempotent.

## Verify

1. TalentTrack → Custom Fields — new menu entry appears below Configuration. The old "Player Custom Fields" sub-tab under Configuration is gone (not a bug — it's been promoted).
2. Entity tabs at top: Players, People, Teams, Sessions, Goals. Each tab shows its own field list.
3. Add a test custom field on Players: label, key, type, set "Insert after: Nationality", save.
4. Edit any player — the new field appears between Nationality and Height. Enter a value and save.
5. View the player detail page — the field appears in the "Additional information" section.
6. Edit the field, change "Insert after" to "(at end of form)" — now it appears below all native fields.
7. Repeat the above on Teams, Sessions, Goals, People to confirm all five entities work.
8. Try each of the ten field types. Multi-select values should round-trip correctly (save, reload, values preserved).

## Known follow-ups

See "Scope boundaries" above. Everything noted there is deliberately deferred.
