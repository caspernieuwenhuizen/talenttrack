<?php
namespace TT\REST;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Evaluations_Controller {
    const NS = 'talenttrack/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_rest_route( self::NS, '/evaluations', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_evals' ],  'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_evaluate_players' ); } ],
        ]);
        register_rest_route( self::NS, '/evaluations/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_eval' ],    'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_evaluate_players' ); } ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_eval' ], 'permission_callback' => function () { return current_user_can( 'tt_evaluate_players' ); } ],
        ]);
    }

    public static function list_evals( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $where = '1=1'; $params = [];
        if ( $r['player_id'] ) { $where .= ' AND e.player_id=%d'; $params[] = absint( $r['player_id'] ); }
        if ( $r['eval_type_id'] ) { $where .= ' AND e.eval_type_id=%d'; $params[] = absint( $r['eval_type_id'] ); }
        $sql = "SELECT e.*, lt.name AS type_name FROM {$p}tt_evaluations e LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id AND lt.lookup_type='eval_type' WHERE $where ORDER BY e.eval_date DESC LIMIT 100";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );
        return rest_ensure_response( array_map( [ __CLASS__, 'fmt' ], $rows ) );
    }

    public static function get_eval( \WP_REST_Request $r ) {
        $e = Helpers::get_evaluation( $r['id'] );
        return $e ? rest_ensure_response( self::fmt( $e, true ) ) : new \WP_Error( 'not_found', 'Not found', [ 'status' => 404 ] );
    }

    public static function create_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $header = self::extract( $r );
        $header['coach_id'] = get_current_user_id();
        do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );
        $wpdb->insert( "{$p}tt_evaluations", $header );
        $id = $wpdb->insert_id;
        foreach ( (array) ( $r['ratings'] ?? [] ) as $cid => $val ) {
            $wpdb->insert( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id, 'category_id' => absint( $cid ), 'rating' => floatval( $val ) ] );
        }
        return rest_ensure_response( self::fmt( Helpers::get_evaluation( $id ), true ) );
    }

    public static function update_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->update( "{$p}tt_evaluations", self::extract( $r ), [ 'id' => $r['id'] ] );
        if ( isset( $r['ratings'] ) ) {
            $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $r['id'] ] );
            foreach ( (array) $r['ratings'] as $cid => $val ) {
                $wpdb->insert( "{$p}tt_eval_ratings", [ 'evaluation_id' => $r['id'], 'category_id' => absint( $cid ), 'rating' => floatval( $val ) ] );
            }
        }
        return rest_ensure_response( self::fmt( Helpers::get_evaluation( $r['id'] ), true ) );
    }

    public static function delete_eval( \WP_REST_Request $r ) {
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $r['id'] ] );
        $wpdb->delete( "{$p}tt_evaluations", [ 'id' => $r['id'] ] );
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    private static function extract( $r ) {
        return [
            'player_id'      => absint( $r['player_id'] ?? 0 ),
            'eval_type_id'   => absint( $r['eval_type_id'] ?? 0 ),
            'eval_date'      => sanitize_text_field( $r['eval_date'] ?? current_time( 'Y-m-d' ) ),
            'notes'          => sanitize_textarea_field( $r['notes'] ?? '' ),
            'opponent'       => sanitize_text_field( $r['opponent'] ?? '' ),
            'competition'    => sanitize_text_field( $r['competition'] ?? '' ),
            'match_result'   => sanitize_text_field( $r['match_result'] ?? '' ),
            'home_away'      => sanitize_text_field( $r['home_away'] ?? '' ),
            'minutes_played' => absint( $r['minutes_played'] ?? 0 ) ?: null,
        ];
    }

    private static function fmt( $e, $with_ratings = false ) {
        $out = [
            'id' => (int) $e->id, 'player_id' => (int) $e->player_id, 'coach_id' => (int) $e->coach_id,
            'eval_type_id' => (int) $e->eval_type_id, 'type_name' => $e->type_name ?? '',
            'eval_date' => $e->eval_date, 'notes' => $e->notes,
            'opponent' => $e->opponent, 'competition' => $e->competition,
            'match_result' => $e->match_result, 'home_away' => $e->home_away, 'minutes_played' => $e->minutes_played,
        ];
        if ( $with_ratings && ! empty( $e->ratings ) ) {
            $out['ratings'] = array_map( function ( $r ) { return [ 'category_id' => (int) $r->category_id, 'category_name' => $r->category_name, 'rating' => (float) $r->rating ]; }, $e->ratings );
        }
        return $out;
    }
}
