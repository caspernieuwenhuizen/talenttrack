# TalentTrack v2.12.0 — Sprint 1I: Evaluation subcategories + Evaluations custom fields

## What this release does

Evaluations get a two-level hierarchy. Each main category (Technical, Tactical, Physical, Mental by default) can have subcategories (Short pass, Long pass, Shooting, etc.) that a coach can rate individually. Per your either/or design, the coach picks per main category whether to rate the main directly OR drill into subcategories — the form lets them switch with a single click.

The Evaluations entity also picks up the full custom-fields machinery from Sprint 1H, so club admins can now add custom fields to the evaluation form just like Players/Teams/Sessions/Goals/People already support.

## The either/or rating model

For any given (evaluation, main category), there are three possible states:

1. **Direct rating only.** Coach entered a number for the main (e.g. Technical = 4). No subcategory ratings for that main. Display shows the direct number.
2. **Subcategory ratings only.** Coach entered numbers for one or more subcategories (e.g. Short pass = 4, Long pass = 3, First touch = 5). The main's "effective" rating is **computed on read** as the mean of those subs — it's not stored as a separate row. Display shows the computed number with an "(averaged from 3 subcategories)" suffix and lists the sub ratings beneath.
3. **Neither.** Main isn't rated at all.

A coach can mix modes across categories on a single evaluation — e.g. Technical in subcategory mode, Tactical in direct mode, Physical in direct mode, Mental skipped.

Switching modes on the form discards the other mode's inputs silently (per the Sprint 1I design decision — the UI mode is the authoritative signal, no modal). The `tt_rating_mode[<main_id>]` hidden input tracks UI state per main and the save handler reads ratings from whichever bucket the mode points to.

## Schema changes

Two schema changes in this release, both additive, both non-destructive.

### New table: `tt_eval_categories`

Replaces the `lookup_type='eval_category'` rows in `tt_lookups`. Supports hierarchy via `parent_id` (nullable, self-referencing). Columns:

```
id             BIGINT UNSIGNED  PK
parent_id      BIGINT UNSIGNED  NULL   — main categories have NULL; subs point at their main
key            VARCHAR(64)      UNIQUE — stable identifier like 'technical_short_pass'
label          VARCHAR(255)             — display name (translatable via .po)
description    TEXT             NULL
display_order  INT              DEFAULT 0
is_active      TINYINT(1)       DEFAULT 1
is_system      TINYINT(1)       DEFAULT 0  — canonical system categories can be renamed but not deleted
created_at     DATETIME
updated_at     DATETIME
```

Indexes: `uniq_key`, `idx_parent`, `idx_active`.

### Extended: `tt_custom_fields` accepts `ENTITY_EVALUATION`

No table change — the `entity_type` column was already a free-form `VARCHAR(50)`. The extension is purely in the domain layer (added `ENTITY_EVALUATION = 'evaluation'` constant + updated `allowedEntityTypes()`) plus the `FormSlugContract::evaluationSlugs()` map that drives the "Insert after" dropdown.

## Migration 0008

`0008_eval_categories_hierarchy.php` — handles the lookup-to-new-table transition idempotently:

1. **Creates** `tt_eval_categories` if missing.
2. **Copies** every `lookup_type='eval_category'` row from `tt_lookups` into `tt_eval_categories` as a main category (parent_id IS NULL). Builds an `old_lookup_id → new_category_id` remap while copying. `is_system=1` is set only for the canonical four keys (`technical`, `tactical`, `physical`, `mental`) — admin-added categories migrate as `is_system=0`.
3. **Retargets** every `tt_eval_ratings.category_id` from its old lookup ID to the new category ID. Rows whose category_id already points at a new-table row (re-run scenario) are counted as already-retargeted.
4. **Seeds** 21 subcategories as children of the canonical four main categories. Idempotent — skipped if a subcategory with the same key already exists or if its parent main category has been renamed/deleted.
5. **Deletes** the old `lookup_type='eval_category'` rows from `tt_lookups` — but **only if** every rating successfully retargeted. If any orphan remains, the migration throws `RuntimeException` leaving the old lookup rows in place so no data is silently dropped.

Safe to re-run; safe to fail half-way; additive to any rows an admin already created in the new table. Existing evaluations keep working throughout — their `tt_eval_ratings.category_id` values get retargeted during the migration and the JOIN in `QueryHelpers::get_evaluation()` now hits the new table.

## Seed data

On fresh installs, `Activator::seedEvalCategoriesIfEmpty()` populates the new table with:

- **4 main categories**: Technical, Tactical, Physical, Mental (all with `is_system=1`)
- **21 subcategories**:
  - **Technical** (6): Short pass, Long pass, First touch, Dribbling, Shooting, Heading
  - **Tactical** (5): Offensive positioning, Defensive positioning, Game reading, Decision making, Off-ball movement
  - **Physical** (5): Speed, Endurance, Strength, Agility, Coordination
  - **Mental** (5): Focus, Leadership, Attitude, Resilience, Coachability

All 25 seed entries have Dutch translations in `talenttrack-nl_NL.po`. Clubs can deactivate the ones they don't use, add their own, or rename any of them (keys stay stable — labels are free to change).

This seed routine also runs on every activation and is idempotent. If migration 0008 successfully ran but an admin had deleted the canonical main categories beforehand, the seed routine will re-create them — the seed never overwrites existing entries.

## New admin page: TalentTrack → Evaluation Categories

Replaces the old "Evaluation Categories" tab that lived under Configuration. The old tab couldn't express parent/child relationships; the new page is a dedicated tree view.

- Main categories render as header rows with a subtle background.
- Each main has its subcategories indented directly underneath with a `↳` prefix.
- "Add main category" button at the top of the page.
- "Add sub" link on each main row for adding subcategories under that specific parent.
- Edit/Activate/Deactivate inline on every row.
- Display order is a numeric field on the edit form (drag-and-drop deferred — convention is to use increments of 10 so new items can be inserted between existing ones).
- System categories are marked with a ✓ and can be renamed/deactivated but not deleted (no Delete action is exposed for them).

Anyone with a bookmark to the old Configuration tab URL (`?page=tt-config&tab=eval_categories`) gets redirected to the new page.

## Compute-on-read rollup

When a coach rates subcategories without entering a direct main rating, the main's effective rating is computed by `EvalRatingsRepository::effectiveMainRating($eval_id, $main_cat_id)`. Return shape:

```php
[ 'value' => float|null, 'source' => 'direct'|'computed'|'none', 'sub_count' => int ]
```

- `direct` — coach entered a main rating, returned as-is (sub_count is 0)
- `computed` — no direct rating, mean of sub_count subcategory ratings
- `none` — neither present, value is null

This is what the evaluation detail page calls to display ratings, and what the radar chart feeds off. Epic 2 (statistics) will call this everywhere too so charts don't need to know which mode a given evaluation used.

No computed rollup row is ever written to `tt_eval_ratings` — the table stores only what was entered. The rollup materializes only at read time. Means no staleness, no need to recompute when subcategories change, one source of truth.

## Custom fields on Evaluations

Mechanically identical to Sprint 1H's five other entities. Specifically:

- `FormSlugContract::evaluationSlugs()` returns 9 native slugs: `player_id`, `eval_type_id`, `eval_date`, `opponent`, `competition`, `match_result`, `home_away`, `minutes_played`, `notes`.
- The Evaluations admin form calls `CustomFieldsSlot::render()` after each native field and `renderAppend()` at the end of the form.
- `CustomFieldValidator::persistFromPost(ENTITY_EVALUATION, $id, $_POST)` persists values after the native save.
- Detail view calls `CustomFieldsSlot::renderReadonly(ENTITY_EVALUATION, $id)` below the ratings table.
- `tt_cf_error` query flag triggers a warning notice on the edit form when a CF validation error happened.
- TalentTrack → Custom Fields now has an "Evaluations" entity tab alongside the existing five.

The ratings grid is explicitly NOT part of the slug contract — it's its own UI section, not a "field". Custom fields anchor to the native form fields around it.

## Call-site redirects (from `tt_lookups` to `tt_eval_categories`)

Six places that read `lookup_type='eval_category'` were rewired to the new table:

1. `QueryHelpers::get_categories()` — now delegates to `EvalCategoriesRepository::getMainCategoriesLegacyShape()` which returns objects with the same fields (`->id`, `->name`, `->description`, `->sort_order`) the old lookup rows had. Existing callers of `get_categories()` don't need to change.
2. `QueryHelpers::get_evaluation()` — the ratings JOIN now hits `tt_eval_categories` instead of `tt_lookups`, and the query exposes `->category_parent_id` and `->category_key` on each rating for hierarchy-aware consumers.
3. `ConfigurationPage` — the `eval_categories` tab is gone from the tabs list and the switch. Old bookmarks redirect to `page=tt-eval-categories`.
4. `ConfigurationPage::tab_key_for_type()` — `eval_category` entry removed from the map.
5. `Activator::seedDefaultsIfEmpty()` — no longer seeds `eval_category` rows into `tt_lookups`. Delegates to `seedEvalCategoriesIfEmpty()`.
6. The Evaluations form and detail view — fully rewritten to use the hierarchy-aware repositories.

## The legacy-shape shim

`EvalCategoriesRepository::getMainCategoriesLegacyShape()` exists specifically to keep `QueryHelpers::get_categories()` stable for any caller (including third-party code using the `tt_modify_categories` filter hook). The method returns main categories as plain objects with the pre-2.12 field names (`->name`, `->sort_order`, `->description`). New code should prefer `getMainCategories()` or `getTree()`, which expose the full new column set (`->label`, `->display_order`, `->key`, `->parent_id`, etc.).

## Files in this release

### New
- `src/Infrastructure/Evaluations/EvalCategoriesRepository.php`
- `src/Infrastructure/Evaluations/EvalRatingsRepository.php`
- `src/Modules/Evaluations/Admin/EvalCategoriesPage.php`
- `database/migrations/0008_eval_categories_hierarchy.php`

### Modified
- `talenttrack.php` — version 2.12.0
- `src/Core/Activator.php` — new `tt_eval_categories` schema; `seedEvalCategoriesIfEmpty()` helper; retired `eval_category` seed from `seedDefaultsIfEmpty()`
- `src/Infrastructure/Query/QueryHelpers.php` — `get_categories()` and `get_evaluation()` rewired to the new table
- `src/Infrastructure/CustomFields/CustomFieldsRepository.php` — added `ENTITY_EVALUATION` constant + entry in `allowedEntityTypes()`
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — dropped the `eval_categories` tab; redirects old URLs
- `src/Modules/Configuration/Admin/CustomFieldsPage.php` — added "Evaluations" to the entity tabs and label map
- `src/Modules/Configuration/Admin/FormSlugContract.php` — `evaluationSlugs()` method; dispatcher updated
- `src/Modules/Evaluations/EvaluationsModule.php` — registers `EvalCategoriesPage` handlers
- `src/Modules/Evaluations/Admin/EvaluationsPage.php` — form + view + save rewritten for hierarchy; custom fields wired
- `src/Shared/Admin/Menu.php` — added "Evaluation Categories" submenu
- `languages/talenttrack-nl_NL.po` + `.mo` — ~57 new strings (21 subcategory labels + UI chrome + 4 main-category labels + form UX strings + eval slug labels)

### Deleted
(None in this release.)

## Install

Per your earlier instruction, this release ships as a **patch ZIP** containing only new + modified files, not the full plugin tree. Extract over an existing 2.11.0 install preserving the `talenttrack/...` directory structure:

```
unzip talenttrack-2.12.0-patch.zip -d /wp-content/plugins/
# then activate/reactivate the plugin in WP admin
```

Fresh installs: still use a full plugin ZIP from the main release pipeline — the patch format only makes sense for upgrades.

On activation the sequence is:
1. `Activator::ensureSchema()` creates `tt_eval_categories` if missing (fresh installs)
2. `MigrationRunner` runs migration 0008 — copies lookup rows over, retargets ratings, seeds subcategories, deletes old lookup rows on success
3. `Activator::seedEvalCategoriesIfEmpty()` runs — no-op on sites where the migration already seeded everything; populates canonical mains + 21 subs on sites that didn't have the lookup rows at all

## Verify

1. TalentTrack → Evaluation Categories — new menu entry. Should show four main categories (Technical, Tactical, Physical, Mental) each with their subcategories listed underneath.
2. Configuration → the "Evaluation Categories" tab is gone. Navigate to `?page=tt-config&tab=eval_categories` in the URL bar and you should land on the new page.
3. Add a new evaluation for any player. Each main category should render as a fieldset with a direct-rating input and a "rate subcategories instead" link. Click the link; it swaps to sub inputs. Click "rate main category directly instead"; it swaps back.
4. Save the evaluation with Technical rated directly (4) and Tactical in subcategory mode (Offensive positioning = 3, Decision making = 5). View the evaluation.
5. Detail view should show Technical = 4 (no suffix), Tactical = 4 (averaged from 2 subcategories) — each with their sub rows listed beneath.
6. Radar chart should show all four dimensions, with Tactical using the computed 4.
7. Existing pre-2.12 evaluations should still display correctly with their direct main ratings.
8. TalentTrack → Custom Fields — new "Evaluations" tab in the entity tab bar. Add a custom field on Evaluations, insert after a native slug (say `minutes_played`), save. Edit an evaluation; the field appears in the right position.

## Scope boundaries

What's explicitly NOT in 2.12.0:

- **Drag-and-drop reorder** of categories — still deferred. Display order is a number input. Convention is to use increments of 10 so you can insert items between existing ones.
- **Deletion** of system categories — blocked by design. Use deactivate instead; stored ratings are preserved.
- **Per-category weighting** in the rollup — the compute-on-read helper returns a simple mean. Weighted averages would need a `weight` column on `tt_eval_categories` plus UX to configure them, which is Epic 2 territory.
- **Hierarchy deeper than two levels** — refused at create time. A sub-of-a-sub is not a thing in this schema.
- **Importing subcategories from a CSV / external definition file** — not in scope. Admins add them one at a time via the admin UI.

## Known follow-ups

- Historical evaluations that only have main-category ratings don't get "upgraded" to subcategory detail — and that's intentional. No backfill was designed for them (per Sprint 1I decision). If a club wants subcategory detail on an old evaluation, they re-open it and rate the subs.
- Epic 2 (statistics) will need new chart primitives for showing subcategory trends over time. Designed for, not built yet.
