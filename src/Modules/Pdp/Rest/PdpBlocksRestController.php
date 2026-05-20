<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Pdp\Repositories\PdpBlocksRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * PdpBlocksRestController (v3.110.191) — academy-configurable PDP
 * cycle blocks. Two routes:
 *
 *   GET  /talenttrack/v1/pdp-blocks?season_id=N  — read
 *   PUT  /talenttrack/v1/pdp-blocks?season_id=N  — replace whole set
 *
 * Read is open to any logged-in user. Write requires `tt_edit_settings`
 * (admin tier), matching SeasonsRestController.
 *
 * PUT validates every constraint before persisting (see validate()).
 * Validation failures leave the prior set intact (delete happens
 * only after every block validates).
 */
class PdpBlocksRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/pdp-blocks', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'replace' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return is_user_logged_in();
    }

    public static function can_admin(): bool {
        return current_user_can( 'tt_edit_settings' );
    }

    public static function list( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = absint( $r->get_param( 'season_id' ) );
        if ( $season_id <= 0 ) {
            return RestResponse::error( 'missing_season',
                __( 'season_id is required.', 'talenttrack' ), 400 );
        }
        $rows = ( new PdpBlocksRepository() )->listForSeason( $season_id );
        return RestResponse::success( [
            'season_id' => $season_id,
            'blocks'    => $rows,
        ] );
    }

    public static function replace( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = absint( $r->get_param( 'season_id' ) );
        if ( $season_id <= 0 ) {
            return RestResponse::error( 'missing_season',
                __( 'season_id is required.', 'talenttrack' ), 400 );
        }
        $season = ( new SeasonsRepository() )->find( $season_id );
        if ( $season === null ) {
            return RestResponse::error( 'bad_season',
                __( 'Season not found.', 'talenttrack' ), 404 );
        }

        $raw = $r->get_param( 'blocks' );
        if ( ! is_array( $raw ) ) {
            return RestResponse::error( 'bad_payload',
                __( 'blocks must be an array of { sequence, start_date, end_date } entries.', 'talenttrack' ), 400 );
        }

        $blocks = [];
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $blocks[] = [
                'sequence'   => (int) ( $entry['sequence']   ?? 0 ),
                'start_date' => trim( (string) ( $entry['start_date'] ?? '' ) ),
                'end_date'   => trim( (string) ( $entry['end_date']   ?? '' ) ),
            ];
        }

        $err = self::validate( $blocks, (string) $season->start_date, (string) $season->end_date );
        if ( $err !== null ) {
            return RestResponse::error( 'invalid_blocks', $err, 400 );
        }

        usort( $blocks, static fn( $a, $b ): int => $a['sequence'] <=> $b['sequence'] );

        $ok = ( new PdpBlocksRepository() )->replaceForSeason( $season_id, $blocks );
        if ( ! $ok ) {
            return RestResponse::error( 'db_error',
                __( 'The blocks could not be saved. Please retry.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'season_id' => $season_id, 'count' => count( $blocks ) ] );
    }

    /**
     * Server-side validation. Returns null on success, or a localized
     * error message describing the first failure. Mirrors the admin
     * UI's pre-submit checks so a hand-rolled REST caller can't write
     * a bad set.
     *
     * @param list<array{sequence:int,start_date:string,end_date:string}> $blocks
     */
    private static function validate( array $blocks, string $season_start, string $season_end ): ?string {
        $count = count( $blocks );
        if ( $count < 1 || $count > 4 ) {
            return __( 'A cycle must have between 1 and 4 blocks.', 'talenttrack' );
        }
        $sequences = array_map( static fn( $b ) => (int) $b['sequence'], $blocks );
        sort( $sequences );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( $sequences[ $i ] !== ( $i + 1 ) ) {
                return __( 'Block sequence numbers must be contiguous starting from 1 (1, 2, … N).', 'talenttrack' );
            }
        }

        foreach ( $blocks as $b ) {
            $start = $b['start_date'];
            $end   = $b['end_date'];
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ||
                 ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
                return sprintf(
                    /* translators: %d = block number */
                    __( 'Block %d has an invalid date format. Use YYYY-MM-DD.', 'talenttrack' ),
                    (int) $b['sequence']
                );
            }
            if ( $end < $start ) {
                return sprintf(
                    /* translators: %d = block number */
                    __( 'Block %d ends before it starts.', 'talenttrack' ),
                    (int) $b['sequence']
                );
            }
            if ( $start < $season_start || $end > $season_end ) {
                return sprintf(
                    /* translators: 1: block number, 2: season start, 3: season end */
                    __( 'Block %1$d extends outside the season window (%2$s – %3$s).', 'talenttrack' ),
                    (int) $b['sequence'], $season_start, $season_end
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
