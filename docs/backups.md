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

## What's not in v1

The Sprint 2 follow-up adds:
- **Partial restore with diff view** — pick specific records, see green/yellow/red diff against current state, with dependency closure.
- **Pre-bulk auto-backup** — automatic safety snapshot before any operation deleting/archiving more than 10 rows.
- **Undo shortcut** — the admin notice after a bulk operation includes a one-click "Undo via backup" link valid for 14 days.

S3, Dropbox, GDrive, and SFTP destinations are not in this release; the destination interface is in place so they're a one-class-each addition when the time is right.
