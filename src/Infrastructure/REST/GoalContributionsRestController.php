<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\GoalContributionDrilldown;
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

        // #4994 — every match behind one player's goals and assists for a
        // team, with each entry's provenance and the flags that point at a
        // wrong one. The Statistics tab and the Goals and assists report open
        // the same data through `GoalContributionDrilldown`.
        register_rest_route( self::NS, '/teams/(?P<team_id>\d+)/players/(?P<player_id>\d+)/goal-contributions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'drilldown' ],
                'permission_callback' => [ __CLASS__, 'can_view_teams' ],
                'args'                => [
                    'season' => [ 'type' => 'string', 'required' => false, 'description' => 'A season id. Unknown: 400 bad_filter. Wins over from/to.' ],
                    'from'   => [ 'type' => 'string', 'required' => false, 'description' => 'YYYY-MM-DD, with to. Malformed or after to: 400 bad_date.' ],
                    'to'     => [ 'type' => 'string', 'required' => false, 'description' => 'YYYY-MM-DD, with from.' ],
                ],
            ],
        ] );
    }

    /**
     * #4994 — the team-staff gate the team routes use. The team and the
     * player are asked about in the handler, so an out-of-scope team answers
     * `403 forbidden_team` rather than a bare refusal.
     */
    public static function can_view_teams(): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_view_teams' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_edit_teams' );
    }

    public static function drilldown( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id   = absint( $r['team_id'] );
        $player_id = absint( $r['player_id'] );
        if ( $team_id <= 0 || $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid team or player.', 'talenttrack' ), 400 );
        }

        // The team first, so an out-of-scope team is always `forbidden_team`;
        // then the player. `GoalContributionDrilldown::refusal()` asks the
        // page the same two questions in the same order.
        $uid = get_current_user_id();
        if ( ! \TT\Modules\Authorization\AllTeamsScope::canReadTeam( $uid, $team_id ) ) {
            return RestResponse::error( 'forbidden_team', __( 'That team is not in your scope.', 'talenttrack' ), 403 );
        }
        if ( ! AuthorizationService::canViewPlayer( $uid, $player_id ) ) {
            return RestResponse::error( 'forbidden_player', __( 'You may not see this player.', 'talenttrack' ), 403 );
        }

        $window = GoalContributionQuery::resolveWindow(
            (string) ( $r['season'] ?? '' ),
            (string) ( $r['from'] ?? '' ),
            (string) ( $r['to'] ?? '' )
        );
        if ( $window['error'] === 'bad_filter' ) {
            return RestResponse::error( 'bad_filter', __( 'That season is not known.', 'talenttrack' ), 400, [ 'parameter' => 'season' ] );
        }
        if ( $window['error'] === 'bad_date' ) {
            return RestResponse::error( 'bad_date', __( 'Dates must be written as YYYY-MM-DD, with from on or before to.', 'talenttrack' ), 400 );
        }

        return RestResponse::success( GoalContributionDrilldown::payload( $team_id, $player_id, $window ) );
    }

    /**
     * The route carries a player id, so the gate has to read it.
     *
     * `userCanOrMatrix( 'tt_view_players' )` alone answers "may this person
     * look at players", never "may they look at THIS one" — and at `player`
     * scope `MatrixGate::canAnyScope()` says yes to anyone who is a player
     * or holds any linked child. That admitted every family on the install
     * to every child's record here, because nothing downstream re-asked:
     * the handler takes `player_id` straight from the path.
     *
     * `canViewPlayer()` is the per-record question every neighbouring
     * per-player read already asks.
     */
    public static function can_view( \WP_REST_Request $r ): bool {
        $player_id = absint( $r['player_id'] );
        if ( $player_id <= 0 ) return false;

        return AuthorizationService::canViewPlayer( get_current_user_id(), $player_id );
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
