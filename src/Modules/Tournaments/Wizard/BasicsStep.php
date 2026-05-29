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
 *
 * No explicit Format field — the tournament's age tier derives from
 * the anchor team's age_group server-side (decision locked in #975
 * 2026-05-28). Coach picks the team; the system picks the rest.
 */
final class BasicsStep implements WizardStepInterface {

    public function slug(): string { return 'basics'; }
    public function label(): string { return __( 'Basics', 'talenttrack' ); }

    public function render( array $state ): void {
        WizardAssets::enqueue();

        global $wpdb;
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}tt_teams WHERE club_id = %d AND archived_at IS NULL ORDER BY name ASC LIMIT 200",
            CurrentClub::id()
        ) );

        echo '<div class="tt-tournament-wizard">';
        echo '<p class="ttw-step-desc">' . esc_html__( 'The headline information about the tournament. Anchor team is the club team that is playing — all other matches are vs. opponents.', 'talenttrack' ) . '</p>';
        echo '<div class="ttw-card">';
        echo '<div class="ttw-field-grid">';

        echo '<div class="ttw-field ttw-field--full">';
        echo '<label for="ttw-tour-name">' . esc_html__( 'Tournament name', 'talenttrack' ) . ' <span class="ttw-req">*</span></label>';
        echo '<input type="text" id="ttw-tour-name" name="tournament_name" required value="' . esc_attr( (string) ( $state['name'] ?? '' ) ) . '">';
        echo '<span class="ttw-hint">' . esc_html__( 'Shows on the planner page and on every match title.', 'talenttrack' ) . '</span>';
        echo '</div>';

        echo '<div class="ttw-field">';
        echo '<label for="ttw-tour-team">' . esc_html__( 'Anchor team', 'talenttrack' ) . ' <span class="ttw-req">*</span></label>';
        echo '<select id="ttw-tour-team" name="team_id" required>';
        echo '<option value="0">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        $current_team = (int) ( $state['team_id'] ?? 0 );
        foreach ( $teams as $t ) {
            $sel = selected( $current_team, (int) $t->id, false );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select>';
        echo '<span class="ttw-hint">' . esc_html__( 'Format (7v7 / 9v9 / 11v11) is inferred from the team age group.', 'talenttrack' ) . '</span>';
        echo '</div>';

        echo '<div class="ttw-field">';
        echo '<label for="ttw-tour-start">' . esc_html__( 'Start date', 'talenttrack' ) . ' <span class="ttw-req">*</span></label>';
        echo '<input type="date" id="ttw-tour-start" name="start_date" required value="' . esc_attr( (string) ( $state['start_date'] ?? '' ) ) . '">';
        echo '</div>';

        echo '<div class="ttw-field">';
        echo '<label for="ttw-tour-end">' . esc_html__( 'End date', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="ttw-tour-end" name="end_date" value="' . esc_attr( (string) ( $state['end_date'] ?? '' ) ) . '">';
        echo '<span class="ttw-hint">' . esc_html__( 'Leave blank for a single-day tournament.', 'talenttrack' ) . '</span>';
        echo '</div>';

        echo '</div>'; // field-grid
        echo '</div>'; // card
        echo '</div>'; // tournament-wizard
    }

    public function validate( array $post, array $state ) {
        $name = isset( $post['tournament_name'] ) ? sanitize_text_field( wp_unslash( (string) $post['tournament_name'] ) ) : '';
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
