<?php
namespace TT\Modules\Vct\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — When. Pick team + date.
 *
 * Coaches see only the teams they're assigned to; HoD/admin see all.
 * Date defaults to tomorrow. The age group + MD context auto-resolve
 * on the Preview step (server-side via MdContextResolver) — keeping
 * this step minimal avoids the slowness of an inline AJAX call.
 */
final class WhenStep implements WizardStepInterface {

    public function slug(): string { return 'when'; }
    public function label(): string { return __( 'When', 'talenttrack' ); }

    public function render( array $state ): void {
        $is_admin = current_user_can( 'tt_edit_settings' );
        $teams    = $is_admin
            ? QueryHelpers::get_teams()
            : QueryHelpers::get_teams_for_coach( get_current_user_id() );

        $current_team = (int)    ( $state['team_id']      ?? 0 );
        $current_date = (string) ( $state['session_date'] ?? gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );

        echo '<p>' . esc_html__( 'Which team and date is this VCT training for?', 'talenttrack' ) . '</p>';
        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span><select name="team_id" required>';
        echo '<option value="">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $t ) {
            $name = (string) ( $t->name ?? '' );
            $age  = (string) ( $t->age_group ?? '' );
            $label = $name . ( $age !== '' ? ' (' . $age . ')' : '' );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . selected( $current_team, (int) $t->id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Date', 'talenttrack' ) . '</span>'
            . '<input type="date" name="session_date" value="' . esc_attr( $current_date ) . '" required></label>';

        echo '<p class="description">' . esc_html__( 'Age group + match-day context are resolved automatically on the next step.', 'talenttrack' ) . '</p>';
    }

    public function validate( array $post, array $state ) {
        $tid  = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
        $date = isset( $post['session_date'] ) ? (string) $post['session_date'] : '';
        if ( $tid <= 0 ) return new \WP_Error( 'no_team', __( 'Please pick a team.', 'talenttrack' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new \WP_Error( 'bad_date', __( 'Please pick a valid date.', 'talenttrack' ) );
        }
        if ( ! AuthorizationService::canPlanForTeam( get_current_user_id(), $tid, 'create_delete' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to plan VCT for this team.', 'talenttrack' ) );
        }
        return [ 'team_id' => $tid, 'session_date' => $date ];
    }

    public function nextStep( array $state ): ?string { return 'theme'; }
    public function submit( array $state ) { return null; }
}
