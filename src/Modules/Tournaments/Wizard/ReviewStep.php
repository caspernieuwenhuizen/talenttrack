<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 5 — Review + Create. Renders an at-a-glance summary and, on
 * submit, persists the tournament + squad + matches in one wpdb
 * session. Mirrors what TournamentsRestController::create_tournament
 * does so the wizard and the REST POST land identical records.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $matches = is_array( $state['matches'] ?? null ) ? $state['matches'] : [];
        $squad   = is_array( $state['squad']   ?? null ) ? $state['squad']   : [];

        echo '<p>' . esc_html__( 'Review the details below before creating the tournament.', 'talenttrack' ) . '</p>';
        echo '<dl class="tt-wizard-review">';
        $start = (string) ( $state['start_date'] ?? '' );
        $end   = (string) ( $state['end_date'] ?? '' );
        $rows = [
            __( 'Name', 'talenttrack' )       => (string) ( $state['name'] ?? '' ),
            __( 'Team', 'talenttrack' )       => self::teamLabel( (int) ( $state['team_id'] ?? 0 ) ),
            __( 'Dates', 'talenttrack' )      => $end !== '' ? $start . ' → ' . $end : $start,
            __( 'Formation', 'talenttrack' )  => (string) ( $state['default_formation'] ?? '' ) ?: __( '(none — pick per match)', 'talenttrack' ),
            __( 'Squad size', 'talenttrack' ) => (string) count( $squad ),
            __( 'Match count', 'talenttrack' ) => (string) count( $matches ),
        ];
        foreach ( $rows as $k => $v ) {
            echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd>';
        }
        echo '</dl>';

        // Per-player target preview — what the equal-share default
        // works out to before any auto-balance runs.
        $total_minutes = 0;
        foreach ( $matches as $m ) {
            $total_minutes += (int) ( $m['duration_min'] ?? 0 );
        }
        if ( $squad && $total_minutes > 0 ) {
            $target = (int) round( $total_minutes );
            echo '<p style="margin-top:8px;">' . esc_html( sprintf(
                __( 'Equal-share target preview: %d minutes per player across %d squad members.', 'talenttrack' ),
                $target,
                count( $squad )
            ) ) . '</p>';
        }
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $name    = (string) ( $state['name'] ?? '' );
        $team_id = (int) ( $state['team_id'] ?? 0 );
        $start   = (string) ( $state['start_date'] ?? '' );
        $end_raw = (string) ( $state['end_date'] ?? '' );
        $formation = (string) ( $state['default_formation'] ?? '' );
        $matches = is_array( $state['matches'] ?? null ) ? $state['matches'] : [];
        $squad   = is_array( $state['squad']   ?? null ) ? $state['squad']   : [];

        if ( $name === '' || $team_id <= 0 || $start === '' ) {
            return new \WP_Error( 'incomplete_state', __( 'Tournament basics are incomplete. Go back and fill in name, team, and start date.', 'talenttrack' ) );
        }
        if ( ! $matches ) {
            return new \WP_Error( 'matches_empty', __( 'Add at least one match before creating the tournament.', 'talenttrack' ) );
        }

        global $wpdb; $p = $wpdb->prefix;

        $row = [
            'uuid'              => wp_generate_uuid4(),
            'club_id'           => CurrentClub::id(),
            'name'              => $name,
            'start_date'        => $start,
            'end_date'          => $end_raw !== '' ? $end_raw : null,
            'default_formation' => $formation !== '' ? $formation : null,
            'team_id'           => $team_id,
            'created_by'        => get_current_user_id(),
        ];
        $ok = $wpdb->insert( "{$p}tt_tournaments", $row );
        if ( ! $ok ) {
            return new \WP_Error( 'db_error', __( 'The tournament could not be created.', 'talenttrack' ) );
        }
        $tournament_id = (int) $wpdb->insert_id;

        // Squad.
        foreach ( $squad as $sq ) {
            $pid = (int) ( $sq['player_id'] ?? 0 );
            if ( $pid <= 0 ) continue;
            $positions = is_array( $sq['eligible_positions'] ?? null ) ? $sq['eligible_positions'] : [];
            $wpdb->insert( "{$p}tt_tournament_squad", [
                'tournament_id'      => $tournament_id,
                'player_id'          => $pid,
                'club_id'            => CurrentClub::id(),
                'eligible_positions' => wp_json_encode( array_values( $positions ) ),
                'target_minutes'     => null,
                'notes'              => null,
            ] );
        }

        // Matches.
        $seq = 0;
        foreach ( $matches as $m ) {
            $seq++;
            $windows = is_array( $m['substitution_windows'] ?? null ) ? $m['substitution_windows'] : [];
            $wpdb->insert( "{$p}tt_tournament_matches", [
                'tournament_id'        => $tournament_id,
                'club_id'              => CurrentClub::id(),
                'sequence'             => $seq,
                'label'                => $m['label'] !== '' ? $m['label'] : null,
                'opponent_name'        => $m['opponent_name'] !== '' ? $m['opponent_name'] : null,
                'opponent_level'       => $m['opponent_level'] !== '' ? $m['opponent_level'] : null,
                'formation'            => $m['formation'] !== '' ? $m['formation'] : null,
                'duration_min'         => (int) $m['duration_min'],
                'substitution_windows' => wp_json_encode( array_values( $windows ) ),
            ] );
        }

        do_action( 'tt_tournament_created', $tournament_id, $row );

        return [
            'redirect_url' => add_query_arg(
                [ 'tt_view' => 'tournaments', 'id' => $tournament_id ],
                WizardEntryPoint::dashboardBaseUrl()
            ),
        ];
    }

    private static function teamLabel( int $team_id ): string {
        if ( $team_id <= 0 ) return '—';
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
            $team_id, CurrentClub::id()
        ) );
        return $name ?: '#' . $team_id;
    }
}
