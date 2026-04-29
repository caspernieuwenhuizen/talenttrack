<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * PlayerStatusRestController (#0057 Sprints 1 + 4) — REST surface for
 * the player status feature.
 *
 *   POST /players/{id}/behaviour-ratings   — log a behaviour rating
 *   POST /players/{id}/potential           — set potential band
 *   GET  /players/{id}/status              — single-player status verdict
 *   GET  /teams/{id}/player-statuses       — bulk: all players on a team
 *
 * Permission gates use the capabilities registered in
 * `PlayerStatusModule`. The bulk endpoint is the read model the
 * traffic-light dot column on My Teams consumes.
 */
final class PlayerStatusRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/behaviour-ratings', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'createBehaviourRating' ],
            'permission_callback' => static fn() => current_user_can( 'tt_rate_player_behaviour' ),
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/potential', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'setPotential' ],
            'permission_callback' => static fn() => current_user_can( 'tt_set_player_potential' ),
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'playerStatus' ],
            'permission_callback' => static fn() => current_user_can( 'tt_view_player_status' ),
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/player-statuses', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'teamStatuses' ],
            'permission_callback' => static fn() => current_user_can( 'tt_view_player_status' ),
        ] );
    }

    public static function createBehaviourRating( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $rating    = isset( $r['rating'] ) ? (float) $r['rating'] : 0.0;
        if ( $player_id <= 0 || $rating < 1.0 || $rating > 5.0 ) {
            return RestResponse::error( 'bad_input', __( 'Player and rating (1-5) are required.', 'talenttrack' ), 400 );
        }
        $id = ( new PlayerBehaviourRatingsRepository() )->create( [
            'player_id'           => $player_id,
            'rating'              => $rating,
            'context'             => isset( $r['context'] ) ? sanitize_text_field( (string) $r['context'] ) : null,
            'notes'               => isset( $r['notes'] )   ? sanitize_textarea_field( (string) $r['notes'] ) : null,
            'related_activity_id' => isset( $r['related_activity_id'] ) ? (int) $r['related_activity_id'] : null,
        ] );
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function setPotential( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $band      = isset( $r['potential_band'] ) ? sanitize_key( (string) $r['potential_band'] ) : '';
        $valid     = [ 'first_team', 'professional_elsewhere', 'semi_pro', 'top_amateur', 'recreational' ];
        if ( $player_id <= 0 || ! in_array( $band, $valid, true ) ) {
            return RestResponse::error( 'bad_input', __( 'Player and a valid potential band are required.', 'talenttrack' ), 400, [ 'allowed' => $valid ] );
        }
        $id = ( new PlayerPotentialRepository() )->create( [
            'player_id'      => $player_id,
            'potential_band' => $band,
            'notes'          => isset( $r['notes'] ) ? sanitize_textarea_field( (string) $r['notes'] ) : null,
        ] );
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function playerStatus( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_player_id', __( 'Player id is required.', 'talenttrack' ), 400 );
        }
        $verdict = ( new PlayerStatusCalculator() )->calculate( $player_id );
        $payload = current_user_can( 'tt_view_player_status_breakdown' )
            ? $verdict->toArray()
            : [ 'color' => $verdict->color, 'label' => $verdict->softLabel(), 'as_of' => $verdict->as_of ];
        return RestResponse::success( $payload );
    }

    public static function teamStatuses( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE team_id = %d AND club_id = %d AND status = 'active'",
            $team_id, CurrentClub::id()
        ) );
        $calc       = new PlayerStatusCalculator();
        $can_detail = current_user_can( 'tt_view_player_status_breakdown' );
        $out        = [];
        foreach ( (array) $players as $row ) {
            $verdict = $calc->calculate( (int) $row->id );
            $out[]   = $can_detail
                ? array_merge( [ 'player_id' => (int) $row->id ], $verdict->toArray() )
                : [ 'player_id' => (int) $row->id, 'color' => $verdict->color, 'label' => $verdict->softLabel() ];
        }
        return RestResponse::success( [ 'rows' => $out ] );
    }
}
