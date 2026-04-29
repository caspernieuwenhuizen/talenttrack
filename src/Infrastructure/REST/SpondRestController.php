<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Spond\SpondSync;

/**
 * SpondRestController (#0031) — POST /teams/{id}/spond/sync.
 *
 * Manager-only ( `tt_edit_teams` ). Triggers an immediate sync of one
 * team and returns the per-team summary. The cron handler keeps running
 * hourly in the background; this endpoint is for the team-form
 * "Refresh now" button + scripted use cases.
 */
final class SpondRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/spond/sync', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'syncTeam' ],
            'permission_callback' => [ __CLASS__, 'canEdit' ],
        ] );
    }

    public static function canEdit(): bool {
        return current_user_can( 'tt_edit_teams' );
    }

    public static function syncTeam( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        $result = SpondSync::syncTeam( $team_id );
        return RestResponse::success( $result );
    }
}
