<?php
namespace TT\REST;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Players_Controller {
    const NS = 'talenttrack/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_rest_route( self::NS, '/players', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_players' ],  'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_player' ], 'permission_callback' => function () { return current_user_can( 'tt_manage_players' ); } ],
        ]);
        register_rest_route( self::NS, '/players/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_player' ],    'permission_callback' => function () { return is_user_logged_in(); } ],
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_player' ], 'permission_callback' => function () { return current_user_can( 'tt_manage_players' ); } ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_player' ], 'permission_callback' => function () { return current_user_can( 'tt_manage_players' ); } ],
        ]);
    }

    public static function list_players( \WP_REST_Request $r ) {
        return rest_ensure_response( array_map( [ __CLASS__, 'fmt' ], Helpers::get_players( absint( $r['team_id'] ?? 0 ) ) ) );
    }

    public static function get_player( \WP_REST_Request $r ) {
        $pl = Helpers::get_player( $r['id'] );
        return $pl ? rest_ensure_response( self::fmt( $pl ) ) : new \WP_Error( 'not_found', 'Player not found', [ 'status' => 404 ] );
    }

    public static function create_player( \WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', self::extract( $r ) );
        $id = $wpdb->insert_id;
        do_action( 'tt_after_player_save', $id, [] );
        return rest_ensure_response( self::fmt( Helpers::get_player( $id ) ) );
    }

    public static function update_player( \WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', self::extract( $r ), [ 'id' => $r['id'] ] );
        do_action( 'tt_after_player_save', $r['id'], [] );
        return rest_ensure_response( self::fmt( Helpers::get_player( $r['id'] ) ) );
    }

    public static function delete_player( \WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => $r['id'] ] );
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    private static function extract( $r ) {
        return [
            'first_name'          => sanitize_text_field( $r['first_name'] ?? '' ),
            'last_name'           => sanitize_text_field( $r['last_name'] ?? '' ),
            'date_of_birth'       => sanitize_text_field( $r['date_of_birth'] ?? '' ),
            'nationality'         => sanitize_text_field( $r['nationality'] ?? '' ),
            'preferred_foot'      => sanitize_text_field( $r['preferred_foot'] ?? '' ),
            'preferred_positions' => wp_json_encode( $r['preferred_positions'] ?? [] ),
            'team_id'             => absint( $r['team_id'] ?? 0 ),
        ];
    }

    private static function fmt( $pl ) {
        return [
            'id' => (int) $pl->id, 'first_name' => $pl->first_name, 'last_name' => $pl->last_name,
            'team_id' => (int) $pl->team_id, 'preferred_positions' => json_decode( $pl->preferred_positions, true ),
            'preferred_foot' => $pl->preferred_foot, 'date_of_birth' => $pl->date_of_birth,
            'nationality' => $pl->nationality, 'jersey_number' => $pl->jersey_number, 'status' => $pl->status,
        ];
    }
}
