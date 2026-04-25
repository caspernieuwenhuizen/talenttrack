# Backups

TalentTrack ships its own backup module (separate from any general-purpose WordPress backup plugin you may also be running). Snapshots cover the plugin's own `tt_*` tables only — not WordPress users, not media uploads. The point is to give you a fast restore path for academy data without dragging the rest of the site along.

## Where to find it

`Configuration → Backups`. Visible to administrators and the **Head of Development** role; the underlying capability is `tt_manage_backups`.

## Settings

- **Preset** — *Minimal* (core operational data), *Standard* (everyday data including sessions/goals/people), *Thorough* (everything including audit log + lookups), or *Custom* (per-table list).
- **Schedule** — daily, weekly, or on-demand (no automatic runs).
- **Retention** — how many local backups to keep before purging the oldest. Default 30.
- **Local destination** — writes `.json.gz` files to `wp-content/uploads/talenttrack-backups/`. The directory is auto-created with an `index.php` + `.htaccess` blocking direct browser access.
- **Email destination** — wp_mail() each backup to a comma-separated recipient list. Files larger than 10 MB are too big for most mail servers; in that case the email is a notice and the backup is stored locally only.

## Run a backup now

A "Run backup now" button on the settings page bypasses the schedule. Useful for:
- Testing your settings end-to-end.
- Just before a risky operation (a CSV import, a bulk archive).
- Sites where WP-cron is unreliable (low traffic, aggressive caching).

## Restoring

1. Pick a backup from the local list and click **Restore**.
2. The page shows a per-table summary of what will be replaced and the snapshot's plugin version.
3. Type **RESTORE** in the confirmation field.
4. The action truncates each table in the snapshot and replays the rows. Tables present on disk but missing from the snapshot are not touched.
5. If row counts don't match expectations after replay, an error surfaces.

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

## What's still deferred

S3, Dropbox, GDrive, and SFTP destinations are not in v1; the destination interface is in place so each is a one-class addition when the time is right (likely bundled with #0011 monetization as a Pro-tier feature).
