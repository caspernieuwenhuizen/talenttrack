# TalentTrack v2.3.0 — Delivery Changes

## What this ZIP does

Completes **Sprint 0 Phase 3: observability & governance**. Adds central logging,
an audit trail, feature toggles, and environment-aware behaviour — all without
touching existing frontend, admin, REST, or module code.

## How to install

1. Extract this ZIP somewhere.
2. Open the resulting `talenttrack-v2.3.0/` folder.
3. Copy its **contents** (not the folder itself — the files and folders inside)
   into your local `talenttrack/` repository folder. Allow overwrites.
4. GitHub Desktop will show you the files that changed.
5. Commit: `v2.3.0 — Sprint 0 Phase 3 (logging/audit/toggles/environment)`.
6. Push to origin.
7. GitHub → Releases → create new release tagged `v2.3.0`.
8. GitHub Actions builds & attaches the ZIP automatically.
9. WordPress auto-updates within a few hours, or force-check in Dashboard → Updates.

## Files in this delivery

### Modified
- `talenttrack.php` — version bumped to 2.3.0.
- `readme.txt` — stable tag 2.3.0, changelog entry added.
- `src/Core/Kernel.php` — registers logger, environment, toggles, audit, audit.subscriber in the container; wires AuditSubscriber on boot.
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — adds "Feature Toggles" and "Audit Log" tabs.
- `config/environment.php` — minor refinement to read via `wp_get_environment_type()`.

### Added
- `src/Infrastructure/Logging/Logger.php` — 4-level central logging.
- `src/Infrastructure/Environment/EnvironmentService.php` — env detection.
- `src/Infrastructure/FeatureToggles/FeatureToggleService.php` — feature flags backed by `tt_config`.
- `src/Infrastructure/Audit/AuditService.php` — writes to `tt_audit_log`.
- `src/Infrastructure/Audit/AuditSubscriber.php` — hooks existing TT actions to record audit entries.
- `database/migrations/0002_create_audit_log.php` — new migration creating `tt_audit_log` table.

### Unchanged
- Every other file — frontend views, REST controllers, module classes, role management, admin page logic for non-Configuration modules.

## What you'll notice after install

1. **New table** `wp_tt_audit_log` created automatically (via migration 0002).
2. **New tab** in Configuration → Feature Toggles — three initial toggles:
   - Audit log (on by default)
   - Verbose logging (off by default)
   - Login redirect (on by default — matches existing behaviour)
3. **New tab** in Configuration → Audit Log — shows recent events with filters.
4. **Logging** — any warnings/errors appear in `wp-content/debug.log` (if `WP_DEBUG_LOG` is enabled) prefixed with `[TalentTrack][LEVEL]`.

## Verification after install

1. `wp_tt_migrations` should now have 2 rows: `0001_initial_schema` and `0002_create_audit_log`.
2. Visit **TalentTrack → Configuration → Audit Log** — if you've done any actions since install, you'll see entries (e.g. `user.login` from your own login).
3. Visit **TalentTrack → Configuration → Feature Toggles** — flip one, save, flip it back to confirm the UI works.

## Rollback note

If anything misbehaves, the new code paths are all feature-toggled. Disabling the `audit_log` toggle stops new audit entries from being recorded. The `tt_audit_log` table remains (containing historical entries) but plays no role in the running plugin.
