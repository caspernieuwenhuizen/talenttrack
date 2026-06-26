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
        // #1275 — PATCH /seasons/{id} updates name + dates. Mirrors
        // the existing /current sub-route's shape (PATCH + admin cap)
        // so a future non-WordPress front end has the same operation
        // surface as the new wp-admin edit form.
        register_rest_route( self::NS, '/seasons/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            // #1481 — DELETE is guarded: the current season and any
            // season with linked PDP / staff-dev / VCT records can't be
            // removed (edit it instead), so referential integrity holds.
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        // #1923 - the seasons list is an admin-config read (consumed by
        // the Seasons admin view and the PDP-blocks form, both behind
        // frontend-admin / settings caps). Replace authz-by-login with
        // the matrix-bridged cap. Write stays tt_edit_settings (can_admin).
        return \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix(
            get_current_user_id(), 'tt_access_frontend_admin'
        );
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

    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid season id.', 'talenttrack' ), 400 );
        }

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

        $repo = new SeasonsRepository();
        if ( ! $repo->find( $id ) ) {
            return RestResponse::error( 'not_found',
                __( 'Season not found.', 'talenttrack' ), 404 );
        }
        $ok = $repo->update( $id, [
            'name'       => $name,
            'start_date' => $start,
            'end_date'   => $end,
        ] );
        if ( ! $ok ) {
            Logger::error( 'pdp.season.update.failed', [ 'id' => $id, 'name' => $name ] );
            return RestResponse::error( 'db_error',
                __( 'The season could not be updated.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid season id.', 'talenttrack' ), 400 );
        }
        $repo   = new SeasonsRepository();
        $season = $repo->find( $id );
        if ( $season === null ) {
            return RestResponse::error( 'not_found', __( 'Season not found.', 'talenttrack' ), 404 );
        }
        if ( (int) $season->is_current === 1 ) {
            return RestResponse::error( 'is_current',
                __( 'The current season can’t be deleted. Set another season as current first.', 'talenttrack' ), 409 );
        }
        if ( $repo->isReferenced( $id ) ) {
            return RestResponse::error( 'has_references',
                __( 'This season has linked records (PDP files, staff development or schedules). Edit it instead of deleting.', 'talenttrack' ), 409 );
        }
        if ( ! $repo->delete( $id ) ) {
            Logger::error( 'pdp.season.delete.failed', [ 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The season could not be deleted.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id, 'deleted' => true ] );
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
