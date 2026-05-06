<?php
namespace TT\Modules\Mfa;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * MfaModule (#0086 Workstream B Child 1) — TalentTrack-native multi-
 * factor authentication.
 *
 * Built native (not a WP-plugin recommendation) per the Q1 lock in
 * specs/0086-epic-security-and-privacy.md so the auth surface ports
 * cleanly into the future SaaS migration.
 *
 * Sprint 1 (this ship — v3.98.2):
 *   - Migration 0071 introduces `tt_user_mfa`.
 *   - `Domain\TotpService` — RFC 6238 generate + verify (HMAC-SHA1,
 *     30-second step, ±1-step tolerance).
 *   - `Domain\BackupCodesService` — generate, hash, verify single-use.
 *   - `MfaSecretsRepository` — CRUD on `tt_user_mfa`. Encrypted secret
 *     via the `CredentialEncryption` envelope (same threat model as
 *     Spond credentials and Web Push VAPID keys — DB dump alone cannot
 *     reconstruct).
 *   - Account-page status tab — shows "MFA: not enrolled / enrolled"
 *     and a placeholder "enrollment coming soon" link until Sprint 2.
 *
 * Sprint 2 (next):
 *   - 4-step enrollment wizard registered in WizardRegistry per
 *     CLAUDE.md §3 (intro → secret + QR code → first-code verification →
 *     backup codes display).
 *   - Account-tab regenerate-backup-codes action.
 *
 * Sprint 3 (last):
 *   - WordPress `authenticate` filter integration — after WP's password
 *     check, before the session cookie issues.
 *   - Per-club setting `require_mfa_for_personas` (default
 *     `[ academy_admin, head_of_development ]`); on login, enrolled
 *     users in gated personas verify TOTP, un-enrolled users in gated
 *     personas redirect to the enrollment wizard.
 *   - Rate-limited verification: 5 attempts / 5 minutes, then 15-minute
 *     lockout. `failed_attempts` + `locked_until` columns from this
 *     migration.
 *   - Optional 30-day "remember this device" cookie. `remembered_devices`
 *     column from this migration.
 *   - Audit-log integration — `mfa_enrolled` / `mfa_verified` /
 *     `mfa_lockout` / `mfa_backup_code_used` / `mfa_disabled`.
 */
class MfaModule implements ModuleInterface {

    public function getName(): string { return 'mfa'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // Sprint 1 wires no runtime hooks — the foundation exists for
        // Sprint 2 (enrollment wizard) and Sprint 3 (login integration)
        // to consume. The Account-page status tab self-registers via
        // the existing AccountPage tab dispatch in src/Modules/License.
    }
}
