# TalentTrack v2.6.3 — Migrations Admin Page

## Why this release exists

After installing v2.6.2, migration `0004_schema_reconciliation` never ran. Your `z06x_tt_migrations` table still only had migrations 0001-0003 recorded.

Investigation revealed the cause: **v2.6.2's migration 0004 used a simpler code pattern** (`return new class { public function up(\wpdb $wpdb) {...} }`) than the existing migrations (`return new class extends Migration {...}`). The MigrationRunner had a strict check — if the file's return value wasn't an instance of the `Migration` base class, it silently skipped it.

Double irony: the whole point of v2.6.2 was to stop silent failures. But the migration runner itself had exactly the same "silent skip" pattern as the admin save handlers.

v2.6.3 fixes the runner AND gives you the admin UI you asked for, so this class of thing stops being invisible going forward.

## What's in this release

### The Migrations admin page
New menu item under TalentTrack → **Migrations**. Shows:
- Every migration file shipped with the plugin
- Its status: ✓ applied (with timestamp) or ⏳ pending
- A "Run" button per pending migration
- A "Run All Pending Migrations" button when multiple are pending
- Diagnostic info: migrations directory path, tracking table status, plugin version
- Clear error messages when a migration fails (the actual DB error, not a generic message)

### Dashboard warning
Every TalentTrack admin page shows a yellow warning banner when migrations are pending, with a one-click button to the Migrations page. The menu item also gets a red badge with the pending count. Hard to miss.

### MigrationRunner fix
The runner now accepts **two** migration patterns:
1. Classic: `return new class extends Migration {...}` (existing migrations 0001-0003)
2. Simple: `return new class { public function up(\wpdb $wpdb) {...} }` (v2.6.2+ migrations)

Plus, it now captures `$wpdb->last_error` during migration execution, so SQL errors that don't throw PHP exceptions are still caught and surfaced.

### Migration 0004 bundled
Since your site never got 0004 applied, it's included in this delivery. After install, the Migrations page will show it as pending; click Run; verify.

## Install

1. Extract the ZIP, copy contents into your local `talenttrack/` folder, commit, push.
2. Tag `v2.6.3` on GitHub, create a release.
3. WordPress auto-updates (or manual install if auto-update fails — no loss of data either way).
4. Navigate to **TalentTrack → Migrations**.
5. You should see `0004_schema_reconciliation` listed with status ⏳ Pending.
6. Click the **Run** button next to it.
7. You should see a green success notice: "Migration 0004_schema_reconciliation applied successfully in Xms."
8. The same row should now show ✓ with the current timestamp.

## Verification

### SQL verification (same as before)

```sql
SELECT * FROM z06x_tt_migrations ORDER BY id DESC LIMIT 5;
```

Now you should see 4 rows including `0004_schema_reconciliation`.

```sql
DESCRIBE z06x_tt_evaluations;
```

Should now show `eval_type_id`, `opponent`, `competition`, `match_result`, `home_away`, `minutes_played`, `updated_at`.

### Functional verification

- Go to TalentTrack → Evaluations → Add New. Fill in the form. Save.
- Should succeed; the evaluation should appear in the list AND on the player dashboard.
- Same for Sessions and Goals.

## If something goes wrong

The Migrations page surfaces errors verbatim. If you click Run and something fails, you'll get a red box with the actual MySQL error message. Send me that message and I'll know exactly what to fix.

## Files in this release

### New
- `src/Infrastructure/Database/MigrationRunner.php` — rewritten with runOne, inspect, dual-pattern support, error capture
- `src/Modules/Configuration/Admin/MigrationsPage.php` — the admin UI
- `src/Shared/Admin/MenuExtension.php` — adds Migrations submenu + dashboard warning without touching existing Menu

### Carried forward from v2.6.2 (in case your site never got them)
- `src/Infrastructure/Database/MigrationHelpers.php` — column/index existence helpers
- `database/migrations/0004_schema_reconciliation.php` — the schema reconciliation migration

### Modified
- `talenttrack.php` — version bump to 2.6.3 + one line to init MenuExtension
- `readme.txt` — stable tag + changelog

### Translations
- `languages/talenttrack-nl_NL.po` + `.mo` — Dutch strings for the Migrations page and warning banner

## Unchanged
- All existing migrations 0001-0003 (still applied on your site)
- All existing admin pages (Players, Evaluations, Sessions, Goals, Configuration, etc.)
- Frontend dashboards, REST API, auth, roles, module system
- The v2.6.2 fail-loud save handlers (if you already applied v2.6.2, they remain in place)

## What this teaches us

Both bugs in this stack came from the same bad pattern: **detect a condition we can't handle, then silently continue as if nothing happened.** The admin save handlers did it (silent insert failures → "Saved." message → empty DB). The migration runner did it (silent instanceof mismatch → no migration applied → user has no idea).

v2.6.2 fixed the save handlers. v2.6.3 fixes the migration runner AND adds UI so you can see what the system thinks is true. Going forward: no more invisible state.

## Next sprint

With v2.6.3 landed and 0004 finally applied, the backlog stands where it was:
- Parent role views
- Visual form designer (parked)
- More REST endpoints
- UX polish
- Match-day attendance sheets
- Player portfolio PDF export

Confirm v2.6.3 works, run 0004, then we pick the next direction.
