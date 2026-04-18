<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PlayersRestController — /wp-json/talenttrack/v1/players
 *
 * All responses use the standard envelope {success, data, errors}.
 */
class PlayersRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_players' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_player' ],
                'permission_callback' => self::permCan( 'tt_manage_players' ),
            ],
        ]);

        register_rest_route( self::NS, '/players/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_player' ],
                'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_player' ],
                'permission_callback' => self::permCan( 'tt_manage_players' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_player' ],
                'permission_callback' => self::permCan( 'tt_manage_players' ),
            ],
        ]);
    }

    public static function list_players( WP_REST_Request $request ): WP_REST_Response {
        $team_id = $request['team_id'] !== null ? absint( $request['team_id'] ) : 0;
        $players = array_map( [ __CLASS__, 'fmt' ], QueryHelpers::get_players( $team_id ) );
        return RestResponse::success( $players );
    }

    public static function get_player( WP_REST_Request $request ): WP_REST_Response {
        $id = absint( $request['id'] );
        if ( ! $id ) {
            return RestResponse::error( 'invalid_id', 'Player ID must be a positive integer.', 400 );
        }
        $pl = QueryHelpers::get_player( $id );
        if ( ! $pl ) {
            return RestResponse::error( 'player_not_found', 'Player not found.', 404, [ 'id' => $id ] );
        }
        return RestResponse::success( self::fmt( $pl ) );
    }

    public static function create_player( WP_REST_Request $request ): WP_REST_Response {
        $validation = self::requireFields( $request, [ 'first_name', 'last_name' ] );
        if ( ! empty( $validation ) ) {
            return RestResponse::errors( $validation, 422 );
        }

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', self::extract( $request ) );
        $id = (int) $wpdb->insert_id;

        if ( ! $id ) {
            return RestResponse::error( 'create_failed', 'Could not create player.', 500 );
        }

        do_action( 'tt_after_player_save', $id, [] );
        return RestResponse::success( self::fmt( QueryHelpers::get_player( $id ) ), 201 );
    }

    public static function update_player( WP_REST_Request $request ): WP_REST_Response {
        $id = absint( $request['id'] );
        if ( ! $id ) {
            return RestResponse::error( 'invalid_id', 'Player ID must be a positive integer.', 400 );
        }
        if ( ! QueryHelpers::get_player( $id ) ) {
            return RestResponse::error( 'player_not_found', 'Player not found.', 404, [ 'id' => $id ] );
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', self::extract( $request ), [ 'id' => $id ] );
        do_action( 'tt_after_player_save', $id, [] );

        return RestResponse::success( self::fmt( QueryHelpers::get_player( $id ) ) );
    }

    public static function delete_player( WP_REST_Request $request ): WP_REST_Response {
        $id = absint( $request['id'] );
        if ( ! $id ) {
            return RestResponse::error( 'invalid_id', 'Player ID must be a positive integer.', 400 );
        }
        if ( ! QueryHelpers::get_player( $id ) ) {
            return RestResponse::error( 'player_not_found', 'Player not found.', 404, [ 'id' => $id ] );
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => $id ] );

        return RestResponse::success( [ 'id' => $id, 'deleted' => true ] );
    }

    /** @return array<string,mixed> */
    private static function extract( WP_REST_Request $request ): array {
        return [
            'first_name' => sanitize_text_field( (string) ( $request['first_name'] ?? '' ) ),
            'last_name'  => sanitize_text_field( (string) ( $request['last_name'] ?? '' ) ),
            'team_id'    => absint( $request['team_id'] ?? 0 ),
        ];
    }

    /** @return array<string,mixed> */
    private static function fmt( ?object $pl ): array {
        if ( ! $pl ) return [];
        return [
            'id'                  => (int) $pl->id,
            'first_name'          => (string) $pl->first_name,
            'last_name'           => (string) $pl->last_name,
            'team_id'             => (int) $pl->team_id,
            'preferred_positions' => json_decode( (string) $pl->preferred_positions, true ) ?: [],
            'preferred_foot'      => (string) $pl->preferred_foot,
            'status'              => (string) $pl->status,
        ];
    }
}
