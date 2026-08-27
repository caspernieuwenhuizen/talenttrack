<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\GoalContributionQuery;

/**
 * GoalContributionsRestController — /wp-json/talenttrack/v1/players/{id}/goal-contributions
 *
 * #2859 — a player's goals and assists over a window, from the same
 * {@see GoalContributionQuery} the profile KPI and the team minutes report
 * read. One service, three consumers: a non-WordPress front end asking this
 * endpoint gets the numbers the rendered page shows, not its own arithmetic
 * over the raw goal log.
 *
 * Gated on `tt_view_players` through {@see AuthorizationService} rather than
 * a role-name comparison, so the capability travels if the authentication
 * layer under it ever changes.
 */
final class GoalContributionsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<player_id>\d+)/goal-contributions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'forPlayer' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args'                => [
                    'from' => [ 'type' => 'string', 'required' => false ],
                    'to'   => [ 'type' => 'string', 'required' => false ],
                ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_view_players' );
    }

    public static function forPlayer( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['player_id'] );
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid player.', 'talenttrack' ), 400 );
        }

        // An absent window means the player's whole record, which is what a
        // profile asks for. A partial one is treated as absent rather than
        // half-applied — a `from` with no `to` is a caller mistake, and
        // silently inventing the other end would return numbers nobody asked
        // for.
        $from = isset( $r['from'] ) ? sanitize_text_field( (string) $r['from'] ) : '';
        $to   = isset( $r['to'] )   ? sanitize_text_field( (string) $r['to'] )   : '';
        $iso  = '/^\d{4}-\d{2}-\d{2}$/';
        if ( ! preg_match( $iso, $from ) || ! preg_match( $iso, $to ) ) {
            $from = '';
            $to   = '';
        }

        $filters = [];
        if ( $from !== '' ) {
            $filters['from'] = $from;
            $filters['to']   = $to;
        }

        $result = ( new GoalContributionQuery() )->forPlayer( $player_id, $filters );

        return RestResponse::success( [
            'player_id'     => $player_id,
            'from'          => $from,
            'to'            => $to,
            'goals'         => (int) $result['goals'],
            'assists'       => (int) $result['assists'],
            'own_goals'     => (int) $result['own_goals'],
            'contributions' => (int) $result['contributions'],
            'matches'       => array_values( $result['per_match'] ),
        ] );
    }
}
