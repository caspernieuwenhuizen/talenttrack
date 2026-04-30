<?php
namespace TT\Modules\Wizards\Person;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — pick role_type.
 *
 * Honours the `?role_hint=parent` query arg sent by
 * ParentSearchPickerComponent so the role is pre-selected when the
 * wizard is launched from the player edit form's "Create a parent
 * account" link.
 */
final class RoleStep implements WizardStepInterface {

    public function slug(): string { return 'role'; }
    public function label(): string { return __( 'Role', 'talenttrack' ); }

    public function render( array $state ): void {
        $current = (string) ( $state['role_type'] ?? '' );
        if ( $current === '' ) {
            $hint = isset( $_GET['role_hint'] ) ? sanitize_key( (string) $_GET['role_hint'] ) : '';
            if ( $hint !== '' && in_array( $hint, PeopleRepository::ROLE_TYPES, true ) ) {
                $current = $hint;
            } else {
                $current = 'staff';
            }
        }
        ?>
        <p><?php esc_html_e( 'What is this person\'s role at the academy?', 'talenttrack' ); ?></p>
        <fieldset>
            <legend><?php esc_html_e( 'Role', 'talenttrack' ); ?></legend>
            <?php foreach ( PeopleRepository::ROLE_TYPES as $code ) :
                $label = LabelTranslator::roleType( $code );
                ?>
                <label style="display:block;">
                    <input type="radio" name="role_type" value="<?php echo esc_attr( $code ); ?>" <?php checked( $current === $code ); ?> />
                    <?php echo esc_html( $label ); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    public function validate( array $post, array $state ) {
        $role = isset( $post['role_type'] ) ? sanitize_key( (string) $post['role_type'] ) : '';
        if ( ! in_array( $role, PeopleRepository::ROLE_TYPES, true ) ) {
            return new \WP_Error( 'bad_role', __( 'Pick a role from the list.', 'talenttrack' ) );
        }
        return [ 'role_type' => $role ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
