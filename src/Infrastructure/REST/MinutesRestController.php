<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\PlayersRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Authorization\AllTeamsScope;

/**
 * MinutesRestController — the two read routes onto a player's minutes.
 *
 * `GET /teams/{id}/players/{pid}/minutes` — #2160, the staff trace-back.
 * Exposes the same per-match breakdown the report drill-down renders, so
 * a non-WordPress front end gets an identical, reconciling trace. Reuses
 * {@see MinutesQuery::matchBreakdownForPlayer()} which reads persisted
 * `record_type='actual'`, non-guest minutes ONLY (#2193 — never
 * estimated or recomputed at report time) so the rows sum exactly to
 * the report total.
 *
 * Cap-gated on `tt_view_reports` plus the same team-scope guard the
 * Team·Minutes report applies: a coach may only trace players on a team
 * they coach; global-scope `activities` readers (HoD / academy admin)
 * see any team.
 *
 * `GET /players/{id}/minutes` — #3666, the same figures as the player and
 * their family read them. Playing time is one of the first things a young
 * player looks at after a match, and until this route there was no way for
 * them to see it: the staff route needs `tt_view_reports` and a coached
 * team, which a player holds neither of. It carries no team parameter (the
 * player's current team is the subject), no share of the available minutes
 * and no other player's figures — a player reads their own playing time,
 * not a league table of their team-mates. The team routes are unchanged
 * and stay staff-only.
 */
final class MinutesRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/teams/(?P<team_id>\d+)/players/(?P<player_id>\d+)/minutes', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'breakdown' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args'                => [
                    'from' => [ 'type' => 'string', 'required' => false ],
                    'to'   => [ 'type' => 'string', 'required' => false ],
                ],
            ],
        ] );

        // #3666 — the player-facing route. Logged-in at the door; the real
        // gate is per player, inside the handler, so a parent whose child
        // has hidden the section gets `section_private` rather than a flat
        // `rest_forbidden` they cannot act on.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/minutes', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'own_minutes' ],
                'permission_callback' => [ __CLASS__, 'is_logged_in' ],
                'args'                => [
                    'id'   => [ 'type' => 'integer', 'required' => true ],
                    'from' => [ 'type' => 'string', 'required' => false ],
                    'to'   => [ 'type' => 'string', 'required' => false ],
                ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_view_reports' );
    }

    public static function is_logged_in(): bool {
        return is_user_logged_in();
    }

    /**
     * GET the per-match minutes breakdown for one player on one team
     * over an optional date window (defaults to the last 12 months).
     */
    public static function breakdown( \WP_REST_Request $r ) {
        $team_id   = absint( $r['team_id'] );
        $player_id = absint( $r['player_id'] );
        if ( $team_id <= 0 || $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid team or player.', 'talenttrack' ), 400 );
        }

        // Team-scope guard — mirrors the Team·Minutes report (#1193).
        $uid = get_current_user_id();
        $is_scope_admin = current_user_can( 'tt_edit_settings' )
            || AllTeamsScope::canSeeAllTeamsActivities( $uid );
        if ( ! $is_scope_admin ) {
            $allowed = array_map( 'intval', array_column( QueryHelpers::get_teams_for_coach( $uid ), 'id' ) );
            if ( ! in_array( $team_id, $allowed, true ) ) {
                return RestResponse::error( 'forbidden_team', __( 'You do not have access to this team.', 'talenttrack' ), 403 );
            }
        }

        $window = self::window( $r );

        return RestResponse::success(
            ( new MinutesQuery() )->playingTimeForPlayer( $team_id, $player_id, $window['from'], $window['to'] )
        );
    }

    /**
     * #3666 — GET the player's own playing time: total minutes and the
     * matches they came from, over an optional window.
     *
     * The subject is the player, not a team, so there is no team
     * parameter: the figures are for the player's current team. Minutes
     * on a team they have since left are out of scope, which keeps this
     * from becoming a second, subtly different history of the same
     * matches.
     */
    public static function own_minutes( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['id'] );
        $uid       = get_current_user_id();
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid player.', 'talenttrack' ), 400 );
        }

        // The single authority on who may see a player: their own record,
        // a linked guardian, team or global staff.
        if ( ! AuthorizationService::canViewPlayer( $uid, $player_id ) ) {
            return RestResponse::error( 'forbidden', __( 'You do not have access to this player.', 'talenttrack' ), 403 );
        }
        // #1867 — and a parent only reads a section the child still shares.
        if ( ! AuthorizationService::parentCanViewSection( $uid, $player_id, 'minutes' ) ) {
            return RestResponse::error( 'section_private', __( 'This section has been kept private.', 'talenttrack' ), 403 );
        }

        $player  = ( new PlayersRepository() )->find( $player_id );
        $team_id = $player ? (int) ( $player->team_id ?? 0 ) : 0;
        $window  = self::window( $r );

        // No team is an answer, not an error: a player between teams has
        // played no minutes for one.
        if ( $team_id <= 0 ) {
            return RestResponse::success( [
                'team_id'       => 0,
                'player_id'     => $player_id,
                'from'          => $window['from'],
                'to'            => $window['to'],
                'total_minutes' => 0,
                'matches'       => [],
            ] );
        }

        return RestResponse::success(
            ( new MinutesQuery() )->playingTimeForPlayer( $team_id, $player_id, $window['from'], $window['to'] )
        );
    }

    /**
     * The requested window, falling back to the shared default. A date
     * that is not `Y-m-d` is replaced rather than refused, so a malformed
     * bookmark still answers with something truthful.
     *
     * @return array{from:string,to:string}
     */
    private static function window( \WP_REST_Request $r ): array {
        $defaults = MinutesQuery::defaultWindow();
        $from = isset( $r['from'] ) ? sanitize_text_field( (string) $r['from'] ) : $defaults['from'];
        $to   = isset( $r['to'] )   ? sanitize_text_field( (string) $r['to'] )   : $defaults['to'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];
        return [ 'from' => $from, 'to' => $to ];
    }
}
