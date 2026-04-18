<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

class PlayersRestController {
    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
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
        $team_id = $r['team_id'] ? absint( $r['team_id'] ) : 0;
        return rest_ensure_response( array_map( [ __CLASS__, 'fmt' ], QueryHelpers::get_players( $team_id ) ) );
    }

    public static function get_player( \WP_REST_Request $r ) {
        $pl = QueryHelpers::get_player( (int) $r['id'] );
        return $pl ? rest_ensure_response( self::fmt( $pl ) ) : new \WP_Error( 'not_found', 'Not found', [ 'status' => 404 ] );
    }

    public static function create_player( \WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', self::extract( $r ) );
        $id = (int) $wpdb->insert_id;
        do_action( 'tt_after_player_save', $id, [] );
        return rest_ensure_response( self::fmt( QueryHelpers::get_player( $id ) ) );
    }

    public static function update_player( \WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', self::extract( $r ), [ 'id' => (int) $r['id'] ] );
        return rest_ensure_response( self::fmt( QueryHelpers::get_player( (int) $r['id'] ) ) );
    }

    public static function delete_player( \WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => (int) $r['id'] ] );
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    /** @return array<string,mixed> */
    private static function extract( \WP_REST_Request $r ): array {
        return [
            'first_name' => sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ),
            'last_name'  => sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ),
            'team_id'    => absint( $r['team_id'] ?? 0 ),
        ];
    }

    /** @return array<string,mixed> */
    private static function fmt( ?object $pl ): array {
        if ( ! $pl ) return [];
        return [
            'id' => (int) $pl->id, 'first_name' => $pl->first_name, 'last_name' => $pl->last_name,
            'team_id' => (int) $pl->team_id, 'preferred_positions' => json_decode( (string) $pl->preferred_positions, true ),
            'preferred_foot' => $pl->preferred_foot, 'status' => $pl->status,
        ];
    }
}
