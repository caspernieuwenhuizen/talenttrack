<?php
namespace TT\Modules\Training\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — When (#2497).
 *
 * Team and date. Everything else the generator needs from this step —
 * the age group, the match-day context, the periodisation week — is
 * resolved server-side later rather than asked for here, because a coach
 * knows which Tuesday they are planning and should not have to look up
 * their own macro-block to say so.
 */
final class WhenStep implements WizardStepInterface {

    public function slug(): string { return 'when'; }

    public function label(): string { return __( 'When', 'talenttrack' ); }

    public function render( array $state ): void {
        $teams = current_user_can( 'tt_edit_settings' )
            ? QueryHelpers::get_teams()
            : QueryHelpers::get_teams_for_coach( get_current_user_id() );

        $team = (int) ( $state['team_id'] ?? 0 );
        $date = (string) ( $state['session_date'] ?? gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );

        echo '<p>' . esc_html__( 'Which team are you planning for, and when?', 'talenttrack' ) . '</p>';

        echo '<label><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span><select name="team_id" required>';
        echo '<option value="">' . esc_html__( '— pick a team —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $row ) {
            $age   = (string) ( $row->age_group ?? '' );
            $label = (string) ( $row->name ?? '' ) . ( $age !== '' ? ' (' . $age . ')' : '' );
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( (string) $row->id ),
                selected( $team, (int) $row->id, false ),
                esc_html( $label )
            );
        }
        echo '</select></label>';

        echo '<label><span>' . esc_html__( 'Date', 'talenttrack' ) . '</span>'
            . '<input type="date" name="session_date" value="' . esc_attr( $date ) . '" required></label>';

        echo '<p class="description">'
            . esc_html__( 'The age group, how many days until the next match, and where you are in the season are worked out from the team and the date. You do not need to enter them.', 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        $team = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
        $date = isset( $post['session_date'] ) ? (string) $post['session_date'] : '';

        if ( $team <= 0 ) {
            return new \WP_Error( 'no_team', __( 'Please pick a team.', 'talenttrack' ) );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new \WP_Error( 'bad_date', __( 'Please pick a valid date.', 'talenttrack' ) );
        }

        return [
            'team_id'      => $team,
            'session_date' => $date,
            'age_group'    => WizardContext::ageGroupFor( $team ),
        ];
    }

    public function nextStep( array $state ): ?string { return 'theme'; }

    public function submit( array $state ) { return null; }
}
