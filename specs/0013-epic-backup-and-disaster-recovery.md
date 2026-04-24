<!-- type: epic -->

# #0013 — Backup + disaster recovery

## Problem

A TalentTrack install accumulates real operational data over months: players, evaluations, goals, sessions, reports. If a site gets hacked, a cheap hosting provider loses data, a plugin conflict corrupts a table, or an admin runs a destructive action by accident, there's currently **no recovery path**. The plugin's existing `archived_at` soft-archive pattern (migration 0010) handles single-record accidents, but not table-wide or site-wide loss.

WordPress itself has no built-in backup. General-purpose WP backup plugins (UpdraftPlus, BackupBuddy) work but: (a) back up the whole site indiscriminately; (b) can't do partial restores scoped to TalentTrack; (c) restore flows assume "bring the site back" not "bring these 40 evaluations back."

Who feels it: academy admins who lose data. In practice, invisible until the moment it's needed — and by then, it's too late.

## Proposal

A TalentTrack-scoped backup and disaster-recovery module with snapshot-based recovery as the primary strategy. JSON + gzip format, local disk and email destinations, preset presets for common use cases, partial restore with dependency resolution.

**Explicitly scoped to `tt_*` tables only** — no `wp_users`, no uploads directory. Site-clone functionality is a future concern.

## Scope

Five sprints:

| Sprint | Focus | Effort |
| --- | --- | --- |
| 1 | Engine + JSON/gzip serializer + local destination + full restore flow | ~12–15h |
| 2 | Presets (Minimal/Standard/Thorough) + settings UI + health-indicator tile | ~8–10h |
| 3 | Partial restore with dependency resolution + dry-run preview | ~14–18h |
| 4 | Email destination + adapter pattern (S3 deferred to follow-up) | ~8–10h |
| 5 | Pre-bulk auto-backup + "undo last 100 deletions" shortcut | ~8–10h |

**Total: ~50–63 hours.**

### Sprint 1 — Engine + local backup + full restore

**Serializer**: one `BackupSerializer` class that takes a list of `tt_*` table names, queries them, emits a single JSON document with schema:
```json
{
  "version": "1.0",
  "plugin_version": "3.1.0",
  "created_at": "2026-05-20T10:00:00Z",
  "tables": {
    "tt_players": {"columns": [...], "rows": [...]},
    "tt_teams": {...},
    ...
  },
  "checksum": "sha256-of-tables-content"
}
```

**Compression**: gzip after JSON. File naming: `talenttrack-backup-YYYYMMDD-HHMMSS.json.gz`.

**Local destination**: files written to `wp-content/uploads/talenttrack-backups/` (or a configurable path). Last N backups retained; older auto-purged (setting: retention count, default 30).

**Full restore flow**:
- Upload a `.json.gz` backup file, or pick one from the local store.
- Dry-run first: show a diff summary ("47 players would be restored, 3 teams, 205 evaluations — this will replace all current tt_* data").
- Confirmation with typed "RESTORE" (similar to #0020's wipe confirmation).
- On confirm: truncate `tt_*` tables → replay the JSON. Wrapped in a transaction where possible.
- Post-restore check: verify checksum, validate row counts match backup metadata.

**Scheduling**: WP-cron-based. Daily default, configurable frequency. Unreliable on low-traffic sites — same mitigation as #0017: "Run backup now" button + daily-trigger preference.

**Capability**: `tt_manage_backups` (new). Granted to `tt_head_dev` and `administrator`.

### Sprint 2 — Presets + settings + health

**Three presets**:
- **Minimal** — `tt_players`, `tt_teams`, `tt_evaluations`, `tt_eval_ratings`. The core operational data.
- **Standard** — Minimal + sessions, attendance, goals, people, functional roles. Typical daily state.
- **Thorough** — Standard + lookups, custom fields, eval categories, audit log, usage events. Complete state.

Custom mode: checkboxes for each `tt_*` table.

**Settings UI** (frontend Administration tile, under `tt_manage_backups`):
- Preset selector (Minimal / Standard / Thorough / Custom).
- Schedule: daily / weekly / on-demand.
- Retention: keep last N (default 30).
- Destinations: local on, email on, add email address(es).
- "Run backup now" button.
- "Restore from backup" link (to Sprint 1's flow).

**Health indicator tile**: on the Administration tile group, a small "Backups" card showing:
- Last successful backup: "3 hours ago" (green) / "2 days ago" (yellow, ≥24h past schedule) / "8 days ago" (red, 7+ days stale).
- Next scheduled: "tomorrow 02:00."
- Quick link to settings.

### Sprint 3 — Partial restore + dependency resolution

The hard sprint.

**Partial restore**: from a backup, select specific records to restore — not the whole snapshot.

Flow:
1. User uploads backup + selects scope: "Restore just this player" or "Restore all players from this team."
2. System computes the **dependency closure**: if restoring a player, we also need their team (if missing), their evaluations (if desired), referenced eval-categories (if desired), etc.
3. **Diff view**: UI shows per-record:
   - Green: in backup, not current. → Will be restored.
   - Yellow: in backup AND current, differ. → User picks: keep-current / overwrite-with-backup / skip.
   - Red: in current, not in backup. → User picks: leave / delete-to-match-backup.
4. User reviews the diff, confirms, restore executes.

**Dependency resolver**: depth-first walk starting from the selected records, following foreign-key-like references defined in a new `BackupDependencyMap`. Closure is presented to the user as "you'll also be restoring: 3 teams, 45 evaluations, 12 goals" with a chance to uncheck dependencies.

**Integrity checks**: after restore, validate foreign-key-like consistency (every `player_id` in `tt_evaluations` refers to an existing row in `tt_players`, etc.). Any broken refs are flagged as warnings post-restore.

**Dry-run preview**: "Show me what would happen without actually doing it." Renders the full diff without writes.

### Sprint 4 — Email destination

**Adapter pattern**: `BackupDestinationInterface` with `store(string $backupPath, array $metadata): StoreResult`. Implementations:
- `LocalDestination` (from Sprint 1).
- `EmailDestination` (this sprint).
- `S3Destination` (deferred to post-v1 follow-on — interface is there, implementation is not).
- Future: Dropbox, GDrive, SFTP. Each is a new adapter.

**Email destination**:
- Settings: recipient email(s), plain-text or HTML body, subject template with variables (`{site_name}`, `{backup_date}`).
- On backup: attaches the `.json.gz` file, sends via WP's `wp_mail`.
- Size cap: ~10MB attachment. If backup exceeds, email gets a truncated summary and a local-storage-only notice; UI shows "Backup exceeds email limit; stored locally only."
- Retry: if email send fails, retry once after 1 hour. Then fail permanently for that run.

### Sprint 5 — Pre-bulk auto-backup + undo shortcut

**Pre-bulk auto-backup**: before any operation deleting or archiving >N rows (default N=10; configurable), automatically snapshot the current state first.

Hooks into:
- CSV import with dupe-overwrite mode (Sprint 3 of #0019).
- Bulk archive actions (any module that adds them).
- The `#0020` demo-wipe actions (belt-and-braces).

The snapshot is marked `auto_bulk_safety` in metadata, retained for a shorter window (default 14 days) so it doesn't eat storage.

**Undo shortcut**: after a bulk operation completes, the resulting admin notice includes:
> **500 rows archived.** [Undo via backup] (expires in 14 days)

Clicking "Undo" opens the partial-restore flow from Sprint 3, pre-scoped to the affected rows.

## Out of scope

- **S3 destination implementation** (interface shipped, adapter deferred).
- **Dropbox / Google Drive / SFTP destinations**. Post-v1.
- **Full-site clone**. This is a TalentTrack-data tool only.
- **Encryption of backup files**. Flagged as open question; skip for v1. Adds key-loss risk.
- **Cross-major-version migration during restore**. Reject v3.x backup into v2.x; accept v2.x into v3.x if schema migrations handle it.
- **Including `wp_users`, `wp_usermeta`, or uploads** in the backup. Scope limited to `tt_*`.
- **Web UI for running `wp-cli`-style backup commands**. WP-cron + "run now" button only.
- **Audit-log-driven undo as an alternative model**. Considered during shaping; deferred.

## Acceptance criteria

The epic is done when:

- [ ] Daily scheduled backup runs and persists to local storage.
- [ ] Admin can restore a full backup with a typed confirmation.
- [ ] Presets (Minimal/Standard/Thorough) work; custom mode works.
- [ ] Health indicator accurately reflects backup status.
- [ ] Partial restore with diff view and dependency resolution works.
- [ ] Dry-run preview shows correct diff without writing.
- [ ] Email destination attaches backup file and sends successfully within size limits.
- [ ] Pre-bulk safety snapshot fires on operations deleting/archiving >10 rows.
- [ ] Undo shortcut in admin notices works for recent bulk operations.
- [ ] No regression: existing data untouched unless restore is explicitly invoked.

## Notes

### Cross-epic interactions

- **#0020 (demo data generator)** — this module can automate the "regenerate known-state" pattern. #0020's data generator could become a test fixture factory for the DR module.
- **#0017 (trials)** — denied trial players' 2-year retention policy interacts with backup retention. Flag: a deleted trial case is GDPR-obligated to purge from backups too. Documented as an operational concern, not a code gate.
- **#0021 (audit log viewer)** — if/when that ships and the audit log turns out rich enough to support undo, the audit-log-driven undo model could supplement or replace pre-bulk auto-backup. Revisit then.

### Retention + GDPR

Backup files contain real personal data (minors' names, ratings, evaluations). Clubs are responsible for backup retention within their site storage. Default: backups older than 90 days auto-purged. Configurable.

When a GDPR deletion request arrives:
- The deleted-person's data must be purged from all backups too. Settings-UI mentions this; implementation-wise, this is a full-scan-and-rewrite of old backups, which is expensive. v1 provides a manual "regenerate all backups post-deletion" button; automation is a future enhancement.

### Depends on

- #0019 Sprints 1, 2, 5 for frontend conventions and Administration tile placement.
- Nothing schema-blocking.

### Blocks

Nothing strictly, but ideally ships before clubs start accumulating real operational data (i.e. before #0011 monetization goes live and paid customers show up).

### Touches

- New module: `src/Modules/Backup/`
  - `BackupModule.php`
  - `BackupSerializer.php`
  - `BackupRestorer.php`
  - `BackupDependencyMap.php`
  - `Destinations/LocalDestination.php`
  - `Destinations/EmailDestination.php`
  - `Destinations/BackupDestinationInterface.php`
  - `Scheduler.php` (WP-cron)
- Admin surfaces:
  - Settings view (Administration tile)
  - Full-restore view
  - Partial-restore view (with diff UI)
  - Health indicator (tile component)
- Hooks:
  - Pre-bulk interception (at the REST controller level for Players CSV import, etc.)
  - Admin-notice augmentation with undo links
- Capability registration: `tt_manage_backups`
