<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — Basics. Name, anchor team, start_date, optional end_date.
 *
 * Anchor team is required per the spec shaping decision (single-team
 * tournament, with cross-team adds via the squad step's "from another
 * team" affordance).
 */
final class BasicsStep implements WizardStepInterface {

    public function slug(): string { return 'basics'; }
    public function label(): string { return __( 'Basics', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb;
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}tt_teams WHERE club_id = %d AND archived_at IS NULL ORDER BY name ASC LIMIT 200",
            CurrentClub::id()
        ) );

        echo '<label><span>' . esc_html__( 'Tournament name', 'talenttrack' ) . ' *</span>';
        echo '<input type="text" name="name" required value="' . esc_attr( (string) ( $state['name'] ?? '' ) ) . '"></label>';

        echo '<label><span>' . esc_html__( 'Anchor team', 'talenttrack' ) . ' *</span><select name="team_id" required>';
        echo '<option value="0">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        $current_team = (int) ( $state['team_id'] ?? 0 );
        foreach ( $teams as $t ) {
            $sel = selected( $current_team, (int) $t->id, false );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Start date', 'talenttrack' ) . ' *</span>';
        echo '<input type="date" name="start_date" required value="' . esc_attr( (string) ( $state['start_date'] ?? '' ) ) . '"></label>';

        echo '<label><span>' . esc_html__( 'End date (optional, for multi-day tournaments)', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="end_date" value="' . esc_attr( (string) ( $state['end_date'] ?? '' ) ) . '"></label>';
    }

    public function validate( array $post, array $state ) {
        $name = isset( $post['name'] ) ? sanitize_text_field( wp_unslash( (string) $post['name'] ) ) : '';
        if ( $name === '' ) {
            return new \WP_Error( 'name_required', __( 'Tournament name is required.', 'talenttrack' ) );
        }
        $team_id = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
        if ( $team_id <= 0 ) {
            return new \WP_Error( 'team_required', __( 'Pick an anchor team.', 'talenttrack' ) );
        }
        $start = isset( $post['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['start_date'] ) ) : '';
        if ( $start === '' ) {
            return new \WP_Error( 'start_date_required', __( 'Start date is required.', 'talenttrack' ) );
        }
        $end = isset( $post['end_date'] ) ? sanitize_text_field( wp_unslash( (string) $post['end_date'] ) ) : '';
        if ( $end !== '' && $end < $start ) {
            return new \WP_Error( 'end_before_start', __( 'End date cannot be before the start date.', 'talenttrack' ) );
        }
        return [
            'name'       => $name,
            'team_id'    => $team_id,
            'start_date' => $start,
            'end_date'   => $end,
        ];
    }

    public function nextStep( array $state ): ?string { return 'formation'; }
    public function submit( array $state ) { return null; }
}
