<?php
namespace TT\Modules\Wizards\Person;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — first / last / email / phone.
 *
 * Email is optional but encouraged: parent + scout flows lean on
 * email. Phone is optional and used by #0042 push routing.
 */
final class BasicsStep implements WizardStepInterface {

    public function slug(): string { return 'basics'; }
    public function label(): string { return __( 'Basics', 'talenttrack' ); }

    public function render( array $state ): void {
        $first = (string) ( $state['first_name'] ?? '' );
        $last  = (string) ( $state['last_name']  ?? '' );
        $email = (string) ( $state['email']      ?? '' );
        $phone = (string) ( $state['phone']      ?? '' );
        ?>
        <label>
            <span><?php esc_html_e( 'First name', 'talenttrack' ); ?> *</span>
            <input type="text" name="first_name" value="<?php echo esc_attr( $first ); ?>" required autocomplete="given-name" />
        </label>
        <label>
            <span><?php esc_html_e( 'Last name', 'talenttrack' ); ?> *</span>
            <input type="text" name="last_name" value="<?php echo esc_attr( $last ); ?>" required autocomplete="family-name" />
        </label>
        <label>
            <span><?php esc_html_e( 'Email', 'talenttrack' ); ?></span>
            <input type="email" name="email" value="<?php echo esc_attr( $email ); ?>" inputmode="email" autocomplete="email" />
        </label>
        <label>
            <span><?php esc_html_e( 'Phone', 'talenttrack' ); ?></span>
            <input type="tel" name="phone" value="<?php echo esc_attr( $phone ); ?>" inputmode="tel" autocomplete="tel" placeholder="+31612345678" />
        </label>
        <?php
    }

    public function validate( array $post, array $state ) {
        $first = isset( $post['first_name'] ) ? sanitize_text_field( (string) $post['first_name'] ) : '';
        $last  = isset( $post['last_name']  ) ? sanitize_text_field( (string) $post['last_name']  ) : '';
        $email = isset( $post['email'] )      ? sanitize_email( (string) $post['email'] )          : '';
        $phone = isset( $post['phone'] )      ? sanitize_text_field( (string) $post['phone'] )     : '';

        if ( $first === '' || $last === '' ) {
            return new \WP_Error( 'missing_name', __( 'First and last name are required.', 'talenttrack' ) );
        }
        if ( $email !== '' && ! is_email( $email ) ) {
            return new \WP_Error( 'bad_email', __( 'Email address looks invalid.', 'talenttrack' ) );
        }
        return [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'phone'      => $phone,
        ];
    }

    public function nextStep( array $state ): ?string { return 'role'; }
    public function submit( array $state ) { return null; }
}
