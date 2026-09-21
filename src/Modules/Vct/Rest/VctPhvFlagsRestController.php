<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctPhvFlagsRepository;

/**
 * VctPhvFlagsRestController — per-player Peak Height Velocity flag.
 *
 *   PATCH /vct/players/{id}/phv-flag  body: { is_active, notes }
 *
 * Coach (with team scope on `vct`) flags; HoD/admin clears. The
 * WorkloadCapRule reads `tt_player_phv_flags` to apply the configured
 * `growth_spurt_load_reduction_pct` to flagged players' load
 * contribution.
 */
class VctPhvFlagsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vct/players/(?P<id>\d+)/phv-flag', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'setFlag' ],
                'permission_callback' => [ __CLASS__, 'can_write' ],
                'args'                => self::setFlagArgs(),
            ],
        ] );
    }

    /**
     * #3819 — the body `PATCH /vct/players/{id}/phv-flag` takes.
     *
     * Nothing is declared `required`: core checks required params before
     * the permission callback, and this route's gate is what keeps one
     * club's coach off another club's player.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function setFlagArgs(): array {
        return [
            'id'        => [ 'type' => [ 'integer', 'string' ], 'description' => 'The player, from the URL. A copy in the body is accepted and ignored.' ],
            'is_active' => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Whether the player is in a growth spurt and should carry less load.' ],
            'notes'     => [ 'type' => 'string', 'description' => 'What the flag is based on.' ],
        ];
    }

    public static function can_write( \WP_REST_Request $r ): bool {
        $uid = get_current_user_id();
        if ( ! AuthorizationService::userCanOrMatrix( $uid, 'tt_vct_plan' ) ) return false;
        $team_id = self::playerTeamId( (int) $r->get_param( 'id' ) );
        if ( $team_id <= 0 ) return false;
        return AuthorizationService::canPlanForTeam( $uid, $team_id, 'change' );
    }

    public static function setFlag( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::setFlagArgs() );
        if ( $refused !== null ) return $refused;

        $player_id = (int)    $r->get_param( 'id' );
        $is_active = (bool)   ( $r->get_param( 'is_active' ) ?? false );
        $notes     = (string) ( $r->get_param( 'notes' )     ?? '' );
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_player_id', __( 'Invalid player id.', 'talenttrack' ), 400 );
        }

        $ok = ( new VctPhvFlagsRepository() )->setFlag(
            $player_id, $is_active, get_current_user_id(), sanitize_text_field( $notes )
        );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error',
                __( 'The PHV flag could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'player_id' => $player_id, 'is_active' => $is_active ] );
    }

    private static function playerTeamId( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;
        global $wpdb;
        $players_table = $wpdb->prefix . 'tt_players';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$players_table} WHERE id = %d LIMIT 1",
            $player_id
        ) );
    }
}
