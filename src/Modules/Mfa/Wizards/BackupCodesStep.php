<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Audit\MfaAuditEvents;
use TT\Modules\Mfa\Auth\MfaLoginGuard;
use TT\Modules\Mfa\Domain\BackupCodesService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Backup codes. Final step. Generates 10 single-use recovery
 * codes (lazy / idempotent across re-renders), persists the hashes,
 * displays the plaintext codes ONCE, and asks the user to confirm
 * they have saved them before submitting.
 *
 * Plaintext-code lifecycle:
 *   - Generated at first render. Plaintext stashed in wizard state so
 *     re-renders (validation failures, F5) show the same codes — telling
 *     the user "you saw codes once, here they are again" is better than
 *     "I generated different codes; the ones you wrote down are wrong."
 *   - Persisted hashes go into `tt_user_mfa.backup_codes_hashed` via
 *     `MfaSecretsRepository::updateBackupCodes()`.
 *   - On submit, `WizardState::clear()` is called by the wizard view —
 *     transient + persistent draft row both deleted. The plaintext is
 *     gone from the wizard; only the user's saved copy persists.
 */
final class BackupCodesStep implements WizardStepInterface {

    public function slug(): string { return 'backup-codes'; }
    public function label(): string { return __( 'Save backup codes', 'talenttrack' ); }

    public function render( array $state ): void {
        $user_id = get_current_user_id();
        $repo    = new MfaSecretsRepository();

        $plaintext = isset( $state['backup_codes_plaintext'] ) && is_array( $state['backup_codes_plaintext'] )
            ? array_values( array_filter( array_map( 'strval', $state['backup_codes_plaintext'] ) ) )
            : [];

        if ( count( $plaintext ) !== BackupCodesService::CODE_COUNT ) {
            // Lazy-generate: this is the only path on first render of this step.
            $generated = BackupCodesService::generate();
            $repo->updateBackupCodes( $user_id, $generated['storage'] );
            $plaintext = $generated['plaintext'];

            // Stash plaintext in wizard state so a re-render shows the same
            // codes. Cleared on submit by WizardState::clear().
            \TT\Shared\Wizards\WizardState::merge(
                $user_id,
                MfaEnrollmentWizard::SLUG,
                [ 'backup_codes_plaintext' => $plaintext ]
            );
        }

        echo '<p>'
            . esc_html__( "These 10 codes let you sign in if you lose your phone. Each code works once. Store them somewhere safe — a password manager, a printed copy in a drawer, anywhere not on the same phone.", 'talenttrack' )
            . '</p>';

        echo '<div style="background:#fafafa; border:1px solid #ddd; padding:16px 20px; margin:24px 0; max-width:520px;">';
        echo '<ol style="margin:0; padding-left:24px; font-family:monospace; font-size:16px; line-height:1.8; columns:2; column-gap:32px;">';
        foreach ( $plaintext as $code ) {
            echo '<li style="break-inside:avoid;">' . esc_html( $code ) . '</li>';
        }
        echo '</ol>';
        echo '</div>';

        echo '<p style="margin:16px 0;">';
        echo '<button type="button" class="button" onclick="navigator.clipboard?.writeText(' . esc_attr( wp_json_encode( implode( "\n", $plaintext ) ) ) . ')">'
            . esc_html__( 'Copy all to clipboard', 'talenttrack' )
            . '</button>';
        echo ' <button type="button" class="button" onclick="window.print()">'
            . esc_html__( 'Print', 'talenttrack' )
            . '</button>';
        echo '</p>';

        echo '<p style="margin-top:24px;">';
        echo '<label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer; max-width:560px;">';
        echo '<input type="checkbox" name="confirm_saved" value="1" required style="margin-top:4px;">';
        echo '<span>' . esc_html__( "I have saved these codes somewhere safe and I understand that I won't see them again after I click Finish.", 'talenttrack' ) . '</span>';
        echo '</label>';
        echo '</p>';
    }

    public function validate( array $post, array $state ) {
        if ( empty( $post['confirm_saved'] ) ) {
            return new \WP_Error(
                'mfa_backup_not_confirmed',
                __( 'Tick the checkbox to confirm you have saved your backup codes.', 'talenttrack' )
            );
        }
        return [];
    }

    public function nextStep( array $state ): ?string {
        return null;
    }

    public function submit( array $state ) {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error(
                'mfa_not_logged_in',
                __( 'You must be logged in to finish enrollment.', 'talenttrack' )
            );
        }

        $user_id = get_current_user_id();
        $repo    = new MfaSecretsRepository();
        // Atomically enroll: flip `enrolled_at` only at this final step
        // so the user can never end up enrolled without their recovery
        // codes (see VerifyStep::validate for the rationale).
        if ( ! $repo->markEnrolled( $user_id ) ) {
            return new \WP_Error(
                'mfa_persist_failed',
                __( 'Could not finalise enrollment. Please try again.', 'talenttrack' )
            );
        }
        MfaAuditEvents::record( MfaAuditEvents::ENROLLED, $user_id );
        // The login-guard's "must enroll" flag (sprint 3) clears here so
        // the gated user, redirected mid-flow, isn't sent right back to
        // the wizard after finishing it. The "pending" challenge flag
        // stays clear because enrollment implies a verified TOTP code on
        // step 3 already.
        MfaLoginGuard::clearPending( $user_id );

        // Final destination: the Account-page MFA tab, with a one-shot
        // success message. The wizard framework will clear our state
        // (including the plaintext backup codes) when this submit
        // returns successfully.
        $url = add_query_arg(
            [
                'page'    => 'tt-account',
                'tab'     => 'mfa',
                'tt_msg'  => 'mfa_enrolled',
            ],
            admin_url( 'admin.php' )
        );
        return [ 'redirect_url' => $url ];
    }
}
