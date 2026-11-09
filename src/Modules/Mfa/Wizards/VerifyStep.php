<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Verify. The user types the first 6-digit code from their
 * authenticator app to prove the secret was added correctly. We verify
 * against the staged secret using the standard ±1-step (90s)
 * tolerance window.
 *
 * Validation outcomes:
 *   - empty / non-numeric / wrong length → WP_Error.
 *   - an enrolled account without a confirmed current code → WP_Error.
 *   - correct code → note `mfa_verified_at` in wizard state,
 *     advance to the backup-codes step.
 *   - incorrect code → WP_Error. We let the user retry indefinitely
 *     here; the rate-limit + lockout machinery is for the runtime
 *     login flow (sprint 3), not enrollment. An attacker without the
 *     secret can't guess a TOTP code anyway, and forcing a re-scan on
 *     every miss is poor UX for a user with a clock-skew problem.
 */
final class VerifyStep implements WizardStepInterface {

    public function slug(): string { return 'verify'; }
    public function label(): string { return __( 'Verify', 'talenttrack' ); }

    public function render( array $state ): void {
        echo '<p>'
            . esc_html__( 'Type the 6-digit code your authenticator app shows right now. The code rotates every 30 seconds — if it changes while you type, just wait for the next one and try again.', 'talenttrack' )
            . '</p>';
        echo '<p style="margin-top:24px;">';
        echo '<label for="tt-mfa-code" style="display:block; font-weight:600; margin-bottom:6px;">'
            . esc_html__( 'Verification code', 'talenttrack' )
            . '</label>';
        echo '<input type="text" id="tt-mfa-code" name="code" required '
            . 'autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]*" '
            . 'maxlength="11" autofocus '
            . 'placeholder="000 000" '
            . 'style="font-size:20px; letter-spacing:4px; padding:8px 12px; width:160px;">';
        echo '</p>';
        echo '<p style="color:#5b6e75; font-size:13px;">'
            . esc_html__( 'Spaces are tolerated — paste from the authenticator app if your phone offers that.', 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        $raw_code = isset( $post['code'] ) ? (string) wp_unslash( (string) $post['code'] ) : '';
        $raw_code = trim( $raw_code );
        if ( $raw_code === '' ) {
            return new \WP_Error(
                'mfa_code_empty',
                __( 'Type the 6-digit code from your authenticator app.', 'talenttrack' )
            );
        }
        $stripped = preg_replace( '/\s+/', '', $raw_code );
        if ( $stripped === null || ! ctype_digit( $stripped ) || strlen( $stripped ) !== 6 ) {
            return new \WP_Error(
                'mfa_code_format',
                __( 'The code is six digits. Try again with the current code from your authenticator app.', 'talenttrack' )
            );
        }

        $user_id = get_current_user_id();
        if ( ! MfaEnrollmentWizard::reauthSatisfied( $user_id, $state ) ) {
            return new \WP_Error(
                'mfa_current_not_confirmed',
                __( 'Confirm a code from your current setup first. Go back one step to enter it.', 'talenttrack' )
            );
        }

        // The staged secret is the one the user just scanned; the working
        // secret on an enrolled row is not.
        $secret = ( new MfaSecretsRepository() )->pendingSecret( $user_id );
        if ( $secret === '' ) {
            return new \WP_Error(
                'mfa_no_secret',
                __( 'The secret expired. Go back one step and scan the QR code again.', 'talenttrack' )
            );
        }

        if ( ! TotpService::verify( $secret, $stripped ) ) {
            return new \WP_Error(
                'mfa_code_mismatch',
                __( "That code didn't match. Make sure your phone's clock is correct, then try the current code from your authenticator.", 'talenttrack' )
            );
        }

        // Nothing is written here. The staged secret is promoted at the
        // BackupCodesStep submit, together with its codes, so a user can
        // never end up enrolled without recovery codes, and closing the
        // browser now leaves the working setup as it was.
        return [ 'mfa_verified_at' => time() ];
    }

    public function nextStep( array $state ): ?string {
        return 'backup-codes';
    }

    public function submit( array $state ) {
        return null;
    }
}
