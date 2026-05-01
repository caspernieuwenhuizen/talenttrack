<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * PlayerPickerStep (#0072) — entry to the player-first ad-hoc path.
 * Replaces today's `PlayerStep`. Reuses the existing
 * `PlayerSearchPickerComponent` so clubs with 200+ players have search;
 * smaller clubs get the dropdown fallback the component already
 * handles.
 */
final class PlayerPickerStep implements WizardStepInterface {

    public function slug(): string  { return 'player-picker'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        return ( $state['_path'] ?? '' ) === 'activity-first';
    }

    public function render( array $state ): void {
        $current = (int) ( $state['player_id'] ?? 0 );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Pick the player you\'re evaluating. Use this for ad-hoc observations not anchored to an activity row — a tournament moment, something you noticed in passing.', 'talenttrack' ); ?>
        </p>
        <?php
        echo PlayerSearchPickerComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'name'             => 'player_id',
            'label'            => __( 'Which player?', 'talenttrack' ),
            'required'         => true,
            'user_id'          => get_current_user_id(),
            'is_admin'         => current_user_can( 'tt_edit_settings' ),
            'selected'         => $current,
            'show_team_filter' => true,
            'cross_team'       => true,
        ] );
    }

    public function validate( array $post, array $state ) {
        $pid = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        if ( $pid <= 0 ) return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        return [ 'player_id' => $pid, '_path' => 'player-first' ];
    }

    public function nextStep( array $state ): ?string { return 'hybrid-deep-rate'; }
    public function submit( array $state ) { return null; }
}
