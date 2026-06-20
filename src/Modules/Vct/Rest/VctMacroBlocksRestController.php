<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;
use TT\Modules\Vct\Validation\VctMacroBlockValidator;

/**
 * VctMacroBlocksRestController — periodization calendar.
 *
 *   GET /vct/macro-blocks?season_id=N&team_id=M
 *
 * Caps: `tt_vct_admin_library` (HoD/admin only). Coaches consume the
 * macro-block read indirectly via ProgressionRule; they don't need
 * direct REST access in MVP.
 *
 * `PUT /vct/macro-blocks` ships in VCT-11 (configuration tile UI).
 */
class VctMacroBlocksRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vct/macro-blocks', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'listForSeason' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'replace' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
    }

    public static function can_admin(): bool {
        return AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_vct_admin_library' );
    }

    public static function listForSeason( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = (int) ( $r->get_param( 'season_id' ) ?? 0 );
        $team_id   = (int) ( $r->get_param( 'team_id' )   ?? 0 );

        $repo = new VctMacroBlocksRepository();

        if ( $season_id === 0 ) {
            return RestResponse::success( [ 'references' => $repo->listReferenceTemplates() ] );
        }
        return RestResponse::success( [
            'season_id' => $season_id,
            'team_id'   => $team_id,
            'blocks'    => $repo->listForSeason( $team_id, $season_id ),
        ] );
    }

    /**
     * Replace the whole block set for (team_id, season_id). Body:
     *   { season_id, team_id (0=club default), blocks: [{sequence,
     *     label, start_date, end_date, phase_profile: [...]}, ...] }
     *
     * Server-side validation lives in the shared VctMacroBlockValidator
     * (so the config tile and any future SaaS writer share one rule set):
     * contiguous sequences 1..N, valid dates, end >= start, no overlaps.
     * Spec also asks for season-boundary validation; without a Seasons
     * module dependency in the VCT module we settle for date sanity here
     * and trust the caller.
     */
    public static function replace( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = (int) ( $r->get_param( 'season_id' ) ?? 0 );
        $team_id   = (int) ( $r->get_param( 'team_id' )   ?? 0 );
        if ( $season_id <= 0 ) {
            return RestResponse::error( 'missing_season_id', __( 'season_id is required.', 'talenttrack' ), 400 );
        }

        $raw = $r->get_param( 'blocks' );
        if ( ! is_array( $raw ) ) {
            return RestResponse::error( 'bad_payload',
                __( 'blocks must be an array of { sequence, label, start_date, end_date, phase_profile }.', 'talenttrack' ),
                400 );
        }

        $blocks = VctMacroBlockValidator::normalise( $raw );

        $err = VctMacroBlockValidator::validate( $blocks );
        if ( $err !== null ) {
            return RestResponse::error( 'invalid_blocks', $err, 400 );
        }

        usort( $blocks, static fn( $a, $b ): int => $a['sequence'] <=> $b['sequence'] );

        $ok = ( new VctMacroBlocksRepository() )->replaceForSeason( $team_id, $season_id, $blocks );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error', __( 'The macro-blocks could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [
            'season_id' => $season_id,
            'team_id'   => $team_id,
            'count'     => count( $blocks ),
        ] );
    }
}
