<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendAjax — centralized AJAX endpoints for the frontend dashboard.
 */
class FrontendAjax {

    public static function register(): void {
        $actions = [ 'tt_fe_save_evaluation', 'tt_fe_save_session', 'tt_fe_save_goal', 'tt_fe_update_goal_status', 'tt_fe_delete_goal' ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_$a", [ __CLASS__, str_replace( 'tt_fe_', 'handle_', $a ) ] );
        }
    }

    public static function handle_save_evaluation(): void {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb; $p = $wpdb->prefix;

        $header = [
            'player_id'      => isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0,
            'coach_id'       => get_current_user_id(),
            'eval_type_id'   => isset( $_POST['eval_type_id'] ) ? absint( $_POST['eval_type_id'] ) : 0,
            'eval_date'      => isset( $_POST['eval_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['eval_date'] ) ) : '',
            'notes'          => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
            'opponent'       => isset( $_POST['opponent'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['opponent'] ) ) : '',
            'competition'    => isset( $_POST['competition'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['competition'] ) ) : '',
            'match_result'   => isset( $_POST['match_result'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['match_result'] ) ) : '',
            'home_away'      => isset( $_POST['home_away'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['home_away'] ) ) : '',
            'minutes_played' => ! empty( $_POST['minutes_played'] ) ? absint( $_POST['minutes_played'] ) : null,
        ];

        if ( ! $header['player_id'] || ! $header['eval_date'] ) {
            wp_send_json_error( esc_html__( 'Missing required fields.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_manage_settings' ) ) {
            if ( ! QueryHelpers::coach_owns_player( get_current_user_id(), (int) $header['player_id'] ) ) {
                wp_send_json_error( esc_html__( 'You can only evaluate players in your team.', 'talenttrack' ) );
            }
        }

        do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );
        $wpdb->insert( "{$p}tt_evaluations", $header );
        $eval_id = (int) $wpdb->insert_id;

        $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $ratings = isset( $_POST['ratings'] ) && is_array( $_POST['ratings'] ) ? $_POST['ratings'] : [];
        foreach ( $ratings as $cat_id => $rating ) {
            $r = max( $rmin, min( $rmax, floatval( $rating ) ) );
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'evaluation_id' => $eval_id, 'category_id' => absint( $cat_id ), 'rating' => $r,
            ]);
        }

        wp_send_json_success( [ 'message' => esc_html__( 'Evaluation saved!', 'talenttrack' ), 'id' => $eval_id ] );
    }

    public static function handle_save_session(): void {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb; $p = $wpdb->prefix;
        $data = [
            'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '',
            'session_date' => isset( $_POST['session_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['session_date'] ) ) : '',
            'team_id'      => isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0,
            'coach_id'     => get_current_user_id(),
            'location'     => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['location'] ) ) : '',
            'notes'        => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        ];
        $wpdb->insert( "{$p}tt_sessions", $data );
        $sid = (int) $wpdb->insert_id;
        $att_raw = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? $_POST['att'] : [];
        foreach ( $att_raw as $pid => $d ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'session_id' => $sid, 'player_id' => absint( $pid ),
                'status' => isset( $d['status'] ) ? sanitize_text_field( wp_unslash( (string) $d['status'] ) ) : 'Present',
                'notes'  => isset( $d['notes'] ) ? sanitize_text_field( wp_unslash( (string) $d['notes'] ) ) : '',
            ]);
        }
        wp_send_json_success( [ 'message' => esc_html__( 'Session saved!', 'talenttrack' ) ] );
    }

    public static function handle_save_goal(): void {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'player_id'   => isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0,
            'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '',
            'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '',
            'status'      => 'pending',
            'priority'    => isset( $_POST['priority'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['priority'] ) ) : 'medium',
            'due_date'    => ! empty( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['due_date'] ) ) : null,
            'created_by'  => get_current_user_id(),
        ]);
        wp_send_json_success( [ 'message' => esc_html__( 'Goal added!', 'talenttrack' ) ] );
    }

    public static function handle_update_goal_status(): void {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_goals',
            [ 'status' => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : 'pending' ],
            [ 'id' => isset( $_POST['goal_id'] ) ? absint( $_POST['goal_id'] ) : 0 ]
        );
        wp_send_json_success( [ 'message' => esc_html__( 'Status updated.', 'talenttrack' ) ] );
    }

    public static function handle_delete_goal(): void {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_goals', [ 'id' => isset( $_POST['goal_id'] ) ? absint( $_POST['goal_id'] ) : 0 ] );
        wp_send_json_success( [ 'message' => esc_html__( 'Goal deleted.', 'talenttrack' ) ] );
    }
}
