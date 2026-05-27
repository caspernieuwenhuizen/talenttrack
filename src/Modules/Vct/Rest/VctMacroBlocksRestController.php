<?php
namespace TT\Modules\Vct\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;

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
     * Server-side validation mirrors PdpBlocksRestController: contiguous
     * sequences 1..N, valid dates, end >= start, no overlaps. Spec also
     * asks for season-boundary validation; without a Seasons module
     * dependency in the VCT module we settle for date sanity here and
     * trust the caller (the config tile loads the season's start/end
     * client-side and clamps the form inputs).
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

        $blocks = [];
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $blocks[] = [
                'sequence'      => (int)    ( $entry['sequence']   ?? 0 ),
                'label'         => sanitize_text_field( (string) ( $entry['label']      ?? '' ) ),
                'start_date'    => trim( (string) ( $entry['start_date'] ?? '' ) ),
                'end_date'      => trim( (string) ( $entry['end_date']   ?? '' ) ),
                'phase_profile' => is_array( $entry['phase_profile'] ?? null ) ? $entry['phase_profile'] : [],
            ];
        }

        $err = self::validate( $blocks );
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

    /**
     * Server-side validation mirroring PdpBlocksRestController::
     * validate(). Returns null on success, or a localised error
     * message describing the first failure.
     *
     * @param list<array{sequence:int,label:string,start_date:string,end_date:string,phase_profile:array<int,mixed>}> $blocks
     */
    private static function validate( array $blocks ): ?string {
        $count = count( $blocks );
        if ( $count < 1 || $count > 12 ) {
            return __( 'A season must have between 1 and 12 macro-blocks.', 'talenttrack' );
        }
        $sequences = array_map( static fn( $b ) => (int) $b['sequence'], $blocks );
        sort( $sequences );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( $sequences[ $i ] !== ( $i + 1 ) ) {
                return __( 'Block sequence numbers must be contiguous starting from 1 (1, 2, … N).', 'talenttrack' );
            }
        }
        foreach ( $blocks as $b ) {
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $b['start_date'] )
                || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $b['end_date'] ) ) {
                return sprintf(
                    /* translators: %d = block number */
                    __( 'Block %d has an invalid date format. Use YYYY-MM-DD.', 'talenttrack' ),
                    (int) $b['sequence']
                );
            }
            if ( $b['end_date'] < $b['start_date'] ) {
                return sprintf(
                    /* translators: %d = block number */
                    __( 'Block %d ends before it starts.', 'talenttrack' ),
                    (int) $b['sequence']
                );
            }
        }
        $sorted = $blocks;
        usort( $sorted, static fn( $a, $b ): int => strcmp( $a['start_date'], $b['start_date'] ) );
        for ( $i = 1; $i < $count; $i++ ) {
            if ( $sorted[ $i ]['start_date'] <= $sorted[ $i - 1 ]['end_date'] ) {
                return sprintf(
                    /* translators: 1: block A sequence, 2: block B sequence */
                    __( 'Block %1$d overlaps with block %2$d. Blocks must not share dates.', 'talenttrack' ),
                    (int) $sorted[ $i - 1 ]['sequence'],
                    (int) $sorted[ $i ]['sequence']
                );
            }
        }
        return null;
    }
}
