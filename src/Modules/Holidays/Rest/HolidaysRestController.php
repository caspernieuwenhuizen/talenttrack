<?php
namespace TT\Modules\Holidays\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Holidays\Repositories\HolidaysRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * HolidaysRestController (#1480) — resource-oriented CRUD for
 * `/talenttrack/v1/holidays`. Read is gated on `tt_view_holidays`,
 * mutations on `tt_manage_holidays`. All logic lives in the repository.
 */
final class HolidaysRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/holidays', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_holidays' ],
                'permission_callback' => self::can( 'tt_view_holidays' ),
                'args'                => [
                    'from' => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'to'   => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'create_holiday' ],
                'permission_callback' => self::can( 'tt_manage_holidays' ),
            ],
        ] );

        register_rest_route( self::NS, '/holidays/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_holiday' ],
                'permission_callback' => self::can( 'tt_view_holidays' ),
            ],
            [
                'methods'             => 'PUT|PATCH',
                'callback'            => [ self::class, 'update_holiday' ],
                'permission_callback' => self::can( 'tt_manage_holidays' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete_holiday' ],
                'permission_callback' => self::can( 'tt_manage_holidays' ),
            ],
        ] );

        // #1784 — referential-integrity permanent delete (the DELETE above
        // only archives). Holidays are standalone, so this just removes the
        // row; fail-closed if anything ever references it.
        register_rest_route( self::NS, '/holidays/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete_holiday_permanently' ],
                'permission_callback' => self::can( 'tt_edit_settings' ),
            ],
        ] );
    }

    private static function can( string $cap ): \Closure {
        return static fn() => current_user_can( $cap );
    }

    /** #1784 — permanently delete a holiday (irreversible). Gated by tt_edit_settings. */
    public static function delete_holiday_permanently( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req['id'];
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid holiday id.', 'talenttrack' ), 400 );
        try {
            $n = ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'holiday', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) return RestResponse::error( 'not_found', __( 'Holiday not found.', 'talenttrack' ), 404 );
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function list_holidays( WP_REST_Request $req ): WP_REST_Response {
        $rows = ( new HolidaysRepository() )->list(
            (string) $req->get_param( 'from' ),
            (string) $req->get_param( 'to' )
        );
        $serialized = array_map( [ self::class, 'serialize' ], $rows );
        // Paginated shape the FrontendListTable consumes (holidays are a
        // small set, so the whole list ships in one page).
        return RestResponse::success( [
            'rows'     => $serialized,
            'total'    => count( $serialized ),
            'page'     => 1,
            'per_page' => max( 1, count( $serialized ) ),
        ] );
    }

    public static function get_holiday( WP_REST_Request $req ): WP_REST_Response {
        $row = ( new HolidaysRepository() )->findById( (int) $req['id'] );
        if ( ! $row ) return RestResponse::error( 'not_found', __( 'Holiday not found.', 'talenttrack' ), 404 );
        return RestResponse::success( self::serialize( $row ) );
    }

    public static function create_holiday( WP_REST_Request $req ): WP_REST_Response {
        $body = is_array( $req->get_json_params() ) ? $req->get_json_params() : [];
        $err  = self::validate( $body );
        if ( $err !== [] ) return RestResponse::errors( $err, 400 );

        $id = ( new HolidaysRepository() )->create( self::clean( $body ) );
        if ( $id <= 0 ) return RestResponse::error( 'create_failed', __( 'Could not create the holiday.', 'talenttrack' ), 500 );

        $row = ( new HolidaysRepository() )->findById( $id );
        return RestResponse::success( $row ? self::serialize( $row ) : [ 'id' => $id ], 201 );
    }

    public static function update_holiday( WP_REST_Request $req ): WP_REST_Response {
        $repo = new HolidaysRepository();
        $id   = (int) $req['id'];
        if ( ! $repo->findById( $id ) ) return RestResponse::error( 'not_found', __( 'Holiday not found.', 'talenttrack' ), 404 );

        $body = is_array( $req->get_json_params() ) ? $req->get_json_params() : [];
        // Validate only the fields that are present + supplied date range.
        $err = self::validate( $body, true );
        if ( $err !== [] ) return RestResponse::errors( $err, 400 );

        if ( ! $repo->update( $id, self::clean( $body ) ) ) {
            return RestResponse::error( 'update_failed', __( 'Could not update the holiday.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_holiday( WP_REST_Request $req ): WP_REST_Response {
        $repo = new HolidaysRepository();
        $id   = (int) $req['id'];
        if ( ! $repo->findById( $id ) ) return RestResponse::error( 'not_found', __( 'Holiday not found.', 'talenttrack' ), 404 );
        if ( ! $repo->archive( $id ) ) return RestResponse::error( 'delete_failed', __( 'Could not delete the holiday.', 'talenttrack' ), 500 );
        return RestResponse::success( [ 'deleted' => 1 ] );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<int,array{code:string,message:string}>
     */
    private static function validate( array $body, bool $partial = false ): array {
        $errors = [];
        $need = static fn( $k ) => ! $partial || array_key_exists( $k, $body );

        if ( $need( 'name' ) && trim( (string) ( $body['name'] ?? '' ) ) === '' ) {
            $errors[] = [ 'code' => 'name', 'message' => __( 'A name is required.', 'talenttrack' ) ];
        }
        $from = (string) ( $body['start_date'] ?? '' );
        $to   = (string) ( $body['end_date'] ?? '' );
        $date_re = '/^\d{4}-\d{2}-\d{2}$/';
        if ( $need( 'start_date' ) && ! preg_match( $date_re, $from ) ) {
            $errors[] = [ 'code' => 'start_date', 'message' => __( 'A valid start date is required.', 'talenttrack' ) ];
        }
        if ( $need( 'end_date' ) && ! preg_match( $date_re, $to ) ) {
            $errors[] = [ 'code' => 'end_date', 'message' => __( 'A valid end date is required.', 'talenttrack' ) ];
        }
        if ( $errors === [] && preg_match( $date_re, $from ) && preg_match( $date_re, $to ) && $from > $to ) {
            $errors[] = [ 'code' => 'range', 'message' => __( 'The start date must be on or before the end date.', 'talenttrack' ) ];
        }
        return $errors;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function clean( array $body ): array {
        $out = [];
        if ( array_key_exists( 'name', $body ) )       $out['name']       = sanitize_text_field( (string) $body['name'] );
        if ( array_key_exists( 'start_date', $body ) ) $out['start_date'] = sanitize_text_field( (string) $body['start_date'] );
        if ( array_key_exists( 'end_date', $body ) )   $out['end_date']   = sanitize_text_field( (string) $body['end_date'] );
        if ( array_key_exists( 'note', $body ) )       $out['note']       = sanitize_textarea_field( (string) $body['note'] );
        if ( array_key_exists( 'color', $body ) )      $out['color']      = sanitize_hex_color( (string) $body['color'] ) ?: '';
        return $out;
    }

    /** @return array<string,mixed> */
    private static function serialize( object $row ): array {
        // #1602 — `detail_url` powers the FrontendListTable row-link
        // (row_url_key) and points at the in-place edit form. Only emit
        // it for users who can actually edit; a read-only viewer gets a
        // null and the row stays non-clickable.
        $id         = (int) $row->id;
        $detail_url = null;
        if ( current_user_can( 'tt_manage_holidays' ) ) {
            $detail_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'holidays', 'edit' => $id ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            ) );
        }
        return [
            'id'         => $id,
            'uuid'       => (string) $row->uuid,
            'name'       => (string) $row->name,
            'start_date' => (string) $row->start_date,
            'end_date'   => (string) $row->end_date,
            'note'       => $row->note !== null ? (string) $row->note : null,
            'color'      => $row->color !== null ? (string) $row->color : null,
            'detail_url' => $detail_url,
        ];
    }
}
