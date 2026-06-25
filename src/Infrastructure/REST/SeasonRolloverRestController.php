<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Players\SeasonRollover\SeasonRolloverService;

/**
 * SeasonRolloverRestController (#1381) — /wp-json/talenttrack/v1
 *
 * Bulk cohort promotion at season end. Two resource-oriented endpoints:
 *
 *   POST /season-rollover/plan     — dry-run; returns the per-player change
 *                                    list and counts, mutating nothing.
 *   POST /season-rollover/execute  — performs the rollover (backup-first,
 *                                    then per-player team move + journey
 *                                    event).
 *
 * Both call into SeasonRolloverService, the same domain layer the frontend
 * view uses (CLAUDE.md §4). Gated by `tt_manage_players` via the capability
 * model, never a role-string check.
 */
class SeasonRolloverRestController extends BaseController {

    public const CAP = 'tt_manage_players';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/season-rollover/plan', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'plan' ],
                'permission_callback' => self::permCan( self::CAP ),
            ],
        ] );
        register_rest_route( self::NS, '/season-rollover/execute', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'execute' ],
                'permission_callback' => self::permCan( self::CAP ),
            ],
        ] );
    }

    public static function plan( \WP_REST_Request $r ): \WP_REST_Response {
        $body = (array) $r->get_json_params();
        $plan = ( new SeasonRolloverService() )->plan(
            self::extractMapping( $body['mapping'] ?? null ),
            self::extractSelections( $body['selections'] ?? null ),
            (string) ( $body['effective_date'] ?? '' )
        );
        return RestResponse::success( $plan );
    }

    public static function execute( \WP_REST_Request $r ): \WP_REST_Response {
        $body   = (array) $r->get_json_params();
        $result = ( new SeasonRolloverService() )->execute(
            self::extractMapping( $body['mapping'] ?? null ),
            self::extractSelections( $body['selections'] ?? null ),
            (string) ( $body['effective_date'] ?? '' ),
            sanitize_textarea_field( (string) ( $body['reason'] ?? '' ) )
        );
        if ( empty( $result['ok'] ) ) {
            return RestResponse::error(
                'rollover_failed',
                $result['error'] !== '' ? (string) $result['error'] : __( 'The rollover could not be completed.', 'talenttrack' ),
                500,
                $result
            );
        }
        return RestResponse::success( $result );
    }

    /**
     * @param mixed $raw
     * @return array<int,int> source_team_id => target_team_id
     */
    private static function extractMapping( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $source => $target ) {
            $source_id = (int) $source;
            if ( $source_id <= 0 ) continue;
            $out[ $source_id ] = (int) $target;
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<int,string> player_id => action
     */
    private static function extractSelections( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $player => $action ) {
            $player_id = (int) $player;
            if ( $player_id <= 0 ) continue;
            $out[ $player_id ] = SeasonRolloverService::normaliseAction( (string) $action );
        }
        return $out;
    }
}
