# TalentTrack v2.12.1 — Recovery release for 2.12.0's broken schema

## What was broken in 2.12.0

v2.12.0 shipped with a critical bug: the new `tt_eval_categories` table defined a column literally named `key`, which is a MySQL reserved word. I quoted it with backticks in the schema, which is valid SQL — but `dbDelta` (the WordPress schema reconciliation tool the plugin uses on every activation) parses `CREATE TABLE` statements with its own rules and **silently drops backticked reserved-word columns** on some hosts. The column never got created on the Strato install.

That alone would have been annoying. But it got worse:

- `dbDelta` on that host also attempted to reconcile the half-created table against the old `tt_lookups.eval_category` schema and inserted four garbage rows into `tt_eval_categories` with partial column population (`name` and `sort_order` from the old shape, `label` empty, `category_key` missing entirely).
- Migration 0008 then tried to run on this corrupt table and failed with `Unknown column 'key' in 'INSERT INTO'` — its throw-before-delete safeguard correctly prevented any data loss from `tt_lookups` or `tt_eval_ratings`, but the migration stayed stuck in "pending" state.
- Users on affected sites could not proceed past the 2.12.0 activation. The plugin was functional (since the old lookup rows were still intact), but no subcategories, no new admin UI, no progress.

## What 2.12.1 fixes

The column is renamed to `category_key` throughout. `category_key` is not a reserved word anywhere, so `dbDelta`, MySQL, and MariaDB all handle it consistently. The rename is the real fix — everything else is scaffolding to recover sites that already hit the broken state.

### New self-healing routine

`Activator::repairEvalCategoriesTableIfCorrupt()` runs on every activation, **before** `ensureSchema`. Flow:

1. Does `tt_eval_categories` exist? If no → return (fresh install, nothing to do).
2. Does it have the `category_key` column? If yes → return (already on the 2.12.1 schema).
3. Safety guard: refuse to drop if any `tt_eval_ratings` row references an ID in the table (would break referential integrity). Logs a WP_DEBUG warning and returns. Shouldn't happen on sites that hit the original bug — the migration throws before retargeting any ratings.
4. `DROP TABLE tt_eval_categories`. Control then returns to `activate()` which calls `ensureSchema()` next — dbDelta recreates the table with the `category_key` column cleanly.

Idempotent. No-op on healthy sites. Fully self-diagnosing.

### Schema rename

`ensureSchema()` now defines the column as `category_key VARCHAR(64) NOT NULL` with a `uniq_category_key` unique index. No backticks, no reserved words, no dbDelta quirks.

### Migration 0008 updated

Every INSERT and SELECT inside migration 0008 now references `category_key`. The migration runs cleanly against the 2.12.1 schema once the repair routine has dropped the corrupt table.

### Repositories updated

- `EvalCategoriesRepository` — `getByKey()`, `update()` lock list, and `normalize()` all reference `category_key`. `normalize()` accepts either `'category_key'` (canonical) or the legacy `'key'` array-key on insert for backward compatibility.
- `EvalRatingsRepository::getForEvaluation()` — SELECT aliases the column as `c.category_key AS category_key` (alias for consumer consistency — rating rows still expose `->category_key`).
- `QueryHelpers::get_evaluation()` — same alias update in the join.

### Admin UI updated

- `EvalCategoriesPage` list view — displays `$main->category_key` and `$sub->category_key` in the code column.
- Edit form — field `name="category_key"`, save handler reads `$_POST['category_key']`, create call passes `'category_key' =>` to the repository.

### Seed routine updated

`Activator::seedEvalCategoriesIfEmpty()` now writes `category_key` (two loops: main categories, subcategories). Still idempotent.

## Upgrade path for your specific site

Because your site is in exactly the corrupted state this release is designed to recover:

1. Extract the patch ZIP over `/wp-content/plugins/talenttrack/` (preserve tree structure).
2. Deactivate the plugin in WP admin. Reactivate.
3. On reactivation:
   - `repairEvalCategoriesTableIfCorrupt()` detects the missing `category_key` column and the non-referencing state of the 4 corrupt rows, and drops the table.
   - `ensureSchema()` recreates the table with the correct `category_key` column.
   - `seedEvalCategoriesIfEmpty()` seeds 4 main + 21 sub rows into the fresh table.
   - Migration 0008 runs successfully: finds the `tt_lookups.eval_category` rows, copies them (key collision detection will make them idempotent against the already-seeded rows), retargets the 28 `tt_eval_ratings` rows, and deletes the old `tt_lookups` rows.
4. Verify by checking the Migrations admin page — no more "pending" for 0008.
5. Verify by opening TalentTrack → Evaluation Categories — should show Technisch/Tactisch/Fysiek/Mentaal with their subcategories underneath.
6. Verify by opening an existing evaluation — ratings should still display (they'll appear in the "direct main rating" mode since they were entered pre-2.12).

## Upgrade path for fresh installs or sites that never hit the bug

Install 2.12.1 directly. `repairEvalCategoriesTableIfCorrupt()` is a no-op for you. Everything works as it did in intended 2.12.0.

## Files in this release

### Modified
- `talenttrack.php` — version 2.12.1
- `src/Core/Activator.php` — column rename in `ensureSchema`; `repairEvalCategoriesTableIfCorrupt()` added; `seedEvalCategoriesIfEmpty()` updated to use the new column name; `activate()` order updated
- `src/Infrastructure/Evaluations/EvalCategoriesRepository.php` — `getByKey()`, `update()`, `normalize()` updated; docblocks refreshed; `normalize()` accepts both old and new data-array key names
- `src/Infrastructure/Evaluations/EvalRatingsRepository.php` — column reference updated in `getForEvaluation()` SELECT
- `src/Infrastructure/Query/QueryHelpers.php` — column reference updated in `get_evaluation()` JOIN
- `src/Modules/Evaluations/Admin/EvalCategoriesPage.php` — list view, edit form, and save handler all updated for the new column/property name
- `database/migrations/0008_eval_categories_hierarchy.php` — `ensureCategoriesTable()`, `copyMainCategoriesFromLookups()`, `seedSubcategories()` all updated

### New
- (none — this is a pure recovery release, no new files)

### Deleted
- (none)

No new translations — the column rename is internal and doesn't surface any new UI strings.

## Post-mortem notes

This is the second time in this plugin's history that a schema change got burned by `dbDelta`'s quirks (the first was the migration-loader eval() issue in v2.10.1). Different problem each time, same root cause: `dbDelta` is much less permissive than raw MySQL and much more permissive than its own documentation suggests.

Lessons for next time:

1. **Never use MySQL reserved words as column names.** Even backticked. Even when the documentation suggests it'd be fine. The word list is short and avoidable. A 30-second `reserved-word grep` against any new `CREATE TABLE` statement would have caught this at author-time.
2. **`dbDelta` silently drops columns it doesn't like.** It doesn't throw, doesn't warn, doesn't log. The only safe way to know a `dbDelta`-created table matches the expected schema is to verify column presence explicitly after activation. The `repairEvalCategoriesTableIfCorrupt()` routine does this now, but it'd be better to not need it.
3. **Mid-release schema changes need end-to-end smoke tests on multiple hosts.** 2.12.0 was tested on a fresh install where everything worked. The real-world path (upgrade from 2.11.0, existing data, existing `tt_lookups` rows, migration 0008 running against a dbDelta-created table) was never exercised before release. Not good. Future schema-changing sprints will include an upgrade-from-previous test run.

No data was lost. The safeguards built in 2.10.1 (migration throws on partial failure, no deletion before retargeting succeeds) paid for themselves this time.
