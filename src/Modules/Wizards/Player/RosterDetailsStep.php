<?php
namespace TT\Modules\Wizards\Player;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2A — Roster path. Collects the standard tt_players fields.
 *
 * Field set is intentionally trimmed to the ones a coach needs to
 * answer "where is this player now?" right away — additional fields
 * (height/weight, parent contacts, photo) live on the regular edit
 * form and can be added afterwards.
 */
final class RosterDetailsStep implements WizardStepInterface {

    public function slug(): string { return 'roster-details'; }
    public function label(): string { return __( 'Player details', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb;
        $teams = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}tt_teams ORDER BY name ASC LIMIT 200" );

        echo '<label><span>' . esc_html__( 'First name', 'talenttrack' ) . ' *</span><input type="text" name="first_name" required autocomplete="given-name" value="' . esc_attr( (string) ( $state['first_name'] ?? '' ) ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Last name', 'talenttrack' ) . ' *</span><input type="text" name="last_name" required autocomplete="family-name" value="' . esc_attr( (string) ( $state['last_name'] ?? '' ) ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Date of birth', 'talenttrack' ) . '</span><input type="date" name="date_of_birth" value="' . esc_attr( (string) ( $state['date_of_birth'] ?? '' ) ) . '"></label>';

        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span><select name="team_id">';
        echo '<option value="0">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        $current_team = (int) ( $state['team_id'] ?? 0 );
        foreach ( $teams as $t ) {
            $sel = selected( $current_team, (int) $t->id, false );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Jersey number', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" min="0" max="99" name="jersey_number" value="' . esc_attr( (string) ( $state['jersey_number'] ?? '' ) ) . '"></label>';

        echo '<label><span>' . esc_html__( 'Preferred foot', 'talenttrack' ) . '</span><select name="preferred_foot">';
        $foot = (string) ( $state['preferred_foot'] ?? '' );
        foreach ( [ '', 'left', 'right', 'both' ] as $f ) {
            $label = $f === '' ? __( '— not specified —', 'talenttrack' ) : ucfirst( $f );
            echo '<option value="' . esc_attr( $f ) . '" ' . selected( $foot, $f, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $first = isset( $post['first_name'] ) ? sanitize_text_field( wp_unslash( (string) $post['first_name'] ) ) : '';
        $last  = isset( $post['last_name'] )  ? sanitize_text_field( wp_unslash( (string) $post['last_name'] ) )  : '';
        if ( $first === '' || $last === '' ) {
            return new \WP_Error( 'name_required', __( 'First and last name are required.', 'talenttrack' ) );
        }
        $dob = isset( $post['date_of_birth'] ) ? sanitize_text_field( wp_unslash( (string) $post['date_of_birth'] ) ) : '';
        $team = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
        $jersey_raw = $post['jersey_number'] ?? '';
        $foot = isset( $post['preferred_foot'] ) ? sanitize_key( (string) $post['preferred_foot'] ) : '';
        if ( ! in_array( $foot, [ '', 'left', 'right', 'both' ], true ) ) $foot = '';

        return [
            'first_name'     => $first,
            'last_name'      => $last,
            'date_of_birth'  => $dob,
            'team_id'        => $team,
            'jersey_number'  => $jersey_raw === '' ? null : absint( $jersey_raw ),
            'preferred_foot' => $foot,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
