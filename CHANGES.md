# TalentTrack v3.101.1 — MFA enrollment wizard (#0086 Workstream B Child 1, sprint 2 of 3)

Sprint 2 of three. Lights up the user-facing surface that sprint 1 (v3.100.1) set up. Sprint 1 shipped the schema + domain services + Account-page status indicator with no user-visible enrollment path. Sprint 3 (next) wires the WordPress `authenticate` filter for login-time enforcement.

Renumbered v3.100.2 → v3.101.1 mid-build after the parallel-agent ship of v3.101.0 (#0006 team planner) landed.

## What landed in sprint 2

### `Domain\QrCodeRenderer`

Self-contained pure-PHP QR encoder. Byte-mode encoding at error-correction level L, automatic version selection v1..v10 (covers any otpauth URI we'd generate — worst case ~180 chars fits inside v8 / 192-byte capacity), full mask-pattern penalty scoring per ISO/IEC 18004:2015 §7.8.3.1. Output is inline SVG.

The choice of pure-PHP over a JS QR library is the security framing: server-side rendering keeps the user's TOTP secret inside a single trust boundary (PHP request handler → SVG bytes → user's screen) rather than shipping a third-party JS library that would need its own license + supply-chain review. Reed-Solomon ECC over GF(256) with primitive 0x11D, version capacity tables hand-copied from the ISO standard, BCH(15,5) format-info encoder + BCH(18,6) version-info encoder for v7+. ~600 LOC self-contained.

### `Wizards\MfaEnrollmentWizard`

Registered in `WizardRegistry` per CLAUDE.md §3. Cap `read` (every logged-in user manages their own MFA — keyed by `(wp_user_id, club_id)` so no cross-user concerns).

**Step 1 — Intro.** Plain explanatory text: what MFA is, which authenticator apps work, what to expect (~2 minutes, scan QR, type code, save backup codes). Already-enrolled users see a warning notice that continuing will replace their secret.

**Step 2 — Secret.** Lazy-generates a fresh 20-byte TOTP secret on first render via `TotpService::generateSecret()`, persists encrypted via `MfaSecretsRepository::upsertSecret()` with an empty backup-codes array. Re-renders / Back navigation reuse the same secret (the operation is idempotent — existing row → reuse, no row → create). Renders the QR code on the left, manual-entry fallback on the right with the secret chunked into 4-character blocks for legibility. The QR encodes the standard otpauth:// URI with issuer `TalentTrack` or `TalentTrack — <site name>` for non-default site names so users with multiple installs can tell them apart in their authenticator app.

**Step 3 — Verify.** 6-digit input with `inputmode=numeric` + `autocomplete=one-time-code` + `pattern=[0-9 ]*` + `autofocus` + `maxlength=11` (allows for spaces). Whitespace tolerated, validated via `TotpService::verify()` against the decrypted secret with the standard ±1-step (90s) tolerance window for clock skew. On match, `MfaSecretsRepository::markEnrolled()` flips `enrolled_at` to now. Clear error messages distinguish format errors from mismatch. Re-tries are unlimited at enrollment time — rate-limit + lockout machinery is for the runtime login flow (sprint 3), not enrollment, where forcing a re-scan on every miss is poor UX for clock-skew problems.

**Step 4 — Backup codes.** Generates 10 single-use plaintext codes via `BackupCodesService::generate()`, persists the hashed storage array via `updateBackupCodes()`. Plaintext is stashed in wizard state so re-renders show the same codes (telling the user "I generated different codes; the ones you wrote down are wrong" is worse than re-displaying the same set). Displays codes in a 2-column monospace grid with Copy-all-to-clipboard + Print buttons. Submission gated on a confirmation checkbox ("I have saved these and understand I won't see them again"). On submit `WizardState::clear()` wipes the plaintext from both the transient and the persistent draft row, redirects to `?page=tt-account&tab=mfa&tt_msg=mfa_enrolled`.

### `Admin\MfaActionHandlers` — two new admin-post endpoints

- `tt_mfa_regenerate_backup_codes` — issues a fresh batch of 10 codes, overwrites the stored hashes, stashes plaintext in a 5-minute one-shot transient (`tt_mfa_fresh_backup_codes_<user_id>`) that the destination tab reads + immediately deletes. The transient is the single channel for "show codes once after a redirect" — no plaintext crosses the redirect URL.
- `tt_mfa_disable` — wipes the user's `tt_user_mfa` row entirely. Requires a `confirm=yes` POST field to proceed. The tab's collapsible `<details>` form makes the action discoverable but a small extra step beyond a single click. Audit-log integration on this destructive action is deferred to sprint 3.

### Account-page MFA tab (rebuilt for sprint 2)

- **Not-enrolled path** — "Start enrollment" hero button pointing at the wizard URL via `WizardEntryPoint::urlFor( MfaEnrollmentWizard::SLUG, '' )`. Renders a "wizards disabled in configuration" notice if `tt_wizards_enabled` is off.
- **Enrolled path** — backup-codes-remaining count (with red running-low warning at ≤3), "Regenerate backup codes" button, collapsible "Turn MFA off" form with confirmation checkbox.
- **One-shot success messages** on `tt_msg=mfa_enrolled` / `mfa_backup_regenerated` / `mfa_disabled` / `mfa_disable_unconfirmed`. The regenerate-codes message renders the freshly-generated plaintext from the one-shot transient with Copy + Print buttons before deleting the transient.

### `MfaModule::boot()`

Was a no-op in sprint 1; now registers the wizard + inits the action handlers.

## Plaintext-credential lifecycle

- **TOTP secret** — generated and persisted (encrypted via `CredentialEncryption`, AES-256-GCM under `wp_salt('auth')`) at step 2. Never held plaintext in wizard state. Step 3 reads + decrypts from `tt_user_mfa` for verification.
- **Plaintext backup codes** — generated at step 4 only (after verify succeeds), held in wizard state for the duration of step 4's render. Submit calls `WizardState::clear()` which deletes both the transient and the persistent draft row. Worst-case exposure window: ~1 hour transient TTL or ~14 days persistent draft TTL until the cleanup cron sweeps it.
- **Regenerate-action plaintext** — held in a 5-minute transient between admin-post and the destination tab's render. The destination deletes the transient on first read.

## What's NOT in this PR

- **WordPress `authenticate` filter integration** (sprint 3).
- **Per-club `require_mfa_for_personas` setting** (sprint 3). Default `[ academy_admin, head_of_development ]`.
- **Rate-limited verification with 15-min lockout** (sprint 3). Consumes the `failed_attempts` + `locked_until` columns from migration 0073.
- **30-day "remember this device" cookie** (sprint 3). Consumes the `remembered_devices` column from migration 0073.
- **Audit-log integration** (sprint 3). Events: `mfa_enrolled` / `mfa_verified` / `mfa_lockout` / `mfa_backup_code_used` / `mfa_disabled`.
- **Operator-on-behalf-of-user disable** (sprint 3). For lockout recovery.

## Affected files

- `src/Modules/Mfa/Domain/QrCodeRenderer.php` — new.
- `src/Modules/Mfa/Wizards/MfaEnrollmentWizard.php` — new.
- `src/Modules/Mfa/Wizards/IntroStep.php` — new.
- `src/Modules/Mfa/Wizards/SecretStep.php` — new.
- `src/Modules/Mfa/Wizards/VerifyStep.php` — new.
- `src/Modules/Mfa/Wizards/BackupCodesStep.php` — new.
- `src/Modules/Mfa/Admin/MfaActionHandlers.php` — new.
- `src/Modules/Mfa/MfaModule.php` — `boot()` now registers the wizard + inits action handlers.
- `src/Modules/License/Admin/AccountPage.php` — `renderMfaTab()` rebuilt for the live enrollment surface.
- `languages/talenttrack-nl_NL.po` — 30 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

30 new translatable strings covering wizard step copy, manual-entry labels, verification error messages, backup-codes confirmation, regenerate-codes success messaging, and the disable-MFA confirmation form.

## Player-centricity

Same framing as sprint 1: protecting the WordPress accounts that touch player records. Every player file, every evaluation, every PDP cycle is gated by the cap-and-matrix layer — but the cap layer trusts whoever holds the WP user's session cookie. MFA is the second factor that anchors that trust. Sprint 2 puts the surface in front of every user so they can self-enroll today; sprint 3 makes enrollment mandatory for the personas with the broadest reach into player data.
