<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Vct\Repositories\VctPhvFlagsRepository;
use TT\Modules\Vct\Services\LoadRestrictionAccess;

/**
 * VctPhvFlagsRestController — per-player load restriction.
 *
 *   PATCH /vct/players/{id}/phv-flag  body: { is_active, notes }
 *
 * The route keeps its path, because the column and the table keep
 * theirs; what it records is that the player must carry less load than
 * the plan asks for, for one of the reasons in
 * `Services\LoadRestriction`. A growth spurt (peak height velocity) is
 * one of them, not the name of the flag.
 *
 * Coach (with team scope on `vct`) sets; HoD/admin clears — one gate,
 * `Services\LoadRestrictionAccess`, shared with the panel on the player
 * profile. The WorkloadCapRule reads `tt_player_phv_flags` to apply the
 * configured `growth_spurt_load_reduction_pct` to restricted players'
 * load contribution.
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
            'is_active' => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Whether the player carries a load restriction and should be given less load than the plan asks for.' ],
            'notes'     => [ 'type' => 'string', 'description' => 'What the restriction is based on.' ],
        ];
    }

    public static function can_write( \WP_REST_Request $r ): bool {
        return LoadRestrictionAccess::canEdit(
            get_current_user_id(),
            (int) $r->get_param( 'id' )
        );
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
                __( 'The load restriction could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'player_id' => $player_id, 'is_active' => $is_active ] );
    }
}
