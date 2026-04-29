<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

final class PlayerStep implements WizardStepInterface {

    public function slug(): string { return 'player'; }
    public function label(): string { return __( 'Player', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb;
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, status FROM {$wpdb->prefix}tt_players
             WHERE archived_at IS NULL AND club_id = %d
             ORDER BY last_name, first_name LIMIT 500",
            CurrentClub::id()
        ) );
        $current = (int) ( $state['player_id'] ?? 0 );
        echo '<label><span>' . esc_html__( 'Which player are you evaluating?', 'talenttrack' ) . '</span><select name="player_id" required>';
        echo '<option value="">' . esc_html__( '— pick a player —', 'talenttrack' ) . '</option>';
        foreach ( $players as $p ) {
            $name = trim( ( (string) ( $p->first_name ?? '' ) ) . ' ' . ( (string) ( $p->last_name ?? '' ) ) ) ?: '#' . (int) $p->id;
            echo '<option value="' . esc_attr( (string) $p->id ) . '" ' . selected( $current, (int) $p->id, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $pid = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
        if ( $pid <= 0 ) return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        return [ 'player_id' => $pid ];
    }

    public function nextStep( array $state ): ?string { return 'type'; }
    public function submit( array $state ) { return null; }
}
