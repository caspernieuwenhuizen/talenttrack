# TalentTrack v3.103.1 — MFA login enforcement (#0086 Workstream B Child 1, sprint 3 of 3)

Closes #0086 Workstream B Child 1. Sprint 1 (v3.100.1) shipped the data layer; sprint 2 (v3.101.1) shipped the enrollment wizard; sprint 3 wires the runtime login enforcement so the second factor actually gates access.

Renumbered v3.102.1 → v3.103.1 mid-rebase after the parallel-agent ship of v3.103.0 (#0080 polish wave 2 — A residual + B + C bundled) landed.

## What landed in sprint 3

### `Auth\MfaLoginGuard` — runtime enforcement

Hooks `wp_login` (post-cookie) and `init` (every subsequent request).

On login, if the user's persona intersects the per-club `mfa_required_personas` list and they don't hold a valid 30-day "remember this device" cookie:
- Enrolled user → set `tt_mfa_pending_<user_id>` transient (15-min TTL).
- Un-enrolled user → set `tt_mfa_must_enroll_<user_id>` transient.
- Stash the original `redirect_to` URL in `tt_mfa_post_verify_url_<user_id>` so the prompt page sends the user there after success.

The init middleware redirects every subsequent request to the MFA prompt page (or the enrollment wizard) until the challenge clears. REST + admin-ajax + admin-post + cron + wp-login + the prompt itself + the enrollment wizard URL are exempt. The "must enroll" path appends `tt_mfa_required=1` so the wizard's intro step can show a "you must enable MFA before continuing" notice.

### `Frontend\FrontendMfaPromptView` — the challenge page

Reachable at `?tt_view=mfa-prompt`. Two modes:
- **TOTP** (default) — 6-digit input with `inputmode=numeric` + `autocomplete=one-time-code` + autofocus. Validates via `TotpService::verify()` against the decrypted secret.
- **Backup code** (`?mode=backup`) — `XXXX-XXXX-XXXX` input. Validates via `BackupCodesService::verify()` over the constant-time iteration; on match, marks the code as used and persists the modified array.

Optional "remember this device for 30 days" checkbox issues the `tt_mfa_device` cookie via `RememberDeviceCookie::setForUser()` on success. Lockout state renders a countdown screen with no input field instead of the form.

### `Auth\RateLimiter` — 5/15 lockout policy

5 consecutive failed verifications trigger a 15-minute lockout. Counter resets on success. Both thresholds are operator-configurable via `MfaSettings` (`mfa_max_attempts`, `mfa_lockout_minutes`). Each failure writes an `mfa.verify_failed` audit event; the threshold-trip writes `mfa.lockout`. The repository helpers (`recordFailedAttempt`, `lockoutUntil`) are added to `MfaSecretsRepository` for sprint-3 use.

### `Auth\RememberDeviceCookie` — 30-day signed cookie

Cookie name: `tt_mfa_device`. Format: `<wp_user_id>|<device_token>|<signature>`. Signature: `wp_hash( "tt_mfa_device|<user_id>|<device_token>" )`, verified with `hash_equals()`. Server-side persists `signed_token` + `device_label` (UA-derived) + `expires_at` + `last_used_at` in `tt_user_mfa.remembered_devices` JSON. Same `httponly` + `samesite=Lax` + `secure=is_ssl()` conventions as `ImpersonationContext`. Verify path bumps `last_used_at`; clear path purges the cookie + cookie-side state.

### `Settings\MfaSettings` — per-club configuration

Typed accessor over `tt_config` (per-club key-value via `ConfigService`). Keys:
- `mfa_required_personas` — JSON array of persona keys. Default: `[ academy_admin, head_of_development ]`. **Explicit `[]` disables enforcement entirely** (the read path distinguishes "unset" from "explicitly empty"); the operator UI uses this to turn the gate off when every checkbox is unticked.
- `mfa_lockout_minutes` — default 15.
- `mfa_max_attempts` — default 5.
- `mfa_remember_device_days` — default 30.

### `Audit\MfaAuditEvents` — stable event keys

Wraps `AuditService::record()` with: `mfa.enrolled`, `mfa.verified`, `mfa.verify_failed`, `mfa.lockout`, `mfa.backup_code_used`, `mfa.backup_codes_regenerated`, `mfa.disabled`, `mfa.device_remembered`, `mfa.devices_revoked`, `mfa.required_personas_changed`. Operator-on-behalf-of-user `mfa.disabled` events log both: actor = operator, subject = target user.

### Operator-only MFA tab section

`?page=tt-account&tab=mfa` (operator-only sub-section, gated on `tt_edit_settings`):
- **`mfa_required_personas` setting** — multi-checkbox over the persona catalogue. Submit writes via the new `tt_mfa_save_required_personas` admin-post.
- **Reset MFA on another user (lockout recovery)** — dropdown of currently-enrolled users + confirmation checkbox + Reset button. Submit deletes the target's `tt_user_mfa` row via `MfaSecretsRepository::disable()`. Audit-logged.

### Atomic enrollment fix

Sprint 2's wizard flipped `enrolled_at` at the verify step, leaving a window where a user who closed the browser between verify and backup-codes was "enrolled" but had no recovery codes. Sprint 3 moves `markEnrolled()` to the BackupCodesStep submit so enrollment is atomic with backup-codes persistence.

### CI / E2E setup

The login smoke test covers a default-admin login flow that's now MFA-gated by default. A `wp db query` step writes `mfa_required_personas = []` to `tt_config` after plugin activation so the existing test isn't redirected into the enrollment wizard. A separate MFA-specific spec file will exercise the full enrollment + challenge flow.

## Risk callouts

- **REST is exempt from the gate.** A user with their session cookie can hit REST endpoints between login and challenge completion. The MFA gate's purpose is at login (verifying the cookie holder is the real user); once a session cookie exists in a browser, REST gating wouldn't change the threat model. Documented as a known limitation.
- **Single-admin lockout.** If the only academy admin enrolls themselves and then loses both phone + all backup codes, they're stuck — operator-on-behalf-of-user disable requires another operator. Mitigation: the security operator guide already prescribes "two admins is a reasonable minimum for redundancy."
- **Cookie-bound trust.** The 30-day remember-device cookie is bound to (user_id, device_token) and signed via `wp_hash()`. If a remember-cookie leaks (e.g. shared browser), the attacker bypasses MFA on that device until the cookie expires or the user revokes.

## What's NOT in this PR

- **Per-device revocation UI** — listing remembered devices with a per-row revoke button. Repository methods are in; surfacing them is a small follow-up.
- **#0086 Workstream B Children 2-4** — session management UI / login-fail tracking / admin IP whitelist.
- **#0086 Workstream C** — external audit (Securify / Computest), kicks off after Workstream B Children 1-3 ship.

## Affected files

- `src/Modules/Mfa/Auth/MfaLoginGuard.php` — new.
- `src/Modules/Mfa/Auth/RateLimiter.php` — new.
- `src/Modules/Mfa/Auth/RememberDeviceCookie.php` — new.
- `src/Modules/Mfa/Frontend/FrontendMfaPromptView.php` — new.
- `src/Modules/Mfa/Settings/MfaSettings.php` — new.
- `src/Modules/Mfa/Audit/MfaAuditEvents.php` — new.
- `src/Modules/Mfa/MfaSecretsRepository.php` — five new repository helpers.
- `src/Modules/Mfa/MfaModule.php` — `boot()` wires `MfaLoginGuard::init()`.
- `src/Modules/Mfa/Admin/MfaActionHandlers.php` — audit logging on regenerate / disable, plus two new actions for save-personas / operator-disable.
- `src/Modules/Mfa/Wizards/VerifyStep.php` — drops `markEnrolled()` (atomic-enrollment fix).
- `src/Modules/Mfa/Wizards/BackupCodesStep.php` — calls `markEnrolled()` + `MfaAuditEvents::record()` + `MfaLoginGuard::clearPending()` on submit.
- `src/Modules/License/Admin/AccountPage.php` — operator-only sub-section.
- `src/Shared/Frontend/DashboardShortcode.php` — `mfa-prompt` view dispatch.
- `.github/workflows/e2e.yml` — `mfa_required_personas = []` in CI setup so the existing login smoke test isn't gated.
- `languages/talenttrack-nl_NL.po` — 35 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md`, `docs/security-operator-guide.md` (EN+NL) — version bump + ship metadata.
