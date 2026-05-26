<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctWorkloadSnapshotsRepository;

/**
 * VctWorkloadRestController — per-player + per-team workload aggregates.
 *
 *   GET /vct/players/{id}/workload?from=...&to=...
 *   GET /vct/teams/{id}/workload?from=...&to=...
 *
 * Cap: `tt_vct_view_load` (HoD/admin in MVP — coaches see the
 * aggregates indirectly through the wizard preview). Scope check
 * for player endpoints resolves the player's team_id and applies
 * the team scope check.
 *
 * Phase 2 ships the dashboard UI consuming these endpoints; MVP
 * surfaces them for the wizard's `near_weekly_envelope` callout.
 */
class VctWorkloadRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vct/players/(?P<id>\d+)/workload', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'forPlayer' ],
                'permission_callback' => [ __CLASS__, 'can_player' ],
            ],
        ] );

        register_rest_route( self::NS, '/vct/teams/(?P<id>\d+)/workload', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'forTeam' ],
                'permission_callback' => [ __CLASS__, 'can_team' ],
            ],
        ] );
    }

    public static function can_player( \WP_REST_Request $r ): bool {
        $uid = get_current_user_id();
        if ( ! AuthorizationService::userCanOrMatrix( $uid, 'tt_vct_view_load' ) ) return false;
        $team_id = self::playerTeamId( (int) $r->get_param( 'id' ) );
        if ( $team_id <= 0 ) return false;
        return AuthorizationService::canPlanForTeam( $uid, $team_id, 'read' );
    }

    public static function can_team( \WP_REST_Request $r ): bool {
        $uid = get_current_user_id();
        if ( ! AuthorizationService::userCanOrMatrix( $uid, 'tt_vct_view_load' ) ) return false;
        return AuthorizationService::canPlanForTeam( $uid, (int) $r->get_param( 'id' ), 'read' );
    }

    public static function forPlayer( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int)    $r->get_param( 'id' );
        $from      = (string) ( $r->get_param( 'from' ) ?? gmdate( 'Y-m-d', strtotime( '-28 days' ) ) );
        $to        = (string) ( $r->get_param( 'to' )   ?? gmdate( 'Y-m-d' ) );
        $rows = ( new VctWorkloadSnapshotsRepository() )->listForPlayer( $player_id, $from, $to );
        return RestResponse::success( [ 'player_id' => $player_id, 'snapshots' => $rows ] );
    }

    public static function forTeam( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r->get_param( 'id' );
        global $wpdb;
        $players_table = $wpdb->prefix . 'tt_players';
        $player_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$players_table} WHERE team_id = %d AND archived_at IS NULL",
            $team_id
        ) );
        $from = (string) ( $r->get_param( 'from' ) ?? gmdate( 'Y-m-d', strtotime( '-28 days' ) ) );
        $to   = (string) ( $r->get_param( 'to' )   ?? gmdate( 'Y-m-d' ) );
        $repo = new VctWorkloadSnapshotsRepository();
        $out = [];
        foreach ( (array) $player_ids as $pid ) {
            $out[ (int) $pid ] = $repo->listForPlayer( (int) $pid, $from, $to );
        }
        return RestResponse::success( [ 'team_id' => $team_id, 'per_player' => $out ] );
    }

    private static function playerTeamId( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;
        global $wpdb;
        $players_table = $wpdb->prefix . 'tt_players';
        $tid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$players_table} WHERE id = %d LIMIT 1",
            $player_id
        ) );
        return $tid;
    }
}
