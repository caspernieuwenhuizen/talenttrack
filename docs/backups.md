<!-- audience: admin -->

# Backups

TalentTrack ships its own backup module (separate from any general-purpose WordPress backup plugin you may also be running). Snapshots cover the plugin's own `tt_*` tables only — not WordPress users, not media uploads. The point is to give you a fast restore path for academy data without dragging the rest of the site along.

## Where to find it

`Configuration → Backups` (the frontend view at `?tt_view=backups`). Visible to administrators and the **Head of Development** role; the underlying capability is `tt_manage_backups`.

## Frontend view (#1937)

From this release the Backups surface is a frontend view at `?tt_view=backups` — no wp-admin bounce. It covers settings, the stored-backups list (download / restore / delete), Run now, and the full `.ttmig` data migration export + import flow. Each action runs through a capability-gated, nonce-protected REST endpoint (`tt_manage_backups`); the two destructive writes — full restore and migration import — keep the typed-confirmation gate ("RESTORE" / "IMPORT"), refuse to run while you are impersonating another user, and are written to the audit log (`backup.restored` / `migration.imported`).

Backup downloads come back as a URL (object-storage-ready), so the list keeps working unchanged if the storage backend moves off the local filesystem in a future SaaS deployment.

The wp-admin Backups tab (`?page=tt-config&tab=backups`) stays as the power-user fallback and still owns the **Partial restore** scope-picker (a Standard+ licensed two-step diff flow); the frontend list links to it.

## Settings

- **Preset** — *Minimal* (core operational data), *Standard* (everyday data including sessions/goals/people), *Thorough* (everything including audit log + lookups), or *Custom* (per-table list). The description below the dropdown updates automatically as you change selection.
- **Schedule** — daily, weekly, or on-demand (no automatic runs).
- **Retention** — how many local backups to keep before purging the oldest. Default 30.
- **Local destination** — writes `.json.gz` files to `wp-content/uploads/talenttrack-backups/`. The directory is auto-created with an `index.php` + `.htaccess` blocking direct browser access.
- **Email destination** — wp_mail() each backup to a comma-separated recipient list. Files larger than 10 MB are too big for most mail servers; in that case the email is a notice and the backup is stored locally only.

## Run a backup now

A "Run backup now" button on the settings page bypasses the schedule. Useful for:
- Testing your settings end-to-end.
- Just before a risky operation (a CSV import, a bulk archive).
- Sites where WP-cron is unreliable (low traffic, aggressive caching).

While the backup runs, a full-screen "Backup in progress…" overlay covers the page. It can't be dismissed — when the server finishes (usually a few seconds for small academies, longer for Thorough on a busy install) the page reloads and the overlay is gone.

## Restoring

1. Pick a backup from the local list and click **Restore**. A confirmation dialog asks you to acknowledge that you're entering the restore preview.
2. The page shows a per-table summary of what will be replaced and the snapshot's plugin version.
3. Type **RESTORE** in the confirmation field.
4. Submit. The same "in progress…" overlay covers the page during the restore — non-dismissible until the server completes.
5. The action truncates each table in the snapshot and replays the rows. Tables present on disk but missing from the snapshot are not touched.
6. If row counts don't match expectations after replay, an error surfaces.

Cross-major-version restores are rejected (a v2.x snapshot won't restore on a v3.x site). Same-major (e.g. v3.12 → v3.14) is allowed; schema migrations handle differences.

## Health indicator

The wp-admin TalentTrack dashboard surfaces a small notice with the backup state:

- **Green** — last successful run within 24 hours.
- **Yellow** — last successful run between 1 and 7 days ago, or no run yet but a schedule is configured.
- **Red** — last run failed, or > 7 days stale, or no destination is enabled.

## File format

Each backup is a gzipped JSON document with:

```json
{
  "version":        "1.0",
  "plugin_version": "3.15.0",
  "created_at":     "2026-04-25T22:00:00Z",
  "preset":         "standard",
  "tables":   { "tt_players": { "columns": [...], "rows": [...] }, ... },
  "checksum": "sha256-..."
}
```

The checksum is computed over the `tables` subtree only — restore verifies it before touching the database.

## Partial restore (v3.16.0+)

Click **Partial restore** on any stored backup to bring back specific rows without replacing everything. The flow:

1. **Choose scope** — pick a table from the backup and either a comma-separated list of row IDs or leave the IDs empty to include every row of that table. Optionally tick child tables to follow downward (e.g. start from a player and bring along their evaluations).
2. **Review diff** — for each table in the resolved closure, see how many rows are *new* (in backup, not currently in DB) and how many *differ*. Pick an action per table:
   - Green rows: **Restore** or **Skip**.
   - Yellow rows: **Keep current**, **Overwrite with backup**, or **Skip**.
3. **Execute** — submits the chosen actions. Tick **Dry run** first if you want to compute the changes without writing.

The dependency map is small: it covers players, teams, evaluations, ratings, sessions, attendance, goals, people, team-people, functional roles, custom values, and category weights. Adding a table is a one-row entry in `BackupDependencyMap::refs()`.

## Pre-bulk safety + undo (v3.16.0+)

Before any wp-admin bulk action that *archives* or *permanently deletes* more than 10 rows, TalentTrack takes an automatic safety snapshot. The snapshot is a regular backup tagged in metadata so retention can be tuned separately.

Right after the bulk operation finishes, an admin notice appears with an **Undo via backup →** link. The link runs a partial restore against the safety snapshot, scoped to exactly the rows that were affected. The notice stays for 14 days; click **Dismiss** to consume it without restoring.

The 10-row threshold is filterable via `tt_backup_bulk_safety_threshold`.

## Data migration — export (v4.21.14+)

To move data to a **different** TalentTrack install, use the **Data migration** section on the Backups page. Tick the data sets to include (Players, Teams, Staff & roles, Evaluations, Activities & attendance, Goals, Lookups & configuration) and click **Export for migration** to download a `.ttmig` archive — gzipped JSON, the same envelope as a backup, stamped `kind: migration`.

Export is data-only: WordPress users and media are not included. Cross-install user links (`wp_user_id`) are resolved at import time, not carried in the file.

### Leaving individual records behind (v4.26.8+)

Beyond the per-data-set checkboxes, each record-bearing set (Players, Teams, Staff & roles, Evaluations, Activities & attendance, Goals) has a **Show N records** expander. Every record is included by default; untick the ones you want to leave behind — handy for dropping test players or scratch records before migrating to a clean install. Excluding a record also drops its child rows in the same set (e.g. excluding an activity drops its attendance rows). "Lookups & configuration" stays all-or-nothing, as it is reference data rather than test records.

If you exclude a record that another included set still references — for example, excluding a player while keeping their evaluations — a confirmation step lists those orphaned dependents before the download. You can **Download anyway** (the dependents export without their referenced record) or cancel and adjust your selection. Very large sets show only the first 500 records in the expander; records beyond that are always included.

## Data migration — import preview (v4.32.1+)

On the target install, the **Import from another install** subsection (just below the export controls) accepts a `.ttmig` file. Choose the archive and click **Preview import** to inspect it. This step is **read-only** — it validates the file and reports what it carries, but changes nothing.

The preview shows:

- **Validation** — the file must decode, be stamped `kind: migration`, and pass its checksum (`sha256` over the data tables). A corrupted or edited archive is rejected. An archive from a different major version still opens but shows a compatibility warning.
- **Contents** — row counts per data set (Players, Teams, Staff & roles, Evaluations, Activities & attendance, Goals, Lookups & configuration).
- **What would happen on import** — for the record sets with a natural key (Players matched on first name + last name + date of birth, Teams on name + age group, Staff on first name + last name + email), how many incoming records **match an existing record** on this install versus how many are **new**. Matching is by stable key, not by id — ids differ between installs, so a source id of 5 is not the target's record 5.

## Data migration — applying an import (v4.36.0+)

From the preview, **Configure import** lets you apply the archive to this install:

1. **Choose data sets** — pick which record groups to import (Players, Teams, Staff & roles, Evaluations, Activities & attendance, Goals). Lookups & configuration are **not** imported; they are used only to match references (see below).
2. **Resolve matches** — for each record set where an incoming record matches an existing one on its stable key, choose **Insert as new** (default — keep both) or **Update the existing record**.
3. **Link WordPress users** — records that referenced a user on the source install are listed with a suggested target user (matched by email); confirm, pick another, or leave unlinked.
4. **Dry run** — produces a per-table count of what *would* be inserted / updated / skipped. **Nothing is written during the dry run.**
5. **Confirm** — type `IMPORT` and apply. The write runs inside a database transaction and rolls back completely if any row fails, so a partial import never lands.

How references are handled:

- **Source ids are never preserved.** Every imported row is inserted as new; the importer records an old→new id map per table and rewrites foreign keys (via the dependency map) to the new ids before each write — so an imported evaluation points at the imported player, not at whatever record happened to hold that id on the target.
- **`club_id`** is rewritten to the current club; **`wp_user_id`** is set from your user mapping (unmapped → unlinked).
- **References into Lookups & configuration** (e.g. an evaluation's type, a rating's category) are matched by stable key to the equivalent entry already on this install. If the target has no matching entry, the row imports without that link and a warning is shown — set up the configuration first if you need those links.

Uploads are capped at 25 MB. Importing custom-field *values* is not yet covered (the records themselves import; their custom values do not).

## What's still deferred

S3, Dropbox, GDrive, and SFTP destinations are not in v1; the destination interface is in place so each is a one-class addition when the time is right (likely bundled with #0011 monetization as a Pro-tier feature).
