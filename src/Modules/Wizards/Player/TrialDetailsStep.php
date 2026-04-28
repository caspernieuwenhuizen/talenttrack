<?php
namespace TT\Modules\Wizards\Player;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2B — Trial path.
 *
 * Captures the minimum needed to open a #0017 trial case: name +
 * date of birth (optional) + the team being trialed for + which
 * track + start/end dates. The review step uses this to call
 * `TrialCasesRepository::create()` after first creating the player
 * record (so the trial case has a real `player_id` to attach to).
 */
final class TrialDetailsStep implements WizardStepInterface {

    public function slug(): string { return 'trial-details'; }
    public function label(): string { return __( 'Trial details', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb;
        $teams = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}tt_teams ORDER BY name ASC LIMIT 200" );
        $tracks = [];
        if ( class_exists( '\\TT\\Modules\\Trials\\Repositories\\TrialTracksRepository' ) ) {
            $tracks = ( new \TT\Modules\Trials\Repositories\TrialTracksRepository() )->listAll( false );
        }

        echo '<label><span>' . esc_html__( 'First name', 'talenttrack' ) . ' *</span><input type="text" name="first_name" required autocomplete="given-name" value="' . esc_attr( (string) ( $state['first_name'] ?? '' ) ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Last name', 'talenttrack' ) . ' *</span><input type="text" name="last_name" required autocomplete="family-name" value="' . esc_attr( (string) ( $state['last_name'] ?? '' ) ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Date of birth', 'talenttrack' ) . '</span><input type="date" name="date_of_birth" value="' . esc_attr( (string) ( $state['date_of_birth'] ?? '' ) ) . '"></label>';

        echo '<label><span>' . esc_html__( 'Team being trialed for', 'talenttrack' ) . '</span><select name="team_id">';
        echo '<option value="0">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        $current_team = (int) ( $state['team_id'] ?? 0 );
        foreach ( $teams as $t ) {
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . selected( $current_team, (int) $t->id, false ) . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        if ( $tracks ) {
            $current_track = (int) ( $state['trial_track_id'] ?? $tracks[0]->id );
            echo '<label><span>' . esc_html__( 'Trial track', 'talenttrack' ) . '</span><select name="trial_track_id">';
            foreach ( $tracks as $tr ) {
                echo '<option value="' . esc_attr( (string) $tr->id ) . '" data-days="' . esc_attr( (string) $tr->default_duration_days ) . '" ' . selected( $current_track, (int) $tr->id, false ) . '>' . esc_html( (string) $tr->name ) . '</option>';
            }
            echo '</select></label>';
        } else {
            echo '<p class="tt-notice">' . esc_html__( 'The Trials module is not active. The trial case won\'t be created — the player will get status "trial" so you can come back later.', 'talenttrack' ) . '</p>';
        }

        $today = gmdate( 'Y-m-d' );
        $default_days = $tracks ? (int) $tracks[0]->default_duration_days : 28;
        $default_end  = gmdate( 'Y-m-d', time() + $default_days * 86400 );

        echo '<label><span>' . esc_html__( 'Trial start date', 'talenttrack' ) . '</span><input type="date" name="trial_start_date" value="' . esc_attr( (string) ( $state['trial_start_date'] ?? $today ) ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Trial end date', 'talenttrack' ) . '</span><input type="date" name="trial_end_date" value="' . esc_attr( (string) ( $state['trial_end_date'] ?? $default_end ) ) . '"></label>';
    }

    public function validate( array $post, array $state ) {
        $first = isset( $post['first_name'] ) ? sanitize_text_field( wp_unslash( (string) $post['first_name'] ) ) : '';
        $last  = isset( $post['last_name'] )  ? sanitize_text_field( wp_unslash( (string) $post['last_name'] ) )  : '';
        if ( $first === '' || $last === '' ) {
            return new \WP_Error( 'name_required', __( 'First and last name are required.', 'talenttrack' ) );
        }
        return [
            'first_name'        => $first,
            'last_name'         => $last,
            'date_of_birth'     => isset( $post['date_of_birth'] ) ? sanitize_text_field( wp_unslash( (string) $post['date_of_birth'] ) ) : '',
            'team_id'           => isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0,
            'trial_track_id'    => isset( $post['trial_track_id'] ) ? absint( $post['trial_track_id'] ) : 0,
            'trial_start_date'  => isset( $post['trial_start_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['trial_start_date'] ) ) : gmdate( 'Y-m-d' ),
            'trial_end_date'    => isset( $post['trial_end_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['trial_end_date'] ) ) : gmdate( 'Y-m-d', time() + 28 * 86400 ),
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
