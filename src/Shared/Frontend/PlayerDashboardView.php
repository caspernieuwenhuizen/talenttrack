<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

class PlayerDashboardView {

    public function render( object $player ): void {
        global $wpdb; $p = $wpdb->prefix;
        $max  = QueryHelpers::get_config( 'rating_max', '5' );
        $view = isset( $_GET['tt_view'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_view'] ) ) : 'overview';

        echo '<div class="tt-tabs">';
        foreach ( [
            'overview'    => __( 'Overview', 'talenttrack' ),
            'evaluations' => __( 'Evaluations', 'talenttrack' ),
            'goals'       => __( 'Goals', 'talenttrack' ),
            'attendance'  => __( 'Attendance', 'talenttrack' ),
            'progress'    => __( 'Progress', 'talenttrack' ),
            'help'        => __( 'Help', 'talenttrack' ),
        ] as $k => $l ) {
            echo '<button class="tt-tab' . ( $view === $k ? ' tt-tab-active' : '' ) . '" data-tab="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</button>';
        }
        echo '</div>';

        // Overview
        echo '<div class="tt-tab-content' . ( $view === 'overview' ? ' tt-tab-content-active' : '' ) . '" data-tab="overview">';
        $this->renderPlayerCard( $player );
        $radar = QueryHelpers::player_radar_datasets( (int) $player->id, 3 );
        if ( ! empty( $radar['datasets'] ) ) {
            echo '<div class="tt-radar-wrap">' . QueryHelpers::radar_chart_svg( $radar['labels'], $radar['datasets'], (float) $max ) . '</div>';
        }
        echo '</div>';

        // Evaluations
        echo '<div class="tt-tab-content' . ( $view === 'evaluations' ? ' tt-tab-content-active' : '' ) . '" data-tab="evaluations">';
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, lt.name AS type_name, u.display_name AS coach_name FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id LEFT JOIN {$wpdb->users} u ON e.coach_id=u.ID
             WHERE e.player_id=%d ORDER BY e.eval_date DESC", $player->id
        ));
        if ( empty( $evals ) ) {
            echo '<p>' . esc_html__( 'No evaluations yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr>'
                . '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Type', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Coach', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Ratings', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $evals as $ev ) {
                $full = QueryHelpers::get_evaluation( (int) $ev->id );
                echo '<tr><td>' . esc_html( $ev->eval_date ) . '</td><td>' . esc_html( $ev->type_name ?: '—' ) . '</td><td>' . esc_html( $ev->coach_name ) . '</td><td>';
                if ( $ev->opponent ) echo '<small>vs ' . esc_html( $ev->opponent ) . ' (' . esc_html( $ev->match_result ?: '—' ) . ')</small><br/>';
                if ( $full && ! empty( $full->ratings ) ) foreach ( $full->ratings as $r ) echo '<span class="tt-rating-pill">' . esc_html( $r->category_name ) . ': ' . esc_html( (string) $r->rating ) . '</span> ';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Goals
        echo '<div class="tt-tab-content' . ( $view === 'goals' ? ' tt-tab-content-active' : '' ) . '" data-tab="goals">';
        $goals = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_goals WHERE player_id=%d ORDER BY created_at DESC", $player->id ) );
        if ( empty( $goals ) ) {
            echo '<p>' . esc_html__( 'No goals assigned.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-goals-list">';
            foreach ( $goals as $g ) {
                echo '<div class="tt-goal-item tt-status-' . esc_attr( $g->status ) . '"><h4>' . esc_html( $g->title ) . '</h4>';
                if ( $g->description ) echo '<p>' . esc_html( $g->description ) . '</p>';
                echo '<span class="tt-status-badge">' . esc_html( ucwords( str_replace( '_', ' ', (string) $g->status ) ) ) . '</span>';
                if ( $g->due_date ) echo ' <small>' . esc_html__( 'Due:', 'talenttrack' ) . ' ' . esc_html( $g->due_date ) . '</small>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Attendance
        echo '<div class="tt-tab-content' . ( $view === 'attendance' ? ' tt-tab-content-active' : '' ) . '" data-tab="attendance">';
        $att = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, s.title AS session_title, s.session_date FROM {$p}tt_attendance a
             LEFT JOIN {$p}tt_sessions s ON a.session_id=s.id WHERE a.player_id=%d ORDER BY s.session_date DESC", $player->id
        ));
        if ( empty( $att ) ) {
            echo '<p>' . esc_html__( 'No attendance records.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr>'
                . '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Session', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Notes', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $att as $a ) {
                $status_lower = strtolower( (string) $a->status );
                $cls = $status_lower === 'present' ? 'tt-att-present' : ( $status_lower === 'absent' ? 'tt-att-absent' : 'tt-att-other' );
                echo '<tr class="' . esc_attr( $cls ) . '"><td>' . esc_html( (string) $a->session_date ) . '</td><td>' . esc_html( (string) $a->session_title ) . '</td><td>' . esc_html( (string) $a->status ) . '</td><td>' . esc_html( $a->notes ?: '—' ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Progress
        echo '<div class="tt-tab-content' . ( $view === 'progress' ? ' tt-tab-content-active' : '' ) . '" data-tab="progress">';
        $r5 = QueryHelpers::player_radar_datasets( (int) $player->id, 5 );
        if ( ! empty( $r5['datasets'] ) ) {
            echo '<h3>' . esc_html__( 'Development Over Time', 'talenttrack' ) . '</h3>';
            echo '<div class="tt-radar-wrap">' . QueryHelpers::radar_chart_svg( $r5['labels'], $r5['datasets'], (float) $max ) . '</div>';
        } else {
            echo '<p>' . esc_html__( 'Not enough data yet.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';

        // Help
        echo '<div class="tt-tab-content' . ( $view === 'help' ? ' tt-tab-content-active' : '' ) . '" data-tab="help">';
        echo '<h3>' . esc_html__( 'How to use your dashboard', 'talenttrack' ) . '</h3>';
        echo '<p>' . esc_html__( 'Overview shows your profile and latest radar chart. Evaluations lists every evaluation your coaches have recorded. Goals shows your development goals. Attendance tracks your sessions. Progress shows your trajectory.', 'talenttrack' ) . '</p>';
        echo '</div>';
    }

    private function renderPlayerCard( object $player ): void {
        $pos  = json_decode( (string) $player->preferred_positions, true );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        echo '<div class="tt-card">';
        if ( $player->photo_url ) echo '<div class="tt-card-thumb"><img src="' . esc_url( (string) $player->photo_url ) . '" alt="" /></div>';
        echo '<div class="tt-card-body">';
        echo '<h3>' . esc_html( QueryHelpers::player_display_name( $player ) ) . '</h3>';
        if ( $team ) echo '<p><strong>' . esc_html__( 'Team:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $team->name ) . '</p>';
        if ( is_array( $pos ) && $pos ) echo '<p><strong>' . esc_html__( 'Pos:', 'talenttrack' ) . '</strong> ' . esc_html( implode( ', ', $pos ) ) . '</p>';
        if ( $player->preferred_foot ) echo '<p><strong>' . esc_html__( 'Foot:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $player->preferred_foot ) . '</p>';
        if ( $player->jersey_number ) echo '<p><strong>#</strong>' . esc_html( (string) $player->jersey_number ) . '</p>';
        echo '</div></div>';
    }
}
