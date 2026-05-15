<?php
namespace TT\Modules\Wizards\Goal;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — pick the player this goal is for.
 *
 * v3.110.101 — was a club-wide `<select>` of all players ordered by
 * last name, which ignored team scoping. A head-coach assigned only
 * to O13 saw O14 players in the dropdown. Replaced with
 * `PlayerSearchPickerComponent`, which delegates to the
 * `resolvePlayers( $user_id, $is_admin )` helper that the player
 * picker uses elsewhere — head-coach scope is automatically applied
 * via the same chain that drives the picker on the new-evaluation
 * wizard, players list, etc. Admin / HoD see the full club;
 * head-coach / assistant-coach see only their team's roster.
 */
final class PlayerStep implements WizardStepInterface {

    public function slug(): string { return 'player'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    public function render( array $state ): void {
        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        $current  = (int) ( $state['player_id'] ?? 0 );

        echo PlayerSearchPickerComponent::render( [
            'name'             => 'player_id',
            'label'            => __( 'Which player is this goal for?', 'talenttrack' ),
            'required'         => true,
            'user_id'          => $user_id,
            'is_admin'         => $is_admin,
            'selected'         => $current,
            'show_team_filter' => true,
            'placeholder'      => __( 'Type a name to search…', 'talenttrack' ),
        ] );
    }

    public function validate( array $post, array $state ) {
        $pid = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        if ( $pid <= 0 ) return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        return [ 'player_id' => $pid ];
    }

    public function nextStep( array $state ): ?string { return 'link'; }
    public function submit( array $state ) { return null; }
}
