<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;

/**
 * VctTeamSchedulesRestController — per-team weekday preferences.
 *
 *   GET /vct/teams/{team_id}/schedule?season_id=N
 *   PUT /vct/teams/{team_id}/schedule
 *
 * Two-layer permission_callback:
 *   layer 1 — cap: `tt_vct_plan`
 *   layer 2 — scope: `canPlanForTeam( $uid, $team_id, $activity )`
 */
class VctTeamSchedulesRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vct/teams/(?P<team_id>\d+)/schedule', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'find' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'upsert' ],
                'permission_callback' => [ __CLASS__, 'can_write' ],
                'args'                => self::upsertArgs(),
            ],
        ] );
    }

    /**
     * #3819 — the body `PUT /vct/teams/{team_id}/schedule` takes.
     *
     * Nothing is declared `required`: core checks required params before
     * the permission callback, so a required key would answer a caller
     * without team scope with a 400 rather than the 403 it is owed.
     * `upsert()` names what it needs itself.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function upsertArgs(): array {
        return [
            'team_id'                  => [ 'type' => [ 'integer', 'string' ], 'description' => 'The team, from the URL. A copy in the body is accepted and ignored.' ],
            'season_id'                => [ 'type' => [ 'integer', 'string' ], 'description' => 'The season the weekday preferences apply to.' ],
            'weekdays_bitmask'         => [ 'type' => [ 'integer', 'string' ], 'description' => 'Which weekdays the team trains, as a bitmask of 0-127.' ],
            'default_start_time'       => [ 'type' => 'string', 'description' => 'The usual kick-off time as HH:MM. Blank means none is set.' ],
            'default_duration_minutes' => [ 'type' => [ 'integer', 'string' ], 'description' => 'How long a training usually runs, in minutes.' ],
        ];
    }

    public static function can_read( \WP_REST_Request $r ): bool {
        $uid = get_current_user_id();
        if ( ! AuthorizationService::userCanOrMatrix( $uid, 'tt_vct_plan' ) ) return false;
        $team_id = (int) $r->get_param( 'team_id' );
        return AuthorizationService::canPlanForTeam( $uid, $team_id, 'read' );
    }

    public static function can_write( \WP_REST_Request $r ): bool {
        $uid = get_current_user_id();
        if ( ! AuthorizationService::userCanOrMatrix( $uid, 'tt_vct_plan' ) ) return false;
        $team_id = (int) $r->get_param( 'team_id' );
        return AuthorizationService::canPlanForTeam( $uid, $team_id, 'change' );
    }

    public static function find( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id   = (int) $r->get_param( 'team_id' );
        $season_id = (int) ( $r->get_param( 'season_id' ) ?? 0 );
        if ( $season_id <= 0 ) {
            return RestResponse::error( 'missing_season_id',
                __( 'season_id is required.', 'talenttrack' ), 400 );
        }
        $row = ( new VctTeamSchedulesRepository() )->findForTeamSeason( $team_id, $season_id );
        return RestResponse::success( [ 'schedule' => $row ] );
    }

    public static function upsert( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::upsertArgs() );
        if ( $refused !== null ) return $refused;

        $team_id          = (int)    $r->get_param( 'team_id' );
        $season_id        = (int)    ( $r->get_param( 'season_id' ) ?? 0 );
        $weekdays_bitmask = (int)    ( $r->get_param( 'weekdays_bitmask' ) ?? 0 );
        $start_time       = $r->get_param( 'default_start_time' );
        $duration         = $r->get_param( 'default_duration_minutes' );

        if ( $season_id <= 0 ) {
            return RestResponse::error( 'missing_season_id',
                __( 'season_id is required.', 'talenttrack' ), 400 );
        }
        if ( $weekdays_bitmask < 0 || $weekdays_bitmask > 127 ) {
            return RestResponse::error( 'bad_bitmask',
                __( 'weekdays_bitmask must be 0-127.', 'talenttrack' ), 400 );
        }
        if ( $start_time !== null && $start_time !== '' && ! preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', (string) $start_time ) ) {
            return RestResponse::error( 'bad_start_time',
                __( 'default_start_time must be HH:MM.', 'talenttrack' ), 400 );
        }

        $ok = ( new VctTeamSchedulesRepository() )->upsert(
            $team_id,
            $season_id,
            $weekdays_bitmask,
            is_string( $start_time ) && $start_time !== '' ? (string) $start_time : null,
            $duration !== null && $duration !== '' ? max( 1, (int) $duration ) : null,
            get_current_user_id()
        );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error',
                __( 'The schedule could not be saved.', 'talenttrack' ), 500 );
        }
        $row = ( new VctTeamSchedulesRepository() )->findForTeamSeason( $team_id, $season_id );
        return RestResponse::success( [ 'schedule' => $row ] );
    }
}
