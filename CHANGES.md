# TalentTrack v2.6.6 — Schema reconciliation, done properly

## What changed in approach

v2.6.2 through v2.6.5 all tried to fix a broken schema by running a dynamically-loaded migration file at runtime. Each iteration added a new layer (admin page, dual-pattern support, closure isolation, eval()) to work around a new silent-failure mode. v2.6.5 finally caused a critical error. Enough.

v2.6.6 abandons the file-based migration approach for this specific fix. The schema reconciliation now happens **directly inside `Activator::activate()`** using `dbDelta` — WordPress's native, battle-tested, non-destructive schema reconciliation tool that has been running reliably on millions of sites for a decade.

## Why this is better

- **dbDelta** compares your current schema to a desired schema and applies only the differences (creates missing tables, adds missing columns). Idempotent, non-destructive, preserves data.
- **`register_activation_hook`** runs in a fresh PHP execution triggered by WordPress's own plugin activation flow. No include-cache state from the main boot. No dynamic file loading. No eval.
- **The migration system stays in place** for future releases, where it's the right tool. Just not for retrofitting a broken install.

## What this release does on your site (in order)

1. Installs/refreshes the custom roles (unchanged).
2. Runs `dbDelta` with the complete, correct schema for all TalentTrack tables. On your site, this will add the missing columns to `tt_evaluations`, `tt_attendance`, and `tt_goals`. Nothing is dropped; existing data is preserved.
3. Runs explicit `ALTER TABLE` on `tt_evaluations.category_id` and `tt_evaluations.rating` to make them nullable (dbDelta can't modify NULL-ability of existing columns).
4. Backfills `tt_attendance.status` from the legacy `present` column where status is blank.
5. Records migrations 0001-0004 as applied in `tt_migrations` so the runtime migration runner has nothing to do and can't cause trouble.
6. Flushes rewrite rules.

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` overwriting existing files. (Your folder name, not `talenttrack-2.6.6`.)
2. Commit, push, tag `v2.6.6`, release on GitHub.
3. WordPress updates (auto-update or manual — either works).
4. **Plugins page → Deactivate TalentTrack → Activate TalentTrack.** This is the one manual step that triggers the reconciliation. Your data is fine — all data is in the database.
5. Done.

## Verify

Run these SQL queries:

```sql
SELECT * FROM z06x_tt_migrations ORDER BY id DESC LIMIT 6;
```
You should see 4 rows: 0001, 0002, 0003, 0004. If 0004 is there, reconciliation ran.

```sql
DESCRIBE z06x_tt_evaluations;
```
Should show `eval_type_id`, `opponent`, `competition`, `match_result`, `home_away`, `minutes_played`, `updated_at` as columns. The legacy `category_id` and `rating` should show `Null: YES`.

```sql
DESCRIBE z06x_tt_attendance;
```
Should show `status` alongside any legacy `present` column.

```sql
DESCRIBE z06x_tt_goals;
```
Should show `priority`.

Then functional test: create a new evaluation in admin. Save. Should succeed. Row should appear in DB and on player dashboard.

## Files in this release

- `src/Core/Activator.php` — the only meaningful change. Now contains the full schema authoritatively plus legacy-relaxation logic.
- `talenttrack.php` — version bump only.
- `readme.txt` — stable tag + changelog.

## Files explicitly REMOVED

- `database/migrations/0004_schema_reconciliation.php` — no longer needed; its job is now done by Activator. If this file exists on your server from a prior install, it's harmless (migrations 0001-0004 are marked as applied, so the runner will skip it).

## What's unchanged from v2.6.3

- Migrations admin page (TalentTrack → Migrations) — still there for future releases
- MenuExtension with pending-migration warning — still there
- MigrationRunner class — still there, just not needed for this fix
- All modules, dashboards, REST endpoints, auth, custom fields, etc.
- The v2.6.2 fail-loud save handlers (if those are already on your site from previous installs)

## If something goes wrong

If activation fails or produces a white screen, you can deactivate the plugin via FTP by renaming the folder from `talenttrack` to `talenttrack-disabled`. That reactivates WordPress. Then share the error message from the WP admin email ("Your Site is Experiencing a Technical Issue") and we'll debug.

But I'm confident this one works because:
- `dbDelta` is the standard WordPress tool for this exact job
- It's executed in `register_activation_hook`, the cleanest execution context there is
- No dynamic file loading, no eval, no closures, no clever tricks
- I've listed every table's complete schema explicitly in Activator, matching what QueryHelpers and the REST controllers expect

## Six iterations, one lesson

Each of v2.6.2–v2.6.5 tried to solve "how do we ship a schema change" with progressively clever code. The right answer was always "use WordPress's built-in tool, triggered by WordPress's built-in activation flow." Fancy was the enemy of working.

The migration system is still a good design for the future — versioned, trackable, rollback-friendly, admin-page-auditable. It was just the wrong tool for this particular rescue job.
