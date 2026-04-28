<?php
namespace TT\Modules\Wizards\Player;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Review and create.
 *
 * Renders a summary of accumulated state and, on submit, persists:
 *   - roster path: a single `tt_players` row.
 *   - trial path: a `tt_players` row with status='trial' AND a real
 *     `tt_trial_cases` row pointing at it (when the Trials module is
 *     active). The user lands on the new trial-case detail view.
 *
 * Both paths set `status` correctly and redirect somewhere useful.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $path = (string) ( $state['path'] ?? 'roster' );
        echo '<p>' . esc_html__( 'Check the details below before creating the record.', 'talenttrack' ) . '</p>';
        echo '<dl class="tt-wizard-review">';
        $rows = [
            __( 'Type', 'talenttrack' )           => $path === 'trial' ? __( 'Trial player', 'talenttrack' ) : __( 'Roster player', 'talenttrack' ),
            __( 'Name', 'talenttrack' )           => trim( ( (string) ( $state['first_name'] ?? '' ) ) . ' ' . ( (string) ( $state['last_name'] ?? '' ) ) ),
            __( 'Date of birth', 'talenttrack' )  => (string) ( $state['date_of_birth'] ?? '' ) ?: '—',
            __( 'Team', 'talenttrack' )           => self::teamLabel( (int) ( $state['team_id'] ?? 0 ) ),
        ];
        if ( $path === 'roster' ) {
            $rows[ __( 'Jersey number', 'talenttrack' ) ]  = $state['jersey_number'] ?? '—';
            $rows[ __( 'Preferred foot', 'talenttrack' ) ] = (string) ( $state['preferred_foot'] ?? '' ) ?: '—';
        } else {
            $rows[ __( 'Trial track', 'talenttrack' ) ]  = self::trackLabel( (int) ( $state['trial_track_id'] ?? 0 ) );
            $rows[ __( 'Trial start', 'talenttrack' ) ]  = (string) ( $state['trial_start_date'] ?? '' );
            $rows[ __( 'Trial end', 'talenttrack' ) ]    = (string) ( $state['trial_end_date'] ?? '' );
        }
        foreach ( $rows as $k => $v ) {
            echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd>';
        }
        echo '</dl>';
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        global $wpdb;
        $first = (string) ( $state['first_name'] ?? '' );
        $last  = (string) ( $state['last_name']  ?? '' );
        if ( $first === '' || $last === '' ) {
            return new \WP_Error( 'name_required', __( 'First and last name are required.', 'talenttrack' ) );
        }

        $path = (string) ( $state['path'] ?? 'roster' );
        $insert = [
            'first_name'    => $first,
            'last_name'     => $last,
            'date_of_birth' => $state['date_of_birth'] ?? null,
            'team_id'       => (int) ( $state['team_id'] ?? 0 ),
            'status'        => $path === 'trial' ? 'trial' : 'active',
        ];
        if ( $path === 'roster' ) {
            $insert['jersey_number']  = $state['jersey_number'] ?? null;
            $insert['preferred_foot'] = (string) ( $state['preferred_foot'] ?? '' );
        }

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', $insert );
        if ( ! $ok ) {
            return new \WP_Error( 'db_error', __( 'Could not create the player record.', 'talenttrack' ) );
        }
        $player_id = (int) $wpdb->insert_id;

        if ( $path === 'trial' && class_exists( '\\TT\\Modules\\Trials\\Repositories\\TrialCasesRepository' ) ) {
            $track_id = (int) ( $state['trial_track_id'] ?? 0 );
            if ( $track_id > 0 ) {
                $cases = new \TT\Modules\Trials\Repositories\TrialCasesRepository();
                $case_id = $cases->create( [
                    'player_id'  => $player_id,
                    'track_id'   => $track_id,
                    'start_date' => (string) ( $state['trial_start_date'] ?? gmdate( 'Y-m-d' ) ),
                    'end_date'   => (string) ( $state['trial_end_date']   ?? gmdate( 'Y-m-d', time() + 28 * 86400 ) ),
                    'created_by' => get_current_user_id(),
                ] );
                if ( $case_id > 0 ) {
                    return [ 'redirect_url' => add_query_arg( [ 'tt_view' => 'trial-case', 'id' => $case_id ], home_url( '/' ) ) ];
                }
            }
        }

        return [ 'redirect_url' => add_query_arg( [ 'tt_view' => 'players', 'player_id' => $player_id ], home_url( '/' ) ) ];
    }

    private static function teamLabel( int $team_id ): string {
        if ( $team_id <= 0 ) return '—';
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d", $team_id ) );
        return $name ?: '#' . $team_id;
    }

    private static function trackLabel( int $track_id ): string {
        if ( $track_id <= 0 ) return '—';
        if ( ! class_exists( '\\TT\\Modules\\Trials\\Repositories\\TrialTracksRepository' ) ) return '#' . $track_id;
        $row = ( new \TT\Modules\Trials\Repositories\TrialTracksRepository() )->find( $track_id );
        return $row ? (string) $row->name : '#' . $track_id;
    }
}
