<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardInterface;

/**
 * The MFA enrollment wizard (#0086 Workstream B Child 1, sprint 2).
 *
 * Five steps:
 *   1. `intro`         — what MFA is and what the user is about to do.
 *   2. `reauth`        — only when already enrolled: a code from the
 *                        current authenticator or a backup code.
 *   3. `secret`        — stage a new secret, show the QR code + manual
 *                        entry fallback.
 *   4. `verify`        — the first 6-digit code from the staged secret.
 *   5. `backup-codes`  — stage 10 single-use recovery codes, show them
 *                        once; Finish promotes the staged secret and codes.
 *
 * Capability: `read` — every logged-in user can enroll their own MFA;
 * each tt_user_mfa row is keyed by (wp_user_id, club_id).
 *
 * First enrolment and setting it up again take the same path (#4943):
 * the new secret and codes live in the row's pending columns and replace
 * the working ones only on Finish, so an abandoned wizard leaves the
 * working setup, the backup codes and the lockout counters as they were.
 * The plaintext backup codes are kept in wizard state only so the last
 * step can show them; they are inert until promotion.
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
            new ReauthStep(),
            new SecretStep(),
            new VerifyStep(),
            new BackupCodesStep(),
        ];
    }

    /**
     * Server-side guard for every step after `reauth`. An account that is
     * already enrolled must have confirmed its current code in this run,
     * inside the TTL; the step order alone is not the check, because the
     * runner's Back-jump and Skip can reach any step.
     *
     * @param array<string,mixed> $state
     */
    public static function reauthSatisfied( int $wp_user_id, array $state ): bool {
        if ( ! ( new MfaSecretsRepository() )->isEnrolled( $wp_user_id ) ) return true;
        $at = (int) ( $state['reauth_at'] ?? 0 );
        if ( $at <= 0 || ( time() - $at ) > MfaSecretsRepository::PENDING_TTL ) return false;

        // Wizard state is keyed on (user, wizard), so every session of the
        // account reads it. The confirmation counts only in the session
        // that made it.
        $bound   = (string) ( $state['reauth_session'] ?? '' );
        $current = self::sessionBinding();
        return $bound !== '' && $current !== '' && hash_equals( $bound, $current );
    }

    /** A hash of the current login session's token, or '' without one. */
    public static function sessionBinding(): string {
        $token = wp_get_session_token();
        return $token === '' ? '' : hash( 'sha256', $token );
    }
}
