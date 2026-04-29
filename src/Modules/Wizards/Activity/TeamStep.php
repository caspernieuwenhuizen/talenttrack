<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — Pick the team the activity is for.
 *
 * Coaches see only the teams they're assigned to; admins see every
 * team. The downstream attendance roster on the activity edit form
 * fills in once the activity is saved.
 */
final class TeamStep implements WizardStepInterface {

    public function slug(): string { return 'team'; }
    public function label(): string { return __( 'Team', 'talenttrack' ); }

    public function render( array $state ): void {
        $is_admin = current_user_can( 'tt_edit_settings' );
        $teams    = $is_admin
            ? QueryHelpers::get_teams()
            : QueryHelpers::get_teams_for_coach( get_current_user_id() );

        $current = (int) ( $state['team_id'] ?? 0 );

        echo '<p>' . esc_html__( 'Which team is this activity for?', 'talenttrack' ) . '</p>';
        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span><select name="team_id" required>';
        echo '<option value="">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            $name = (string) ( $t->name ?? '' );
            $age  = (string) ( $t->age_group ?? '' );
            $label = $name . ( $age !== '' ? ' (' . $age . ')' : '' );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . selected( $current, (int) $t->id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    public function validate( array $post, array $state ) {
        $tid = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
        if ( $tid <= 0 ) return new \WP_Error( 'no_team', __( 'Please pick a team.', 'talenttrack' ) );
        return [ 'team_id' => $tid ];
    }

    public function nextStep( array $state ): ?string { return 'type'; }
    public function submit( array $state ) { return null; }
}
