<?php
namespace TT\Frontend;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Ajax {

    public static function init() {
        $actions = [ 'tt_fe_save_evaluation', 'tt_fe_save_session', 'tt_fe_save_goal', 'tt_fe_update_goal_status', 'tt_fe_delete_goal' ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_$a", [ __CLASS__, str_replace( 'tt_fe_', 'handle_', $a ) ] );
        }
    }

    public static function handle_save_evaluation() {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( 'Unauthorized' );
        global $wpdb; $p = $wpdb->prefix;

        $header = [
            'player_id'      => absint( $_POST['player_id'] ?? 0 ),
            'coach_id'       => get_current_user_id(),
            'eval_type_id'   => absint( $_POST['eval_type_id'] ?? 0 ),
            'eval_date'      => sanitize_text_field( $_POST['eval_date'] ?? '' ),
            'notes'          => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'opponent'       => sanitize_text_field( $_POST['opponent'] ?? '' ),
            'competition'    => sanitize_text_field( $_POST['competition'] ?? '' ),
            'match_result'   => sanitize_text_field( $_POST['match_result'] ?? '' ),
            'home_away'      => sanitize_text_field( $_POST['home_away'] ?? '' ),
            'minutes_played' => absint( $_POST['minutes_played'] ?? 0 ) ?: null,
        ];

        if ( ! $header['player_id'] || ! $header['eval_date'] ) wp_send_json_error( 'Missing required fields.' );

        if ( ! current_user_can( 'tt_manage_settings' ) ) {
            if ( ! Helpers::coach_owns_player( get_current_user_id(), $header['player_id'] ) ) {
                wp_send_json_error( 'You can only evaluate players in your team.' );
            }
        }

        do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );
        $wpdb->insert( "{$p}tt_evaluations", $header );
        $eval_id = $wpdb->insert_id;

        $rmin = (float) Helpers::get_config( 'rating_min', 1 );
        $rmax = (float) Helpers::get_config( 'rating_max', 5 );
        foreach ( $_POST['ratings'] ?? [] as $cat_id => $rating ) {
            $rating = max( $rmin, min( $rmax, floatval( $rating ) ) );
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'evaluation_id' => $eval_id, 'category_id' => absint( $cat_id ), 'rating' => $rating,
            ]);
        }

        wp_send_json_success( [ 'message' => 'Evaluation saved!', 'id' => $eval_id ] );
    }

    public static function handle_save_session() {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( 'Unauthorized' );
        global $wpdb; $p = $wpdb->prefix;
        $data = [
            'title'        => sanitize_text_field( $_POST['title'] ?? '' ),
            'session_date' => sanitize_text_field( $_POST['session_date'] ?? '' ),
            'team_id'      => absint( $_POST['team_id'] ?? 0 ),
            'coach_id'     => get_current_user_id(),
            'location'     => sanitize_text_field( $_POST['location'] ?? '' ),
            'notes'        => sanitize_textarea_field( $_POST['notes'] ?? '' ),
        ];
        $wpdb->insert( "{$p}tt_sessions", $data );
        $sid = $wpdb->insert_id;
        foreach ( $_POST['att'] ?? [] as $pid => $d ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'session_id' => $sid, 'player_id' => absint( $pid ),
                'status' => sanitize_text_field( $d['status'] ?? 'Present' ),
                'notes'  => sanitize_text_field( $d['notes'] ?? '' ),
            ]);
        }
        wp_send_json_success( [ 'message' => 'Session saved!' ] );
    }

    public static function handle_save_goal() {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( 'Unauthorized' );
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'player_id'   => absint( $_POST['player_id'] ?? 0 ),
            'title'       => sanitize_text_field( $_POST['title'] ?? '' ),
            'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'status'      => 'pending',
            'priority'    => sanitize_text_field( $_POST['priority'] ?? 'medium' ),
            'due_date'    => sanitize_text_field( $_POST['due_date'] ?? '' ) ?: null,
            'created_by'  => get_current_user_id(),
        ]);
        wp_send_json_success( [ 'message' => 'Goal added!' ] );
    }

    public static function handle_update_goal_status() {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( 'Unauthorized' );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_goals',
            [ 'status' => sanitize_text_field( $_POST['status'] ?? 'pending' ) ],
            [ 'id' => absint( $_POST['goal_id'] ?? 0 ) ]
        );
        wp_send_json_success( [ 'message' => 'Status updated.' ] );
    }

    public static function handle_delete_goal() {
        check_ajax_referer( 'tt_frontend', 'nonce' );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_send_json_error( 'Unauthorized' );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_goals', [ 'id' => absint( $_POST['goal_id'] ?? 0 ) ] );
        wp_send_json_success( [ 'message' => 'Goal deleted.' ] );
    }
}
