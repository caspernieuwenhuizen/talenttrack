<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 5 — Review + Create. One card per upstream step with an Edit
 * link in the top-right that jumps the wizard back to that step
 * (preserving state). On submit the tournament + squad + matches are
 * persisted in one wpdb session and the user lands on the planner
 * detail view.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        WizardAssets::enqueue();

        $matches = is_array( $state['matches'] ?? null ) ? $state['matches'] : [];
        $squad   = is_array( $state['squad']   ?? null ) ? $state['squad']   : [];

        $name       = (string) ( $state['name'] ?? '' );
        $team_id    = (int) ( $state['team_id'] ?? 0 );
        $team_label = self::teamLabel( $team_id );
        $start      = (string) ( $state['start_date'] ?? '' );
        $end        = (string) ( $state['end_date'] ?? '' );
        $formation  = (string) ( $state['default_formation'] ?? '' );

        $squad_count = count( $squad );
        $match_count = count( $matches );

        echo '<div class="tt-tournament-wizard">';
        echo '<p class="ttw-step-desc">' . esc_html__( 'Check the details below. You can jump back to any step via the Edit links.', 'talenttrack' ) . '</p>';

        // Basics card.
        echo '<div class="ttw-card ttw-review-card">';
        echo '<a href="#" class="ttw-edit-link" data-ttw-jump="basics">' . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
        echo '<h3 class="ttw-card-title">' . esc_html__( 'Basics', 'talenttrack' ) . '</h3>';
        echo '<dl class="ttw-facts">';
        echo '<dt>' . esc_html__( 'Name', 'talenttrack' ) . '</dt><dd>' . esc_html( $name ) . '</dd>';
        echo '<dt>' . esc_html__( 'Anchor team', 'talenttrack' ) . '</dt><dd>' . esc_html( $team_label ) . '</dd>';
        echo '<dt>' . esc_html__( 'Start date', 'talenttrack' ) . '</dt><dd>' . esc_html( $start !== '' ? $start : '—' ) . '</dd>';
        echo '<dt>' . esc_html__( 'End date', 'talenttrack' ) . '</dt><dd>' . esc_html( $end !== '' ? $end : '—' ) . '</dd>';
        echo '</dl></div>';

        // Formation card.
        echo '<div class="ttw-card ttw-review-card">';
        echo '<a href="#" class="ttw-edit-link" data-ttw-jump="formation">' . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
        echo '<h3 class="ttw-card-title">' . esc_html__( 'Default formation', 'talenttrack' ) . '</h3>';
        echo '<dl class="ttw-facts">';
        echo '<dt>' . esc_html__( 'Formation', 'talenttrack' ) . '</dt><dd>' . esc_html( $formation !== '' ? $formation : __( '(none — pick per match)', 'talenttrack' ) ) . '</dd>';
        echo '</dl></div>';

        // Squad card.
        echo '<div class="ttw-card ttw-review-card">';
        echo '<a href="#" class="ttw-edit-link" data-ttw-jump="squad">' . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
        echo '<h3 class="ttw-card-title">' . esc_html__( 'Squad', 'talenttrack' ) . ' <span class="ttw-pill">' . esc_html( sprintf( __( '%d picked', 'talenttrack' ), $squad_count ) ) . '</span></h3>';
        echo '<dl class="ttw-facts">';
        echo '<dt>' . esc_html__( 'Players', 'talenttrack' ) . '</dt><dd>';
        echo esc_html( self::squadNamesPreview( $squad, 8 ) );
        echo '</dd>';
        echo '<dt>' . esc_html__( 'By position', 'talenttrack' ) . '</dt><dd>' . esc_html( self::squadPositionBreakdown( $squad ) ) . '</dd>';
        echo '</dl></div>';

        // Matches card.
        echo '<div class="ttw-card ttw-review-card">';
        echo '<a href="#" class="ttw-edit-link" data-ttw-jump="matches">' . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
        echo '<h3 class="ttw-card-title">' . esc_html__( 'Matches', 'talenttrack' ) . ' <span class="ttw-pill">' . esc_html( (string) $match_count ) . '</span></h3>';
        if ( $matches ) {
            echo '<ol class="ttw-review-matches">';
            foreach ( $matches as $i => $m ) {
                $headline = (string) ( $m['label'] ?? '' );
                if ( $headline === '' && ( (string) ( $m['opponent_name'] ?? '' ) ) !== '' ) {
                    $headline = sprintf( __( 'vs %s', 'talenttrack' ), (string) $m['opponent_name'] );
                }
                if ( $headline === '' ) $headline = sprintf( __( 'Match %d', 'talenttrack' ), (int) $i + 1 );
                $windows = is_array( $m['substitution_windows'] ?? null ) ? $m['substitution_windows'] : [];
                $meta_bits = [ sprintf( __( '%d min', 'talenttrack' ), (int) ( $m['duration_min'] ?? 0 ) ) ];
                if ( $windows ) {
                    $meta_bits[] = sprintf( __( 'subs at %s', 'talenttrack' ), implode( ', ', array_map( static function ( $w ) { return $w . "'"; }, $windows ) ) );
                } else {
                    $meta_bits[] = __( 'no subs', 'talenttrack' );
                }
                $level = (string) ( $m['opponent_level'] ?? '' );
                if ( $level !== '' ) $meta_bits[] = $level;
                echo '<li>';
                echo '<span class="ttw-seq">' . (int) ( $i + 1 ) . '</span>';
                echo '<span class="ttw-name">' . esc_html( $headline ) . '</span>';
                echo '<span class="ttw-meta">' . esc_html( implode( ' · ', $meta_bits ) ) . '</span>';
                echo '</li>';
            }
            echo '</ol>';
        } else {
            echo '<p class="tt-muted">' . esc_html__( 'No matches added yet.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';

        // Equal-share preview.
        $total_minutes = 0;
        foreach ( $matches as $m ) $total_minutes += (int) ( $m['duration_min'] ?? 0 );
        if ( $squad && $total_minutes > 0 ) {
            echo '<p class="ttw-step-desc">' . esc_html( sprintf(
                __( 'Equal-share target preview: %1$d minutes per player across %2$d squad members.', 'talenttrack' ),
                (int) round( $total_minutes ),
                $squad_count
            ) ) . '</p>';
        }

        echo '</div>'; // tournament-wizard
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
                WizardEntryPoint::currentDashboardUrl()
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
        return (string) ( $name ?: '#' . $team_id );
    }

    /**
     * Render the first N squad member names as a short comma list,
     * with "+M more" tail when the squad is bigger.
     *
     * @param array<int, array<string,mixed>> $squad
     */
    private static function squadNamesPreview( array $squad, int $cap ): string {
        if ( ! $squad ) return __( '(none picked)', 'talenttrack' );
        $ids = [];
        foreach ( $squad as $sq ) {
            $pid = (int) ( $sq['player_id'] ?? 0 );
            if ( $pid > 0 ) $ids[] = $pid;
        }
        if ( ! $ids ) return __( '(none picked)', 'talenttrack' );

        global $wpdb; $p = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $args = array_merge( $ids, [ CurrentClub::id() ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$p}tt_players WHERE id IN ({$placeholders}) AND club_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $args
        ) ) ?: [];
        $names = [];
        foreach ( $rows as $r ) {
            $names[] = trim( ( (string) $r->first_name ) . ' ' . ( (string) $r->last_name ) );
        }
        $shown  = array_slice( $names, 0, $cap );
        $more   = count( $names ) - count( $shown );
        $out    = implode( ', ', $shown );
        if ( $more > 0 ) {
            $out .= ' ' . sprintf( __( '+%d more', 'talenttrack' ), $more );
        }
        return $out;
    }

    /**
     * @param array<int, array<string,mixed>> $squad
     */
    private static function squadPositionBreakdown( array $squad ): string {
        $codes = array_keys( SquadStep::positionCodes() );
        $counts = array_fill_keys( $codes, 0 );
        foreach ( $squad as $sq ) {
            $positions = is_array( $sq['eligible_positions'] ?? null ) ? $sq['eligible_positions'] : [];
            foreach ( $positions as $code ) {
                $code = strtoupper( (string) $code );
                if ( isset( $counts[ $code ] ) ) $counts[ $code ]++;
            }
        }
        $bits = [];
        foreach ( $counts as $code => $n ) {
            if ( $n > 0 ) $bits[] = $code . ' ' . $n;
        }
        return $bits ? implode( ' · ', $bits ) : __( '(no positions set)', 'talenttrack' );
    }
}
