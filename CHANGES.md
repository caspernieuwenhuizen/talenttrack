# TalentTrack v2.10.1 — Migration loader fix + self-healing backfill

## What was wrong

Two unrelated issues, both real, both biting on at least one pilot site (a Strato-hosted install).

### 1. Migration 0006 marked applied but did nothing

Sprint 1G's data migration, `0006_functional_role_backfill`, was in `tt_migrations` with a successful `applied_at` timestamp, but the `tt_team_people` rows it was supposed to fill in still had `functional_role_id = NULL`. As a result, no team assignments showed up on the Functional Roles page, and the Roles & Permissions detail pages showed "no assignments" for every team-scoped auth role despite people being clearly assigned to teams.

The root cause was in `MigrationRunner::loadMigrationFromFile()`. Since v2.6.5 the runner had been using `eval()` to sidestep PHP's per-request include-once tracking. The rationale at the time was reasonable, but it had a flaw I hadn't caught: **`use` statements at the top of an eval'd string are silently ignored**. PHP processes `use` at compile time when parsing a file; eval'd code runs in the global namespace regardless of any `namespace` declaration in the string.

That meant `use TT\Infrastructure\Database\Migration;` never created the alias, and `return new class extends Migration { ... }` resolved `Migration` to `\Migration` instead of `\TT\Infrastructure\Database\Migration`. On some PHP versions/configurations this throws outright; on others (apparently including the affected host) it produces an object whose class hierarchy doesn't satisfy `instanceof Migration`, causing the runner to take an alternate code path that called `up($wpdb)` instead of `up()`. Inside `up()`, `$wpdb->update` either silently no-op'd or failed in a way that the runner didn't detect because it only checked `$wpdb->last_error` — not the update's return value.

The migration got marked applied (because `up()` returned cleanly), the rows were never populated, and the whole thing failed silently.

This had been masked for all previous migrations because the Activator pre-marks migrations 0001–0004 as applied in `markMigrationsApplied()`, so the runner never actually loaded them through the eval path. Migration 0006 was literally the first migration to go through `MigrationRunner::runFile()` end-to-end on upgraded sites.

### 2. Orphan `0005_authorization_rbac` migration record

Sprint 1F (v2.9.0) pre-marked `0005_authorization_rbac` as applied but never shipped the corresponding file. The migrations admin page correctly flagged this with an "applied but file missing" warning, which has been cosmetically visible on every v2.9.x install. Harmless, but noisy.

## The fix

### Migration loader

`MigrationRunner::loadMigrationFromFile()` no longer uses `eval()`. It now `include`s the file inside a closure, which gives us an isolated variable scope *and* proper handling of `use` statements. The original "PHP's include tracking" concern was moot anyway: `MigrationRunner::run()` filters out applied migrations before calling `runFile`, so no file is ever included twice in the same request.

### Migration 0006 hardening

Every `$wpdb->update()` call in the backfill now:

- Passes explicit `%d` format hints for both the SET column and the WHERE clause
- Checks the return value for `false` and collects failed row IDs
- Throws a `RuntimeException` at the end of Step 1 if any row failed, which prevents `MigrationRunner` from marking the migration applied on partial failure

### Self-healing repair routine

A new `Activator::repairFunctionalRoleBackfill()` runs on every activation after the migration runner. It scans `tt_team_people` for rows with `role_in_team IS NOT NULL AND functional_role_id IS NULL` and fills them in directly — no migration system involved. Fully idempotent: if the migration already ran correctly or an earlier run of this routine fixed things, it does nothing.

This is the belt to the migration's suspenders. Any site that got stuck with empty `functional_role_id` columns self-heals on upgrade to 2.10.1. Future analogous states (say, a team_people row is somehow inserted directly into the DB without `functional_role_id`) also converge.

### Orphan migration record cleanup

`Activator::markMigrationsApplied()` no longer adds `0005_authorization_rbac` to the pre-applied list. A new `Activator::cleanupOrphanMigrationRecords()` deletes the leftover row from `tt_migrations` on existing sites. Idempotent. The "file missing" warning disappears on next activation.

### Cosmetic: Assignments column dropped from Roles & Permissions list

The Roles & Permissions list page had an "Assignments" column showing `COUNT(*) FROM tt_user_role_scopes`. After Sprint 1G, most auth roles receive their assignments via functional-role mapping (not direct grants), so that column was showing "0" for roles that clearly have assignments visible on the detail page. Rather than complicate the count with indirect grants (where "one person on two teams" is ambiguous between 1 and 2), the column is removed. The detail page remains the authoritative place to see who holds a role and why.

The Functional Roles list page keeps its Assignments column — unambiguous there: one count per functional role, one row per `tt_team_people` assignment.

## Files in this release

### Modified
- `talenttrack.php` — version 2.10.1
- `src/Infrastructure/Database/MigrationRunner.php` — replaced eval-based loader with include-inside-closure; class docblock rewritten
- `database/migrations/0006_functional_role_backfill.php` — explicit `%d` formats, return-value checks, throw on partial failure
- `src/Core/Activator.php` — added `repairFunctionalRoleBackfill()` and `cleanupOrphanMigrationRecords()`, added `columnExists()` helper, removed `0005_authorization_rbac` from `markMigrationsApplied`'s `$to_mark` list
- `src/Modules/Authorization/Admin/RolesPage.php` — removed Assignments column + its colspan adjustment in the empty-state row

No new files. No schema changes. No new translations (everything user-facing stays in existing strings).

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting.
2. Commit, push, tag `v2.10.1`, release.
3. Deactivate and reactivate the plugin. On reactivation, `repairFunctionalRoleBackfill()` runs and fills in any missing `functional_role_id` values, and `cleanupOrphanMigrationRecords()` removes the 0005 orphan row.

## Verify

1. TalentTrack → Migrations — no more "applied but file missing" warning for 0005.
2. If you were seeing empty Assignments columns on the Functional Roles page before this release: they now show the real counts. Functional Roles → Head Coach detail page lists the head coaches assigned to any team.
3. Roles & Permissions → Head Coach detail — the indirect-grant rows (Source = "via Head Coach") are populated.
4. Permission Debug → pick a head coach WP user — they resolve to a head_coach scope at team scope with Source = "Via functional role" + "via Head Coach".
5. Roles & Permissions list — Assignments column is gone.

## Post-mortem notes for the team

- **eval() was the wrong tool here, even though the original rationale was defensible.** The include-tracking issue it was designed to solve didn't apply to the actual usage pattern. Lesson: when reaching for eval, verify that the justifying constraint is actually binding in practice.
- **Migration 0004 "worked" in production only because it was never actually run by the runner** — it was pre-marked applied by the Activator. This meant the eval bug had been latent for a year and a half. When we finally shipped a migration (0006) that had to go through the runner for real, the bug surfaced immediately.
- **The MigrationRunner's success check (`$wpdb->last_error`) was inadequate.** A silent zero-row UPDATE doesn't set `last_error`. 2.10.1's 0006 now checks the actual `$wpdb->update` return, which is the right pattern for any future data-migrating migration.
- **The self-healing routine is over-engineered if we trust the migration fix alone** — but it's a small amount of code that costs little and gives us robustness against analogous future states. Worth keeping.
