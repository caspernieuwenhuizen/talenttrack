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

## What you never need to do

- Deactivate + reactivate (old workflow, no longer required)
- Edit the database manually
- Worry about "running the wrong migration" — the system figures out what needs applying
