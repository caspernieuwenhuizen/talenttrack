<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\PlayerEvaluationsReader;
use TT\Infrastructure\Security\AuthorizationService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PlayerEvaluationsRestController (#3478) — a player's evaluations as the
 * player and their guardians see them.
 *
 *   GET /players/{id}/evaluations?scope=current|all
 *   GET /players/{id}/evaluations/{evaluation_id}/detail
 *
 * The second route is what "My evaluations" calls when a row is opened, which
 * is what took the 4,368 hidden rating rows out of the page. The first is the
 * same windowed list the view renders, so a non-WordPress front end gets the
 * same cut (§4).
 *
 * Why not `GET /evaluations/{id}`: that route is gated on
 * `tt_view_evaluations`, which neither a player nor a parent holds (they hold
 * `my_evaluations`, split deliberately in #1482), and it returns the full
 * record including staff-only notes. This one gates per player and returns
 * the player-facing shape.
 *
 * Permission: `canViewPlayer()` — own record, linked guardian, team or global
 * staff — **and** the #1867 preference, under which a player may keep their
 * evaluations from a parent. Both checks per request, per player.
 */
final class PlayerEvaluationsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/evaluations', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_for_player' ],
            'permission_callback' => [ __CLASS__, 'can_read' ],
            'args'                => [
                'id'    => [ 'type' => 'integer', 'required' => true ],
                'scope' => [
                    'type'    => 'string',
                    'enum'    => [ PlayerEvaluationsReader::SCOPE_CURRENT, PlayerEvaluationsReader::SCOPE_ALL ],
                    'default' => PlayerEvaluationsReader::SCOPE_CURRENT,
                ],
            ],
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/evaluations/(?P<evaluation_id>\d+)/detail', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'detail' ],
            'permission_callback' => [ __CLASS__, 'can_read' ],
            'args'                => [
                'id'            => [ 'type' => 'integer', 'required' => true ],
                'evaluation_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    public static function can_read( WP_REST_Request $r ): bool {
        $uid       = get_current_user_id();
        $player_id = (int) $r['id'];
        if ( $uid <= 0 || $player_id <= 0 ) return false;

        return AuthorizationService::canViewPlayer( $uid, $player_id )
            && AuthorizationService::parentCanViewSection( $uid, $player_id, 'evaluations' );
    }

    public static function list_for_player( WP_REST_Request $r ): WP_REST_Response {
        $player_id = (int) $r['id'];
        $scope     = (string) $r['scope'];
        $reader    = new PlayerEvaluationsReader();

        $items = [];
        foreach ( $reader->listForPlayer( $player_id, $scope ) as $row ) {
            $eid  = (int) ( $row->id ?? 0 );
            $type = (string) ( $row->type_name ?? '' );
            // #806 — `type` stays the canonical lookup value for consumers
            // that group on it; `type_localised` is what a front end prints.
            $type_localised = (string) ( $row->type_name_localised ?? '' );
            if ( $type_localised === '' ) $type_localised = $type;

            $items[] = [
                'id'              => $eid,
                'eval_date'       => (string) ( $row->eval_date ?? '' ),
                'type'            => $type,
                'type_localised'  => $type_localised,
                'coach'           => (string) ( $row->coach_name ?? '' ),
                'opponent'        => (string) ( $row->opponent ?? '' ),
                'game_result'     => (string) ( $row->game_result ?? '' ),
                'player_feedback' => (string) ( $row->player_feedback ?? '' ),
                'main_ratings'    => $reader->mainPills( $eid ),
                'has_detail'      => $reader->hasDetail( $eid ),
            ];
        }

        $window = $reader->window( $scope );
        return new WP_REST_Response( [
            'scope'          => $scope,
            'season'         => $window['season_name'],
            'from'           => $window['from'],
            'to'             => $window['to'],
            'items'          => $items,
            'earlier_count'  => $reader->countOutside( $player_id, $scope ),
        ], 200 );
    }

    public static function detail( WP_REST_Request $r ): WP_REST_Response {
        $groups = ( new PlayerEvaluationsReader() )->detail( (int) $r['id'], (int) $r['evaluation_id'] );
        if ( $groups === null ) {
            // Same answer for "does not exist" and "is not this player's", so
            // an id cannot be probed.
            return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
        }
        return new WP_REST_Response( [ 'groups' => $groups ], 200 );
    }
}
