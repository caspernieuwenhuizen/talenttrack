<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Auth\CurrentFactorCheck;
use TT\Modules\Mfa\Auth\RateLimiter;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\ConditionalStep;
use TT\Shared\Wizards\ConditionalStepInterface;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — only when two-step verification is already set up. Asks for a
 * code from the current authenticator app or one of the backup codes
 * before a new secret is staged, so a session left open on someone else's
 * device cannot move the second factor to a new phone.
 *
 * The check is `CurrentFactorCheck`, shared with making new backup codes
 * and turning two-step off. A match is noted as `reauth_at` in wizard
 * state and nothing else; the counters are not reset here.
 */
final class ReauthStep implements WizardStepInterface, ConditionalStepInterface {

    use ConditionalStep;

    public function slug(): string { return 'reauth'; }
    public function label(): string { return __( 'Confirm current code', 'talenttrack' ); }

    /** @param array<string,mixed> $state */
    public function notApplicableFor( array $state ): bool {
        return ! ( new MfaSecretsRepository() )->isEnrolled( get_current_user_id() );
    }

    public function render( array $state ): void {
        if ( ( new RateLimiter() )->isLockedOut( get_current_user_id() ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'Too many codes did not match. Try again in a few minutes.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<p>'
            . esc_html__( 'Two-step verification is already set up on this account. Enter the 6-digit code your current authenticator app shows, or one of your backup codes. Your current setup keeps working until you confirm a code from the new one.', 'talenttrack' )
            . '</p>';
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-mfa-current-code">'
            . esc_html__( 'Current code or backup code', 'talenttrack' )
            . '</label>';
        echo '<input type="text" id="tt-mfa-current-code" name="current_code" class="tt-input" required '
            . 'autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="20">';
        echo '<p class="tt-field-hint">'
            . esc_html__( 'Spaces and dashes are ignored. A backup code used here is spent.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $raw    = isset( $post['current_code'] ) ? (string) wp_unslash( (string) $post['current_code'] ) : '';
        $result = ( new CurrentFactorCheck() )->verify( get_current_user_id(), $raw );

        if ( $result === CurrentFactorCheck::OK ) return [ 'reauth_at' => time() ];
        // Nothing to confirm against: the run continues as a first enrolment.
        if ( $result === CurrentFactorCheck::NOT_ENROLLED ) return [];

        return new \WP_Error( 'mfa_current_code_' . $result, CurrentFactorCheck::message( $result ) );
    }

    public function nextStep( array $state ): string {
        return 'secret';
    }

    /**
     * Never the last step: the runner submits only where `nextStep()` is null.
     *
     * @return array<string,mixed>
     */
    public function submit( array $state ) {
        return [];
    }
}
