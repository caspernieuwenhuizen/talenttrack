<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Authorization\AllTeamsScope;

/**
 * MinutesRestController — /wp-json/talenttrack/v1/teams/{id}/players/{pid}/minutes
 *
 * #2160 — minutes audit / trace-back over REST. Exposes the same
 * per-match breakdown the report drill-down renders, so a non-WordPress
 * front end gets an identical, reconciling trace. Reuses the hardened
 * {@see MinutesQuery::matchBreakdownForPlayer()} (persisted `actual` /
 * non-guest rows first, execution recompute second, never a fabricated
 * estimate) so the rows sum exactly to the report total.
 *
 * Cap-gated on `tt_view_reports` plus the same team-scope guard the
 * Team·Minutes report applies: a coach may only trace players on a team
 * they coach; global-scope `activities` readers (HoD / academy admin)
 * see any team.
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
    }

    public static function can_view(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_view_reports' );
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

        $defaults = self::defaultWindow();
        $from = isset( $r['from'] ) ? sanitize_text_field( (string) $r['from'] ) : $defaults['from'];
        $to   = isset( $r['to'] )   ? sanitize_text_field( (string) $r['to'] )   : $defaults['to'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $defaults['from'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $defaults['to'];

        $rows  = ( new MinutesQuery() )->matchBreakdownForPlayer( $team_id, $player_id, $from, $to );
        $total = 0;
        foreach ( $rows as $row ) $total += (int) $row['minutes'];

        return RestResponse::success( [
            'team_id'       => $team_id,
            'player_id'     => $player_id,
            'from'          => $from,
            'to'            => $to,
            'total_minutes' => $total,
            'matches'       => $rows,
        ] );
    }

    /** @return array{from:string,to:string} */
    private static function defaultWindow(): array {
        return [
            'from' => gmdate( 'Y-m-d', strtotime( '-12 months' ) ),
            'to'   => gmdate( 'Y-m-d' ),
        ];
    }
}
