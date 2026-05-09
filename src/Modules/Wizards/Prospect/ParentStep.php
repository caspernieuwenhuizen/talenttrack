<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — parent_name / parent_email / parent_phone / consent.
 *
 * The parent contact powers the InviteToTestTraining task's invitation
 * email. Email and phone are optional individually, but at least one
 * channel must be supplied so the HoD can reach the parent. Consent is
 * a checkbox — required if parent contact is captured (GDPR/DPIA per
 * `marketing/security/dpa-template.md`).
 */
final class ParentStep implements WizardStepInterface {

    public function slug(): string { return 'parent'; }
    public function label(): string { return __( 'Parent contact', 'talenttrack' ); }

    public function render( array $state ): void {
        $name   = (string) ( $state['parent_name']     ?? '' );
        $email  = (string) ( $state['parent_email']    ?? '' );
        $phone  = (string) ( $state['parent_phone']    ?? '' );
        $consent = ! empty( $state['consent_given'] );
        ?>
        <label>
            <span><?php esc_html_e( 'Parent name', 'talenttrack' ); ?></span>
            <input type="text" name="parent_name" value="<?php echo esc_attr( $name ); ?>" autocomplete="name" />
        </label>
        <label>
            <span><?php esc_html_e( 'Parent email', 'talenttrack' ); ?></span>
            <input type="email" name="parent_email" value="<?php echo esc_attr( $email ); ?>" inputmode="email" autocomplete="email" />
        </label>
        <label>
            <span><?php esc_html_e( 'Parent phone', 'talenttrack' ); ?></span>
            <input type="tel" name="parent_phone" value="<?php echo esc_attr( $phone ); ?>" inputmode="tel" autocomplete="tel" placeholder="+31612345678" />
        </label>
        <label style="display:block; margin-top:12px;">
            <input type="checkbox" name="consent_given" value="1" <?php checked( $consent ); ?> />
            <?php esc_html_e( 'Parent has given consent for the academy to hold this contact information', 'talenttrack' ); ?>
        </label>
        <?php
    }

    public function validate( array $post, array $state ) {
        $name  = isset( $post['parent_name']  ) ? sanitize_text_field( (string) $post['parent_name']  ) : '';
        $email = isset( $post['parent_email'] ) ? sanitize_email( (string) $post['parent_email'] )      : '';
        $phone = isset( $post['parent_phone'] ) ? sanitize_text_field( (string) $post['parent_phone'] ) : '';
        $consent = ! empty( $post['consent_given'] );

        if ( $email !== '' && ! is_email( $email ) ) {
            return new \WP_Error( 'bad_email', __( 'Enter a valid parent email or leave it blank.', 'talenttrack' ) );
        }
        // At least one contact channel — otherwise the HoD has no way
        // to reach the parent for the test-training invitation.
        if ( $email === '' && $phone === '' ) {
            return new \WP_Error( 'no_contact', __( 'Add at least an email or a phone number so the HoD can reach the parent.', 'talenttrack' ) );
        }
        // Consent is required if any contact data is captured.
        if ( ( $email !== '' || $phone !== '' ) && ! $consent ) {
            return new \WP_Error( 'no_consent', __( 'Tick the consent box — the academy may only hold parent contact data with consent.', 'talenttrack' ) );
        }

        return [
            'parent_name'   => $name,
            'parent_email'  => $email,
            'parent_phone'  => $phone,
            'consent_given' => $consent ? 1 : 0,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
