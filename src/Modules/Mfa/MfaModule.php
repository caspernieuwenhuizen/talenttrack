<?php
namespace TT\Modules\Mfa;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Mfa\Admin\MfaActionHandlers;
use TT\Modules\Mfa\Wizards\MfaEnrollmentWizard;
use TT\Shared\Wizards\WizardRegistry;

/**
 * MfaModule (#0086 Workstream B Child 1) — TalentTrack-native multi-
 * factor authentication.
 *
 * Built native (not a WP-plugin recommendation) per the Q1 lock in
 * specs/0086-epic-security-and-privacy.md so the auth surface ports
 * cleanly into the future SaaS migration.
 *
 * Shipped so far:
 *   - Migration 0073 — `tt_user_mfa` (encrypted secret, hashed backup
 *     codes, remembered devices, rate-limit columns).
 *   - `Domain\TotpService` — RFC 6238 generate + verify.
 *   - `Domain\BackupCodesService` — generate, hash, verify single-use.
 *   - `Domain\QrCodeRenderer` — pure-PHP byte-mode QR encoder used by
 *     the enrollment wizard's secret step.
 *   - `MfaSecretsRepository` — CRUD on `tt_user_mfa`.
 *   - Account-page status tab — surfaces enrolled / not-enrolled state
 *     and the entry point to enrollment.
 *   - 4-step enrollment wizard registered in WizardRegistry (intro →
 *     secret + QR code → first-code verification → backup codes).
 *   - Regenerate-backup-codes + disable-MFA admin-post actions.
 *
 * Sprint 3 (last):
 *   - WordPress `authenticate` filter integration — after WP's password
 *     check, before the session cookie issues.
 *   - Per-club setting `require_mfa_for_personas` (default
 *     `[ academy_admin, head_of_development ]`); on login, enrolled
 *     users in gated personas verify TOTP, un-enrolled users in gated
 *     personas redirect to the enrollment wizard.
 *   - Rate-limited verification: 5 attempts / 5 minutes, then 15-minute
 *     lockout. `failed_attempts` + `locked_until` columns from migration 0073.
 *   - Optional 30-day "remember this device" cookie. `remembered_devices`
 *     column from migration 0073.
 *   - Audit-log integration — `mfa_enrolled` / `mfa_verified` /
 *     `mfa_lockout` / `mfa_backup_code_used` / `mfa_disabled`.
 */
class MfaModule implements ModuleInterface {

    public function getName(): string { return 'mfa'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        WizardRegistry::register( new MfaEnrollmentWizard() );
        MfaActionHandlers::init();
    }
}
