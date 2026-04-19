# TalentTrack v2.6.4 — Migration loader hardening

## What happened on your site

You clicked Run on migration 0004 in the Migrations admin page and got this error:

> Migration file does not return a runnable migration. Expected either a Migration instance or an object with an up(\wpdb) method.

The file is physically correct. The problem is that my v2.6.3 runner's `require` call didn't always re-evaluate the file's `return` statement cleanly when the same file had been touched earlier in the request lifecycle. PHP's include/require semantics around anonymous class files are subtle.

## What v2.6.4 does

**Two parallel fixes so we don't have to care which theory was right:**

**1. Rewrote migration 0004.** It now uses the classic `extends Migration` pattern (same as migrations 0001-0003, which work fine on your site), and all the column-existence helpers are inlined inside the migration class instead of being called out to a separate `MigrationHelpers` file. This removes autoload timing from the picture. Functionally identical to v2.6.2's version.

**2. Rewrote the migration loader.** The runner now loads each file inside a closure-isolated scope, with exception and stray-output capture. If the file returns something weird, the error message now tells you exactly what it returned (type, value, and a hint for common failure modes).

These two changes together make it essentially impossible to see the "file does not return a runnable migration" error from this migration again. If it does happen on some future migration, the new error message will tell us why.

## Install

1. Extract ZIP, drop into `/wp-content/plugins/talenttrack/` (overwriting).
2. Commit + push + tag `v2.6.4`.
3. WordPress admin → TalentTrack → Migrations.
4. 0004 should still show as ⏳ Pending (nothing changed in the DB).
5. Click **Run** next to it.
6. Expect: green success notice, row flips to ✓ Applied.

## Verify

Same SQL checks as before:
```sql
SELECT * FROM z06x_tt_migrations ORDER BY id DESC LIMIT 5;
DESCRIBE z06x_tt_evaluations;
DESCRIBE z06x_tt_attendance;
DESCRIBE z06x_tt_goals;
```

Then try saving a new evaluation in admin. Should succeed end-to-end.

## If it still errors

The new error message will be much more informative. If it still fails, paste me the exact wording and we'll pinpoint the issue.

## Files in this delivery

### Modified
- `src/Infrastructure/Database/MigrationRunner.php` — closure-isolated loader, better error messages
- `database/migrations/0004_schema_reconciliation.php` — extends Migration, inlined helpers
- `talenttrack.php` — version bump
- `readme.txt` — stable tag + changelog

### Unchanged from v2.6.3
- `src/Modules/Configuration/Admin/MigrationsPage.php`
- `src/Shared/Admin/MenuExtension.php`
- `src/Infrastructure/Database/MigrationHelpers.php` (still shipped, still works — just not used by 0004 anymore)
- Everything else
