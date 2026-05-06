<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — Intro. Explains what MFA is, what the user is about to do,
 * and what authenticator apps work. No form fields; clicking "Continue"
 * advances to the secret step.
 *
 * If the user already has an enrolled row, surface a notice that
 * proceeding will replace their secret. Re-enrollment is a legitimate
 * flow (lost-device recovery; switch authenticator app), so we don't
 * block it — we warn.
 */
final class IntroStep implements WizardStepInterface {

    public function slug(): string { return 'intro'; }
    public function label(): string { return __( 'Intro', 'talenttrack' ); }

    public function render( array $state ): void {
        $repo        = new MfaSecretsRepository();
        $is_enrolled = $repo->isEnrolled( get_current_user_id() );

        echo '<p>'
            . esc_html__( "MFA adds a second factor to your TalentTrack login. After typing your password you'll also enter a 6-digit code from an authenticator app. Even if someone learns your password, they can't sign in without your phone.", 'talenttrack' )
            . '</p>';

        echo '<h3 style="margin-top:24px;">' . esc_html__( "What you'll need", 'talenttrack' ) . '</h3>';
        echo '<ul style="padding-left:20px;">';
        echo '<li>' . esc_html__( "An authenticator app on your phone — Google Authenticator, Authy, 1Password, Microsoft Authenticator, or any other RFC 6238 TOTP app.", 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( '~2 minutes to scan a QR code, type a verification code, and save your backup codes.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'A safe place to store 10 single-use backup codes (a password manager works well).', 'talenttrack' ) . '</li>';
        echo '</ul>';

        if ( $is_enrolled ) {
            echo '<div class="notice notice-warning" style="padding:12px 16px; margin:24px 0; max-width:760px;">';
            echo '<p style="margin:0;"><strong>' . esc_html__( 'You are already enrolled.', 'talenttrack' ) . '</strong> '
                . esc_html__( 'Continuing will replace your existing secret and backup codes — useful if you lost your phone or want to switch authenticator app. Your old codes will stop working.', 'talenttrack' )
                . '</p>';
            echo '</div>';
        }

        echo '<p style="margin-top:24px;">'
            . esc_html__( "Click Continue to generate your shared secret. The next screen shows the QR code your authenticator app scans.", 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        return [];
    }

    public function nextStep( array $state ): ?string {
        return 'secret';
    }

    public function submit( array $state ) {
        return null;
    }
}
