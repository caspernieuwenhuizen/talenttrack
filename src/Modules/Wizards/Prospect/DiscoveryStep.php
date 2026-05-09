<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — discovered_at_event + scouting_notes.
 *
 * Both optional. Scouting notes is a free-text field (textarea) the
 * scout uses to describe the player's standout traits — the HoD reads
 * it on the InviteToTestTraining task to decide which test-training
 * slot fits best.
 */
final class DiscoveryStep implements WizardStepInterface {

    public function slug(): string { return 'discovery'; }
    public function label(): string { return __( 'Discovery', 'talenttrack' ); }

    public function render( array $state ): void {
        $event = (string) ( $state['discovered_at_event'] ?? '' );
        $notes = (string) ( $state['scouting_notes']      ?? '' );
        ?>
        <label>
            <span><?php esc_html_e( 'Discovered at (event / match)', 'talenttrack' ); ?></span>
            <input type="text" name="discovered_at_event" value="<?php echo esc_attr( $event ); ?>" placeholder="<?php esc_attr_e( 'e.g. Friendly vs. Sparta U13, 2 May', 'talenttrack' ); ?>" />
        </label>
        <label>
            <span><?php esc_html_e( 'Scouting notes', 'talenttrack' ); ?></span>
            <textarea name="scouting_notes" rows="4"><?php echo esc_textarea( $notes ); ?></textarea>
        </label>
        <?php
    }

    public function validate( array $post, array $state ) {
        $event = isset( $post['discovered_at_event'] ) ? sanitize_text_field( (string) $post['discovered_at_event'] ) : '';
        $notes = isset( $post['scouting_notes'] )      ? sanitize_textarea_field( (string) $post['scouting_notes'] )  : '';
        return [
            'discovered_at_event' => $event,
            'scouting_notes'      => $notes,
        ];
    }

    public function nextStep( array $state ): ?string { return 'parent'; }
    public function submit( array $state ) { return null; }
}
