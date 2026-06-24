<!-- audience: admin, dev -->

# Migrations & updates

When you update TalentTrack via the WordPress plugin updater, the plugin code changes instantly — but the database sometimes needs follow-up work: new columns, renamed tables, new capability grants, seeded data. That follow-up is a **migration**.

## Before v3.0.0

Historically you had to deactivate and reactivate the plugin after every update to trigger migrations. This was easy to forget and the symptoms of "skipped a migration" were confusing.

## v3.0.0 and later

Migrations are now a first-class admin action.

### Automatic detection

TalentTrack stores the currently-installed schema version in a WordPress option (`tt_installed_version`). On every admin page load, it compares that option to the running plugin version.

If they don't match — usually because the WP plugin updater just copied in a new version — a yellow notice appears at the top of every admin page:

> **TalentTrack schema needs updating.**
> Plugin version `3.0.0` is loaded but the installed schema is `2.22.0`. Run the migration to bring the database up to date.
> **[Run migrations now]**

Click the button. Migrations complete (usually within a second or two). The banner disappears.

### Manual trigger

You can also run migrations any time from the **Plugins** page. Next to the TalentTrack row:

`Run Migrations | Dashboard | Deactivate | Edit`

"Run Migrations" triggers the same routine. Useful if you suspect a previous run failed or want to force a cap re-grant.

## What migrations actually do

- **Schema repair** — ensure every TalentTrack table exists with the expected columns
- **Seed data** — insert default evaluation categories, functional roles, lookup values when the tables are empty
- **Capability grants** — make sure the WordPress administrator has every `tt_*` capability, and that each TalentTrack role has the caps it's supposed to
- **Self-healing** — detect known-bad states from old releases (missing columns, corrupted enum values) and repair them

All steps are **idempotent** — running migrations when nothing has changed is a no-op.

## When a migration fails (v4.20.96+)

A migration that errors (host-specific SQL restrictions, drifted schema, a bad release) no longer hides behind a success banner:

- The plugin version is **not** marked installed — the schema stays flagged pending until every migration completes.
- A **red notice** appears on every admin page listing each failed migration and its database error, with a **Retry migrations now** button.
- Automatic re-runs are suspended while a failure is recorded, so one bad migration doesn't re-execute on every page load. Retrying is always explicit: the notice button, or **Run Migrations** on the Plugins page.
- The failure list lives in the `tt_migration_failures` option and clears itself on the first clean run.

If the retry keeps failing, the error text in the notice is what your host or developer needs — it names the migration file and the exact SQL error.

## Writing migrations (v4.20.116+ standards, dev)

Three rules, enforced by review + CI:

1. **Every statement goes through `$this->exec( $sql )`** (on the `Migration` base class). The runner's fallback only reads the database's *last* error after `up()` returns — a failed statement followed by a successful one would be invisible and the migration would be marked applied half-done. `exec()` throws at the exact statement that broke, which is what the red admin notice then shows.
2. **Column adds on existing tables use `MigrationHelpers::addColumnIfMissing()`**, never `dbDelta`. dbDelta silently no-ops ALTERs when the live table has drifted from the CREATE statement — the failure class behind the v4.20.85 blueprint-columns repair. CI (`migration-lint.yml`) fails any new migration that passes a pre-existing table to dbDelta.
3. **`dbDelta` stays fine for genuinely new tables** — first creation is the case it handles well.
4. **Adding an index / UNIQUE key on an existing table is made idempotent by checking `information_schema.STATISTICS` first**, then running a guarded `ALTER … ADD … KEY` through `Migration::exec`. There's no `addIndexIfMissing` helper yet — migration `0170` (`tt_players` one-account-one-player UNIQUE, #1772) is the reference. When the index enforces a constraint that existing rows might violate (a UNIQUE over data that could contain duplicates), dedupe **before** the `ADD`, in the same migration, so the `exec` can't fail on legacy data.

## What you never need to do

- Deactivate + reactivate (old workflow, no longer required)
- Edit the database manually
- Worry about "running the wrong migration" — the system figures out what needs applying

## SaaS-readiness audit script (#0052 PR-A)

A one-shot script `bin/audit-tenancy.php` ships with the plugin to verify the SaaS-readiness scaffold added by migrations 0038 + 0039. It checks every tenant-scoped table for a populated `club_id` column, the five root entities for unique populated `uuid` values, and the `tt_config` composite primary key.

Run it via WP-CLI on the host:

```
wp eval-file wp-content/plugins/talenttrack/bin/audit-tenancy.php
```

Exit code `0` on success, `1` on failure with a per-row report. Intended as a sanity check after the migrations run; the future SaaS-migration sprint will resurrect it as part of pre-go-live validation.
