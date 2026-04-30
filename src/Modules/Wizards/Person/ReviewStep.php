<?php
namespace TT\Modules\Wizards\Person;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — confirm + create.
 *
 * `submit()` writes the row via `PeopleRepository::create()` and
 * returns a redirect URL. When the wizard was launched with a
 * `?return_to` arg (the parent-picker round-trip), the redirect
 * sends the user back there with the new person id appended on the
 * configured field — so the picker can pre-select.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $first = (string) ( $state['first_name'] ?? '' );
        $last  = (string) ( $state['last_name']  ?? '' );
        $email = (string) ( $state['email']      ?? '' );
        $phone = (string) ( $state['phone']      ?? '' );
        $role  = (string) ( $state['role_type']  ?? '' );
        ?>
        <p><?php esc_html_e( 'Confirm and create:', 'talenttrack' ); ?></p>
        <dl class="tt-profile-dl">
            <dt><?php esc_html_e( 'Name', 'talenttrack' ); ?></dt>
            <dd><?php echo esc_html( trim( $first . ' ' . $last ) ); ?></dd>
            <dt><?php esc_html_e( 'Role', 'talenttrack' ); ?></dt>
            <dd><?php echo esc_html( LabelTranslator::roleType( $role ) ); ?></dd>
            <?php if ( $email !== '' ) : ?>
                <dt><?php esc_html_e( 'Email', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $email ); ?></dd>
            <?php endif; ?>
            <?php if ( $phone !== '' ) : ?>
                <dt><?php esc_html_e( 'Phone', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $phone ); ?></dd>
            <?php endif; ?>
        </dl>
        <?php
    }

    public function validate( array $post, array $state ) {
        // Nothing to collect here — review is a confirmation surface.
        return [];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $repo = new PeopleRepository();
        $id   = $repo->create( [
            'first_name' => (string) ( $state['first_name'] ?? '' ),
            'last_name'  => (string) ( $state['last_name']  ?? '' ),
            'email'      => (string) ( $state['email']      ?? '' ),
            'phone'      => (string) ( $state['phone']      ?? '' ),
            'role_type'  => (string) ( $state['role_type']  ?? 'staff' ),
            'status'     => 'active',
        ] );
        if ( ! $id ) {
            return new \WP_Error( 'people_create_failed', __( 'Could not create the person record.', 'talenttrack' ) );
        }

        // Honour `?return_to=...&return_field=...` if the wizard was
        // launched from a picker round-trip (e.g. the parent picker
        // on the player edit form).
        $return_to    = isset( $_GET['return_to'] )    ? esc_url_raw( (string) $_GET['return_to'] )       : '';
        $return_field = isset( $_GET['return_field'] ) ? sanitize_key( (string) $_GET['return_field'] )   : '';
        if ( $return_to !== '' ) {
            $field = $return_field !== '' ? $return_field : 'parent_person_id';
            $url   = add_query_arg( $field, (int) $id, $return_to );
            return [ 'redirect_url' => $url ];
        }

        // Default: land on the person detail page.
        return [
            'redirect_url' => add_query_arg(
                [ 'tt_view' => 'people', 'id' => (int) $id ],
                home_url( '/' )
            ),
        ];
    }
}
