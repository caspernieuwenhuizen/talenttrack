<!-- type: epic -->

# Backup and disaster recovery — scheduled exports, destinations, partial restore

Raw idea:

Application should have some kind of default backup setting but admin can choose between more or less. Output of backups for now stay on site but are in files or perhaps can be sent to emails or cloud service. Should be only data — to avoid a club losing all data after a problem, or to revert back to a day on which a backup was made when an admin deleted 100 evaluations instead of 10. Ideally the restore functionality should allow partial restores but of course taking integrity into account.

## Why this is an epic

A full backup + restore system touches every data table, needs scheduled jobs, serialized export format, destination adapters (filesystem / email / cloud), a restore UI that respects foreign-key integrity, and a partial-restore mode that merges old data into a live database without clobbering the week of work that happened since the backup. Minimum 3–4 sprints.

## What the plugin already has (important — this shapes the design)

Before spec-ing backup, three existing features already partially cover what backup is usually used for. Backup should *complement* these, not duplicate them:

- **Soft archive** (migration `0010_archive_support`). `archived_at` + `archived_by` on every major table (players, teams, evaluations, sessions, goals, people). An "archive" is a soft-delete — the row stays in the DB, queries filter it out. This already handles most "oh no I archived the wrong thing" cases today.
- **Audit log** (migration `0002_create_audit_log`, table `tt_audit_log`). Records user actions. Depending on what it captures, it may already be enough to *tell you* what was deleted when — just not enough to *bring it back* if it was a hard delete.
- **Database migrations** (`database/migrations/`). Schema is versioned and idempotent. Recreating the schema on a new install is solved; the problem is bringing the *data* with it.

This is why the raw idea is specifically about **data**, not the whole plugin. The code and schema can be reinstalled from a release. What a club can't get back is the 2,000 evaluation records they painstakingly entered over the season.

Critically, the "admin deleted 100 evaluations instead of 10" scenario mostly isn't a hard-delete case in this plugin — it's usually a bulk archive. The real hard-delete paths need a separate audit pass (see Open Questions) to confirm where a backup is actually the only recovery option.

## Scope

### What to back up

All TalentTrack data tables, as they are at the moment of backup:

- `tt_lookups`, `tt_config`
- `tt_teams`, `tt_players`, `tt_people`
- `tt_evaluations`, `tt_eval_ratings`
- `tt_sessions`, `tt_attendance`
- `tt_goals`
- `tt_report_presets`
- `tt_custom_fields`, `tt_custom_values`
- `tt_audit_log` (optional — big table, rarely needed in a restore, toggle in settings)
- `tt_usage_events` (optional — analytics, never useful in a restore, default off)

**Not** backed up: WordPress core tables, users table (touchy — user accounts live in `wp_users`, which the plugin doesn't own), plugin files, uploads, WP options outside `tt_*`. The raw idea is clear about this — data only. Rationale stays in the spec.

### What not to back up (and why)

- **Code.** Reinstalling the plugin is a five-minute operation and the release is version-controlled on GitHub. Including code in the backup adds bulk for zero recovery benefit.
- **Media uploads.** If the plugin ever starts attaching photos to players, this becomes a harder call. For now, none of the tables above reference the uploads directory — no attachments to worry about. Flag for future: if player photos ship, backup scope grows.
- **WordPress users.** The plugin links to `wp_users` by ID. A restore across sites would break those links. See "Cross-site restore" below.

## Backup presets (the "more or less" axis)

Three presets, with a custom option for tinkerers:

| Preset | Frequency | Retention | Includes audit log | Destinations |
| --- | --- | --- | --- | --- |
| **Minimal** | Weekly | 4 backups | No | Local file |
| **Standard** (default) | Daily | 14 backups | No | Local file |
| **Thorough** | Daily + on-demand after bulk operations | 30 backups | Yes | Local + one off-site |
| Custom | any | any | toggle | any |

"Default" matches the raw idea: something reasonable out of the box, admin can dial up or down. Daily + 14 retained is the sweet spot — it covers the "last two weeks" window where most "oh no what did I do" moments happen, without filling disks.

**On-demand trigger.** Thorough tier (and opt-in for Standard) runs a backup automatically *before* any bulk operation above a threshold — bulk archive, bulk delete, mass evaluation import, migration run. This directly addresses the "deleted 100 evaluations instead of 10" scenario: the backup exists from 30 seconds before the mistake. Threshold configurable (default: anything touching ≥25 rows).

## Backup format

JSON (not SQL dump). Reasons:

- Portable across DB backends (the plugin uses `wpdb` which is MySQL-only today, but other hosts might matter tomorrow).
- Human-readable for debugging.
- Diff-able (a security researcher or support engineer can literally `diff` two backups to see what changed).
- Can include a schema-version header so the restore can handle format drift across plugin versions.

Structure:

```
talenttrack-backup-{site_hash}-{ISO_timestamp}.json.gz
```

Contents (sketch):

```json
{
  "meta": {
    "plugin_version": "3.1.0",
    "schema_version": "0011",
    "site_url": "https://example.com",
    "site_hash": "abc123...",
    "created_at": "2026-04-23T14:30:00Z",
    "created_by": 42,
    "preset": "standard",
    "trigger": "scheduled|on-demand|pre-bulk",
    "included_tables": ["tt_teams", "tt_players", ...],
    "row_counts": { "tt_teams": 12, "tt_players": 287, ... }
  },
  "tables": {
    "tt_teams": [ { ...row... }, ... ],
    "tt_players": [ ... ],
    ...
  },
  "checksum": "sha256:..."
}
```

Gzipped on disk (these compress extremely well — text with repeated field names and player names).

**Size estimate.** 300 players × 2,000 evaluations × say 8 fields each = manageable, low MB territory even uncompressed. A year of daily retention at "Standard" tier is maybe 100–300 MB on disk for a real club. Not a concern.

## Destinations

Each destination is an adapter behind a common interface (`BackupDestination::write(BackupFile $b)`), so new destinations slot in without touching the core scheduler.

1. **Local file.** Default and always on. Writes to `wp-content/uploads/talenttrack-backups/` (not inside plugin dir — plugin dir gets overwritten on updates). Directory protected with a `.htaccess` deny-all + random suffix on filenames to make URL guessing impractical. Important for restoring if the database is lost but the filesystem survives (common host failure mode).
2. **Email.** Send the `.json.gz` as an attachment to a configurable admin email. Safe for small sites; hits mail server attachment limits (usually 20–25 MB) for bigger ones. Works out of the box with no account setup. Good as a "something is better than nothing" offsite option.
3. **Cloud service — S3 / S3-compatible.** Amazon S3, Backblaze B2, Cloudflare R2, Wasabi. Credentials in `wp-config.php` constants (not in `wp_options` — same reasoning as the GitHub token in idea #0009). S3-compatible is the widest umbrella; it covers four or five of the cheapest providers with one adapter.
4. **Cloud service — Dropbox / Google Drive.** More complex (OAuth dance), but more familiar to non-technical admins. Lower priority — ship after S3.
5. **SFTP.** Small clubs running their own servers. Low-priority but a well-understood protocol, cheap adapter once S3 is done.

Day one: local + email + S3. Others follow.

## Restore

This is where most backup systems get it wrong. Restore has two genuinely different modes and they need separate UIs.

### Mode 1 — Full restore (disaster recovery)

Database lost, corrupted, or reverting an entire site to a known state. User explicitly confirms: "replace all TalentTrack data with the contents of this backup."

- Upload or select a backup file.
- Preview: metadata, row counts per table, timestamp, created by.
- Big red confirmation dialog with typed confirmation ("Type RESTORE to confirm").
- Plugin writes a safety backup of current state first, *then* truncates `tt_*` tables and re-inserts from the backup.
- Orphan detection: if the backup references `wp_user` IDs (coach/author columns) that don't exist on this site, offer a remap UI or accept orphans (NULL them out).

This mode is rarely used and that's fine. It's the safety net for the worst case.

### Mode 2 — Partial restore (the common case)

"I archived 100 evaluations instead of 10. Restore the ones I shouldn't have touched without losing the week of work since." This is the scenario the raw idea describes, and it's the hard part.

- Browse the backup tree: entities grouped by type, filterable.
- Diff view: for each entity present in the backup but missing/changed in the live DB, show what would happen on restore.
  - Entity in backup, not in live → **restore**.
  - Entity in backup and live, content identical → **skip**.
  - Entity in backup and live, content differs → **show both, let user pick**.
  - Entity in live, not in backup → **leave alone** (never delete during partial restore — that's what full restore is for).
- Integrity checks before applying:
  - A player references `team_id=5` — is team 5 in live or in the selection? If not, either add team 5 from the backup too, skip the player, or warn.
  - An evaluation references player + evaluator + session — all three must exist (or be added) for the restore to succeed.
  - Lookup values (positions, age groups, etc.) referenced by restored entities must still exist.
- The restore engine resolves dependencies automatically: selecting 100 evaluations includes their players and sessions if missing, and the UI explains this clearly ("Restoring 100 evaluations will also restore 4 players and 2 sessions that were removed").
- Dry-run mode: show what would happen without touching the DB.

This is genuinely the hardest piece in the epic and deserves its own sprint.

### Mode 3 — Not doing (but worth naming)

Time-travel / "bring back the DB state of 2026-03-15". This would require every backup to be a full snapshot and a specific replay mechanism. Overkill; partial restore covers the real need.

## Integrity model (the "of course taking integrity into account" part)

The hard rule: **the live database must never end up in a state it couldn't have reached through normal use of the plugin.** That means:

- Foreign-key-ish relationships enforced (even though MySQL may not enforce them at the schema level — the plugin does it at application level today, and so must restore).
- Lookup references validated before insertion.
- `archived_at` timestamps preserved — a restored archived entity stays archived. No accidental un-archiving.
- User IDs (`created_by`, `archived_by`) validated against `wp_users`. If they don't resolve, the restore NULLs them out (all these fields should be nullable in schema — a quick audit needed).
- Auto-increment IDs: preserve them where possible. On conflict (same ID in live, different content), the user chooses. Re-assigning IDs during restore is a last resort — it breaks any external references (URLs, bookmarks, reports that cite `/player/123`).

## Schedule + jobs

- WP-Cron for scheduled backups. Works out of the box; reliability depends on site traffic. Document the fallback to a real cron (`wp cron event run --due-now` on a system crontab) for sites that need it — standard WP advice.
- Backup jobs run in the background (`wp_schedule_single_event` chunks for large sites). Never block the admin UI.
- Failure handling: email the admin on any backup failure. Health indicator in the admin menu (green / yellow / red based on last successful backup age).
- On-demand backup button on the settings page. "Backup now."

## Security and privacy

- **Backups contain personally identifiable data** (player names, possibly contact info, evaluations). They must be handled like DB dumps — not casually emailed to random addresses, not left in a publicly accessible directory.
- Local backup directory: `.htaccess` deny + random path suffix. Confirmed readable only by PHP, not by the web.
- Email destination: admin accepts responsibility in the settings (checkbox: "I understand these emails contain personal data"). Warning shown if the configured address is on a domain the site doesn't control (gmail.com, etc.) — not blocked, just warned.
- S3 / cloud: credentials via `wp-config.php` constants, scoped to one bucket, write-only if the provider supports it (S3 does — IAM policy with `s3:PutObject` but not `s3:GetObject` is fine for write; `Get` only needed during restore, can be scoped to a separate read role that's enabled manually when a restore is needed).
- Encryption at rest on the destination: S3-side encryption is free and on by default. At-source encryption (the plugin encrypts before sending) is possible but adds key-management complexity — not day-one.
- Retention enforcement: old backups get deleted on schedule. "30 retained, rolling" means #31 overwrites when #1 ages out. Applies per destination.

## Cross-site restore (important edge case)

If someone takes a backup from site A and tries to restore it on site B, what happens?

- User IDs don't match (different `wp_users`). Remap UI or NULL.
- Site URL in metadata differs from current site URL. Warn loudly, proceed if confirmed.
- Plugin schema version in backup differs from current. If backup is older, run migrations on the restored data. If newer, refuse — the plugin can't safely read a future schema.
- Lookups may have different IDs on site B. Match by slug/name rather than ID when possible.

Most users never do this. But it's the natural path for "I want to clone production to staging" and should work, with warnings.

## Open questions

- **Hard-delete audit.** Before spec'ing restore flows, do a pass through the codebase to find every `DELETE FROM tt_*` — not just bulk operations but every admin action that hard-deletes. Each one is a scenario where a backup is actually needed for recovery; each `archive` is not. The answer determines how much of the restore UI gets used in practice.
- **Can we use the audit log to do better than snapshots?** If `tt_audit_log` captures enough detail about changes (not just "user X deleted row Y" but "the row contained {...}"), then "undo the last 10 minutes" becomes possible without ever needing a backup file. Worth looking at what the audit log actually stores. Might turn into a separate idea rather than part of this one.
- **Compression.** gzip is fine. Brotli compresses better but adds a PHP extension dependency. Not worth it for this.
- **Encryption.** Optional client-side encryption (backup file encrypted with a user-supplied passphrase before leaving the server). Adds key-loss risk. Skip for v1, keep as a later option.
- **Restore across plugin major versions.** Backup from v2.x restored into v3.x — should this work? Probably yes, with the schema migration running over restored data. Backup from v3.x into v2.x? No, always refuse.
- **"Include users" toggle.** There's a case where backing up `wp_users` (or just the subset of users with `tt_*` roles) would be useful — when cloning a site. Scope expands quickly though; probably a separate export feature rather than part of backup.
- **Upload attachments if they get added.** If player photos ship as the raw idea #0004 suggests, that scope expands materially. Backup would need to include or link to files in the uploads dir. Flag for whoever does #0004 first.

## Decomposition / rough sprint plan

1. **Sprint 1 — engine + local destination + full restore.** Core scheduler, JSON export, gzip, local file writer, full-restore flow with confirmation. Ships as a usable MVP: daily backups to disk, manual full restore when needed.
2. **Sprint 2 — presets + settings UI + health indicator.** Three presets, custom config, admin settings page, on-demand button, "last backup" status tile.
3. **Sprint 3 — partial restore.** The hard one. Diff view, integrity checks, dependency resolution, dry run. Deserves a full sprint alone.
4. **Sprint 4 — destinations: email + S3.** Adapter pattern, two adapters, credential handling, failure/retry logic.
5. **Sprint 5 — pre-bulk auto-backup + polish.** The "before you delete 100 rows, let me just save a copy" feature. Needs hooking into bulk operation endpoints plus a quick-restore shortcut in the resulting admin notice ("500 rows archived — [undo via backup]").

Dropbox/Google Drive/SFTP adapters stack after 5 as follow-ons.

## Touches

New module: `src/Modules/Backup/`
- `BackupEngine.php` — orchestrates a backup run
- `BackupSerializer.php` — DB → JSON
- `BackupRestorer.php` — JSON → DB with integrity checks
- `PartialRestorePlanner.php` — the diff + dependency resolution logic
- `Destinations/` — `LocalDestination.php`, `EmailDestination.php`, `S3Destination.php`, (later) `DropboxDestination.php`, etc.
- `Schedule.php` — WP-Cron integration
- `Admin/BackupPage.php` — settings + restore UI

Storage location: `wp-content/uploads/talenttrack-backups/` (not inside plugin dir)
Schema: probably no new tables needed — backup state (last run, last success, retention pointer per destination) can live in a JSON blob in `tt_config` or in wp_options.
Hooks into bulk operation endpoints across existing modules for the pre-bulk auto-backup trigger.
Config constants in `wp-config.php`: `TT_BACKUP_S3_KEY`, `TT_BACKUP_S3_SECRET`, `TT_BACKUP_S3_BUCKET`, `TT_BACKUP_S3_REGION`, `TT_BACKUP_S3_ENDPOINT` (for non-AWS S3-compatible).
DEVOPS.md: document WP-Cron reliability + real-cron fallback.
