<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — Pick the player to evaluate.
 *
 * #0061 — replaced the long `<select>` with `PlayerSearchPickerComponent`
 * (autocomplete) so the wizard scales beyond a few-hundred-player roster
 * and matches the v3.49.0 trial-case create form's UX.
 */
final class PlayerStep implements WizardStepInterface {

    public function slug(): string { return 'player'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    public function render( array $state ): void {
        $current = (int) ( $state['player_id'] ?? 0 );
        echo PlayerSearchPickerComponent::render( [
            'name'             => 'player_id',
            'label'            => __( 'Which player are you evaluating?', 'talenttrack' ),
            'required'         => true,
            'user_id'          => get_current_user_id(),
            'is_admin'         => current_user_can( 'tt_edit_settings' ),
            'selected'         => $current,
            'show_team_filter' => true,
            'cross_team'       => true,
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function validate( array $post, array $state ) {
        $pid = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        if ( $pid <= 0 ) return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        return [ 'player_id' => $pid ];
    }

    public function nextStep( array $state ): ?string { return 'type'; }
    public function submit( array $state ) { return null; }
}
