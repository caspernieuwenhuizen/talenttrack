<?php
namespace TT\Modules\Vct\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — When. Pick team + date + start time (#1084 VCT-9 mockup-
 * fidelity slice).
 *
 * Coaches see only the teams they're assigned to; HoD/admin see all.
 * Date defaults to tomorrow. Start time prefills from the team-
 * defaults panel (#1088) if one is configured for the current
 * season; otherwise it stays empty. The age group + MD context
 * auto-resolve on the Preview step (server-side via
 * MdContextResolver) — keeping this step minimal avoids the slowness
 * of an inline AJAX call on every team-change.
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
        $current_time = (string) ( $state['start_time']   ?? '' );

        // #1084 / #1088 — prefill start time from the team's VCT
        // schedule when the coach hasn't already picked one.
        if ( $current_time === '' && $current_team > 0 ) {
            $season = ( new SeasonsRepository() )->current();
            if ( $season ) {
                $schedule = ( new VctTeamSchedulesRepository() )->findForTeamSeason( $current_team, (int) $season->id );
                if ( $schedule !== null && ! empty( $schedule['default_start_time'] ) ) {
                    $current_time = substr( (string) $schedule['default_start_time'], 0, 5 );
                }
            }
        }

        echo '<p>' . esc_html__( 'Which team, date, and time is this VCT training for?', 'talenttrack' ) . '</p>';
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

        // #1084 VCT-9 — start time field. Optional but prefilled from
        // the team-defaults panel when set. Matches mockup step 1
        // basis-form spec.
        echo '<label><span>' . esc_html__( 'Start time (optional)', 'talenttrack' ) . '</span>'
            . '<input type="time" name="start_time" value="' . esc_attr( $current_time ) . '"></label>';

        echo '<p class="description">' . esc_html__( 'On the next step we detect this team\'s age group and its match-day (MD) context from the team and its season schedule — you don\'t need to enter them. Start time prefills from the team VCT-defaults panel when configured.', 'talenttrack' ) . '</p>';
    }

    public function validate( array $post, array $state ) {
        $tid  = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
        $date = isset( $post['session_date'] ) ? (string) $post['session_date'] : '';
        $time = isset( $post['start_time'] ) ? trim( (string) $post['start_time'] ) : '';
        if ( $tid <= 0 ) return new \WP_Error( 'no_team', __( 'Please pick a team.', 'talenttrack' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new \WP_Error( 'bad_date', __( 'Please pick a valid date.', 'talenttrack' ) );
        }
        // #1084 VCT-9 — HH:MM (24-hour) or empty.
        if ( $time !== '' && ! preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $time ) ) {
            return new \WP_Error( 'bad_time', __( 'Please pick a valid start time, or leave it empty.', 'talenttrack' ) );
        }
        if ( ! AuthorizationService::canPlanForTeam( get_current_user_id(), $tid, 'create_delete' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to plan VCT for this team.', 'talenttrack' ) );
        }
        return [
            'team_id'      => $tid,
            'session_date' => $date,
            'start_time'   => $time,
        ];
    }

    public function nextStep( array $state ): ?string { return 'theme'; }
    public function submit( array $state ) { return null; }
}
