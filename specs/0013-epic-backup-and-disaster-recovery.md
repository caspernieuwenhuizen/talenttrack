<!-- type: epic -->

# #0013 — Backup + disaster recovery

## Status

**Ready.** Q1-Q4 locked 2026-04-25 (evening). Compressed to **2 sprints** per Casper's request.

## Locked decisions

| Q | Decision | Rationale |
| - | - | - |
| Q1 | **Defer S3** to a post-#0011 follow-on | Email covers 80% of clubs at zero credential friction; S3 ships as a paid-tier add-on when monetization can charge for it |
| Q2 | **Single settings page** with smart defaults, not a wizard | Backup config is 5 inputs; #0024's Done screen "Recommended next steps" handles the activation hand-off |
| Q3 | **Free baseline locked now**; Pro/Business assignment with #0011 | Sprint 1 is unconditional Free; Sprint 2 is Pro/Business candidate but unconditional until #0011 ships gates |
| Q4 | **5th deep-link card** on #0024 Done screen → backup settings | No standalone backup wizard; thin integration with the onboarding wizard |

## Problem

A TalentTrack install accumulates real operational data over months: players, evaluations, goals, sessions, reports. If a site gets hacked, a cheap hosting provider loses data, a plugin conflict corrupts a table, or an admin runs a destructive action by accident, there's currently **no recovery path**. The plugin's existing `archived_at` soft-archive pattern (migration 0010) handles single-record accidents, but not table-wide or site-wide loss.

WordPress itself has no built-in backup. General-purpose WP backup plugins (UpdraftPlus, BackupBuddy) work but: (a) back up the whole site indiscriminately; (b) can't do partial restores scoped to TalentTrack; (c) restore flows assume "bring the site back" not "bring these 40 evaluations back."

Who feels it: academy admins who lose data. In practice, invisible until the moment it's needed — and by then, it's too late.

## Proposal

A TalentTrack-scoped backup and disaster-recovery module with snapshot-based recovery as the primary strategy. JSON + gzip format, local disk and email destinations in v1, partial restore with dependency resolution in v2.

**Explicitly scoped to `tt_*` tables only** — no `wp_users`, no uploads directory. Site-clone functionality is a future concern.

## Scope (compressed to 2 sprints)

| Sprint | Focus | Tier | Effort |
| - | - | - | - |
| 1 | Engine + serializer + local + email + full restore + presets + settings + health + scheduler | **Free baseline** | ~28-35h |
| 2 | Partial restore with diff + pre-bulk auto-backup + undo shortcut | **Pro/Business candidate** | ~22-28h |

**Total: ~50-63 hours** — same as the original 5-sprint estimate; the compression reflects that several of the original sprints had no hard dependencies between them.

---

### Sprint 1 — Free-tier backup baseline

A complete, usable backup system at the free-tier level. After Sprint 1 lands, every install can take, schedule, and restore full backups locally and via email.

#### Engine

**`BackupSerializer`** — emits a JSON document over a list of `tt_*` table names:

```json
{
  "version": "1.0",
  "plugin_version": "3.14.0",
  "created_at": "2026-05-20T10:00:00Z",
  "preset": "standard",
  "tables": {
    "tt_players":      {"columns": [...], "rows": [...]},
    "tt_teams":        {...},
    "tt_evaluations":  {...}
  },
  "checksum": "sha256-of-tables-content"
}
```

**Compression**: gzip after JSON. File naming: `talenttrack-backup-YYYYMMDD-HHMMSS-<preset>.json.gz`.

**`BackupRestorer`** — accepts a `.json.gz` file path; flow:
1. Decompress + JSON-decode.
2. Verify checksum + schema version.
3. Reject if `plugin_version` major doesn't match (v2.x → v3.x rejected).
4. Dry-run summary first: "X players, Y teams, Z evaluations would be replaced."
5. Confirmation: typed "RESTORE" string (mirrors #0020's wipe confirmation).
6. Truncate target `tt_*` tables → replay the JSON. Wrapped in a transaction where MySQL allows.
7. Post-restore: re-verify row counts match metadata, surface warnings if not.

**Capability**: `tt_manage_backups` (new). Granted to `tt_head_dev` and `administrator`.

#### Destinations (adapter pattern)

```php
interface BackupDestinationInterface {
    public function store( string $backup_path, array $metadata ): StoreResult;
    public function list(): array;          // metadata of stored backups
    public function fetch( string $id ): string; // returns local path
    public function purge( string $id ): bool;
}
```

**`LocalDestination`** — files written to `wp-content/uploads/talenttrack-backups/`. Last N retained (default 30); older auto-purged.

**`EmailDestination`** — attaches the `.json.gz` via `wp_mail`. Settings: recipient(s), subject template (`{site_name}`, `{backup_date}`). Size cap ~10MB; over that, email gets a truncated summary + local-only notice. Retry once on failure (after 1 hour).

S3 / Dropbox / GDrive / SFTP: interface only, implementations deferred (Q1 decision).

#### Presets

| Preset | Tables |
| - | - |
| **Minimal** | `tt_players`, `tt_teams`, `tt_evaluations`, `tt_eval_ratings` |
| **Standard** | Minimal + `tt_sessions`, `tt_attendance`, `tt_goals`, `tt_people`, `tt_team_people`, `tt_functional_role_*` |
| **Thorough** | Standard + `tt_lookups`, `tt_custom_fields`, `tt_custom_values`, `tt_eval_categories`, `tt_audit_log`, `tt_usage_events`, `tt_demo_tags`, `tt_config` |
| **Custom** | Per-table checkboxes |

#### Scheduler

WP-cron-based. `tt_backup_run` action runs the configured preset to all enabled destinations. Default schedule: daily 02:00. Frequencies: daily / weekly / on-demand. "Run backup now" button on the settings page bypasses the schedule.

#### Settings page

A single wp-admin page under `Configuration` (or a frontend Administration tile if #0019 Sprint 5 patterns make that cleaner — decide during build). Fields:

- Preset selector (Minimal / Standard / Thorough / Custom + per-table checkboxes when Custom)
- Schedule (daily / weekly / on-demand)
- Retention count (default 30)
- Local destination toggle
- Email destination toggle + recipient list
- "Run backup now" button
- Restore-from-backup link (uploads a `.json.gz` or picks from local store)

#### Health indicator

Small "Backups" card on the wp-admin TalentTrack dashboard (and the frontend Administration tile group when present). Shows:

- Last successful backup: relative time, color-coded (green ≤24h, yellow ≤7d, red >7d)
- Next scheduled time
- Quick link to the settings page

#### #0024 integration

Add a 5th "Recommended next steps" card on `OnboardingPage::renderDone()`:
> **Set up backups** — Schedule daily backups so a hosting hiccup doesn't lose your data.
> Links to: `wp-admin/admin.php?page=tt-config&tab=backups`

Three-line change to the existing wizard. No structural impact.

---

### Sprint 2 — Pro/Business candidate features

Builds on Sprint 1's engine. Every feature in this sprint is unconditional until #0011 ships gates; the spec just identifies them as paid-tier candidates so #0011's tier audit can flip them in one place.

#### Partial restore with dependency resolution

The headline differentiator vs general-purpose backup plugins.

**Flow**:
1. User uploads backup or picks from local store + selects scope: "Restore just this player," "Restore all players from this team," etc.
2. **`BackupDependencyMap`**: depth-first walk of foreign-key-like references — a player needs their team (if missing); evaluations need the player AND eval-categories; goals need the player.
3. **Diff view** per record:
   - **Green** — in backup, not current. Will be restored.
   - **Yellow** — in both, differ. User picks: keep-current / overwrite-with-backup / skip.
   - **Red** — in current, not in backup. User picks: leave / delete-to-match-backup.
4. Dependency closure presented to user with chance to uncheck.
5. **Dry-run preview** ("show me what would happen without writing").
6. Confirm → restore executes. Post-restore integrity scan validates foreign-key-like consistency; broken refs surface as warnings.

#### Pre-bulk auto-backup

Before any operation deleting/archiving >N rows (default N=10; configurable):

- Snapshot the current state via the Sprint 1 engine.
- Tag in metadata `auto_bulk_safety: true`.
- Retention shorter than scheduled backups (default 14 days).

Hooks into:
- CSV import with dupe-overwrite mode (#0019 Sprint 3).
- Bulk archive actions across modules.
- #0020 demo-wipe actions (belt-and-braces).

#### Undo shortcut

Bulk operations that triggered a pre-bulk safety snapshot get an admin-notice augmentation:
> **500 rows archived.** [Undo via backup] (expires in 14 days)

Click → opens the partial-restore flow pre-scoped to the affected rows.

---

## Out of scope

- **S3 destination implementation** (interface shipped in Sprint 1, adapter deferred until #0011 — see Q1).
- **Dropbox / Google Drive / SFTP destinations**. Post-v1.
- **Full-site clone**. This is a TalentTrack-data tool only.
- **Encryption of backup files**. Adds key-loss risk for marginal value at the v1 trust level.
- **Cross-major-version migration during restore**. Reject v3.x backup into v2.x; accept v2.x → v3.x if migrations handle it.
- **Including `wp_users`, `wp_usermeta`, or uploads** in the backup.
- **Web UI for `wp-cli`-style backup commands**. WP-cron + "run now" button only.
- **Audit-log-driven undo as an alternative model**. Considered during shaping; deferred (revisit if #0021 audit log is rich enough).

## Acceptance criteria

### Sprint 1
- [ ] Daily scheduled backup runs and persists to local storage.
- [ ] Email destination attaches and sends within size limits.
- [ ] Admin can restore a full backup with a typed confirmation.
- [ ] Presets (Minimal/Standard/Thorough) work; Custom mode works.
- [ ] Health indicator accurately reflects backup status.
- [ ] `tt_manage_backups` capability registered + assigned to head_dev + administrator.
- [ ] #0024 Done screen has the 5th "Set up backups" card.
- [ ] Dutch translations + docs in the same PR.

### Sprint 2
- [ ] Partial restore with diff view and dependency resolution works.
- [ ] Dry-run preview shows correct diff without writing.
- [ ] Pre-bulk safety snapshot fires on operations deleting/archiving >10 rows.
- [ ] Undo shortcut in admin notices works for recent bulk operations.
- [ ] No regression: existing data untouched unless restore is explicitly invoked.

## Notes

### Cross-epic interactions

- **#0024 (setup wizard)** — already shipped; integration is the 5th Recommended Next Step card.
- **#0020 (demo data generator)** — Sprint 2's pre-bulk auto-backup hooks into demo-wipe.
- **#0017 (trials)** — denied trial players' 2-year retention policy interacts with backup retention. Operational concern, not a code gate.
- **#0021 (audit log viewer)** — if/when ships, audit-log-driven undo could supplement Sprint 2's pre-bulk model.
- **#0011 (monetization)** — Sprint 2's three features are paid-tier candidates. Cap checks go through `TT\License::can()` once that abstraction exists. Today they're unconditional.

### Retention + GDPR

Backup files contain real personal data. Default retention 90 days; configurable. GDPR deletion requests must purge from backups too — v1 ships a manual "regenerate all backups post-deletion" button; full automation is a future enhancement.

### Depends on

- #0019 Sprints 1, 2, 5 — for frontend conventions and Administration tile placement (already shipped).
- Nothing schema-blocking.

### Touches

- New module: `src/Modules/Backup/`
  - `BackupModule.php`
  - `BackupSerializer.php`, `BackupRestorer.php`
  - `Destinations/BackupDestinationInterface.php`
  - `Destinations/LocalDestination.php`, `Destinations/EmailDestination.php`
  - `Scheduler.php`
  - `Admin/BackupSettingsPage.php`, `Admin/BackupHealthBlock.php`
  - Sprint 2: `BackupDependencyMap.php`, `Admin/PartialRestoreView.php`, `Admin/BulkSafetyHook.php`
- Capability registration: `tt_manage_backups` in `RolesService`
- `wp-content/uploads/talenttrack-backups/` directory created on first run with a stub `index.php` + `.htaccess`
- Hooks into existing CSV import + demo-wipe + bulk archive flows (Sprint 2)

## Sequence position

Insert ahead of #0011 in SEQUENCE.md. Backup safety should be in place before paid customers show up.
