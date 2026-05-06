<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardInterface;

/**
 * The MFA enrollment wizard (#0086 Workstream B Child 1, sprint 2).
 *
 * Four steps:
 *   1. `intro`         — what MFA is and what the user is about to do.
 *   2. `secret`        — generate a fresh TOTP shared secret, display
 *                        the QR code + manual-entry fallback. The user
 *                        scans / types into their authenticator app.
 *   3. `verify`        — user types the first 6-digit code; we verify
 *                        against the just-generated secret. On success
 *                        the row in `tt_user_mfa` is marked enrolled.
 *   4. `backup-codes`  — show the 10 single-use recovery codes once.
 *                        User confirms they have saved them, wizard
 *                        finishes by redirecting to the Account-page
 *                        MFA tab.
 *
 * Capability: `read` — every logged-in user can enroll their own MFA;
 * each tt_user_mfa row is keyed by (wp_user_id, club_id) so no
 * cross-user concerns. The tab on the Account page provides the entry
 * point.
 *
 * Plaintext-credential lifetime in wizard state:
 *   - The TOTP secret is generated and persisted (encrypted) at step 2;
 *     never held plaintext in wizard state — step 3 reads + decrypts
 *     from `tt_user_mfa`.
 *   - The plaintext backup codes are generated at step 4 (after the
 *     verify step succeeds) and held in wizard state only for the
 *     duration of step 4's render. Submit clears the wizard state,
 *     which deletes the transient and the persistent draft row.
 *     Worst-case exposure window: ~1 hour transient TTL or ~14 days
 *     persistent draft TTL until the cleanup cron sweeps it.
 */
final class MfaEnrollmentWizard implements WizardInterface {

    public const SLUG = 'mfa-enroll';

    public function slug(): string { return self::SLUG; }
    public function label(): string { return __( 'Enable two-factor authentication', 'talenttrack' ); }
    public function requiredCap(): string { return 'read'; }
    public function firstStepSlug(): string { return 'intro'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new IntroStep(),
            new SecretStep(),
            new VerifyStep(),
            new BackupCodesStep(),
        ];
    }
}
