<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * SeasonsRestController — /wp-json/talenttrack/v1/seasons
 *
 * #0044 Sprint 1. Tiny CRUD; the wp-admin season manager from Sprint 2
 * will sit on top of these. Anyone authenticated can read; only
 * tt_edit_settings can create or flip the current flag.
 */
class SeasonsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/seasons', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
        register_rest_route( self::NS, '/seasons/(?P<id>\d+)/current', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'set_current' ],
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

    public static function list(): \WP_REST_Response {
        $repo    = new SeasonsRepository();
        $current = $repo->current();
        $rows    = array_map( [ __CLASS__, 'format' ], $repo->all() );
        return RestResponse::success( [
            'rows'       => $rows,
            'current_id' => $current ? (int) $current->id : null,
        ] );
    }

    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $name  = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
        $start = sanitize_text_field( (string) ( $r['start_date'] ?? '' ) );
        $end   = sanitize_text_field( (string) ( $r['end_date'] ?? '' ) );

        if ( $name === '' || $start === '' || $end === '' ) {
            return RestResponse::error( 'missing_fields',
                __( 'Name, start date, and end date are required.', 'talenttrack' ), 400 );
        }
        if ( strtotime( $end ) <= strtotime( $start ) ) {
            return RestResponse::error( 'bad_range',
                __( 'End date must be after start date.', 'talenttrack' ), 400 );
        }

        $id = ( new SeasonsRepository() )->create( [
            'name'       => $name,
            'start_date' => $start,
            'end_date'   => $end,
        ] );
        if ( $id <= 0 ) {
            Logger::error( 'pdp.season.create.failed', [ 'name' => $name ] );
            return RestResponse::error( 'db_error',
                __( 'The season could not be created.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function set_current( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid season id.', 'talenttrack' ), 400 );
        }
        $ok = ( new SeasonsRepository() )->setCurrent( $id );
        if ( ! $ok ) {
            return RestResponse::error( 'not_found',
                __( 'Season not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'id' => $id, 'is_current' => true ] );
    }

    /** @return array<string,mixed> */
    private static function format( object $row ): array {
        return [
            'id'         => (int) $row->id,
            'name'       => (string) $row->name,
            'start_date' => (string) $row->start_date,
            'end_date'   => (string) $row->end_date,
            'is_current' => (int) $row->is_current === 1,
        ];
    }
}
