<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;

/**
 * ScoutingVisitsRestController — /wp-json/talenttrack/v1/scouting-visits
 *
 * Create + update + archive endpoints backing the scout's
 * scouting-plan list view. Read access is via PHP-rendered views
 * (FrontendScoutingPlanView / FrontendScoutingVisitDetailView).
 *
 * Cap model: write requires `tt_edit_prospects` (which every scout
 * holds). A scout may only edit / archive their own visits;
 * `tt_manage_prospects` (HoD + admin) can edit any.
 */
class ScoutingVisitsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/scouting-visits', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );

        register_rest_route( self::NS, '/scouting-visits/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'update' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_prospects' )
            || current_user_can( 'tt_manage_prospects' )
            || current_user_can( 'tt_edit_settings' );
    }

    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $visit_date = sanitize_text_field( (string) ( $r['visit_date'] ?? '' ) );
        $location   = sanitize_text_field( (string) ( $r['location'] ?? '' ) );

        if ( $visit_date === '' ) {
            return RestResponse::error( 'missing_fields',
                __( 'Visit date is required.', 'talenttrack' ), 400 );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $visit_date ) ) {
            return RestResponse::error( 'invalid_date',
                __( 'Visit date must be YYYY-MM-DD.', 'talenttrack' ), 400 );
        }
        if ( $location === '' ) {
            return RestResponse::error( 'missing_fields',
                __( 'Location is required.', 'talenttrack' ), 400 );
        }

        $payload = [
            'visit_date'        => $visit_date,
            'visit_time'        => self::normaliseTime( $r['visit_time'] ?? null ),
            'location'          => $location,
            'event_description' => isset( $r['event_description'] )
                ? sanitize_text_field( (string) $r['event_description'] )
                : null,
            'age_groups_csv'    => isset( $r['age_groups_csv'] )
                ? self::sanitiseCsv( (string) $r['age_groups_csv'] )
                : null,
            'notes'             => isset( $r['notes'] )
                ? sanitize_textarea_field( (string) $r['notes'] )
                : null,
            'scout_user_id'     => isset( $r['scout_user_id'] ) && (int) $r['scout_user_id'] > 0
                ? (int) $r['scout_user_id']
                : get_current_user_id(),
            'status'            => self::normaliseStatus( (string) ( $r['status'] ?? '' ) ),
        ];

        $id = ( new ScoutingVisitsRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'scouting_visit.create.failed', [ 'payload' => $payload ] );
            return RestResponse::error( 'db_error',
                __( 'The scouting visit could not be saved.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid scouting visit id.', 'talenttrack' ), 400 );
        }

        $repo  = new ScoutingVisitsRepository();
        $row   = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Scouting visit not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canEditRow( $row ) ) {
            return RestResponse::error( 'forbidden', __( 'You can only edit your own scouting visits.', 'talenttrack' ), 403 );
        }

        $patch = [];
        if ( isset( $r['visit_date'] ) ) {
            $d = sanitize_text_field( (string) $r['visit_date'] );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
                return RestResponse::error( 'invalid_date',
                    __( 'Visit date must be YYYY-MM-DD.', 'talenttrack' ), 400 );
            }
            $patch['visit_date'] = $d;
        }
        if ( array_key_exists( 'visit_time', $r->get_params() ) ) {
            $patch['visit_time'] = self::normaliseTime( $r['visit_time'] );
        }
        if ( isset( $r['location'] ) ) {
            $patch['location'] = sanitize_text_field( (string) $r['location'] );
        }
        if ( array_key_exists( 'event_description', $r->get_params() ) ) {
            $patch['event_description'] = $r['event_description'] !== null && $r['event_description'] !== ''
                ? sanitize_text_field( (string) $r['event_description'] )
                : null;
        }
        if ( array_key_exists( 'age_groups_csv', $r->get_params() ) ) {
            $patch['age_groups_csv'] = $r['age_groups_csv'] !== null && $r['age_groups_csv'] !== ''
                ? self::sanitiseCsv( (string) $r['age_groups_csv'] )
                : null;
        }
        if ( array_key_exists( 'notes', $r->get_params() ) ) {
            $patch['notes'] = $r['notes'] !== null && $r['notes'] !== ''
                ? sanitize_textarea_field( (string) $r['notes'] )
                : null;
        }
        if ( isset( $r['status'] ) ) {
            $patch['status'] = self::normaliseStatus( (string) $r['status'] );
        }

        if ( ! $patch ) {
            return RestResponse::success( [ 'id' => $id, 'changed' => false ] );
        }

        $ok = $repo->update( $id, $patch );
        return RestResponse::success( [ 'id' => $id, 'changed' => $ok ] );
    }

    public static function archive( \WP_REST_Request $r ): \WP_REST_Response {
        $id = (int) $r['id'];
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid scouting visit id.', 'talenttrack' ), 400 );
        }
        $repo = new ScoutingVisitsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return RestResponse::error( 'not_found', __( 'Scouting visit not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canEditRow( $row ) ) {
            return RestResponse::error( 'forbidden', __( 'You can only archive your own scouting visits.', 'talenttrack' ), 403 );
        }
        $repo->archive( $id );
        return RestResponse::success( [ 'id' => $id, 'archived' => true ] );
    }

    private static function canEditRow( object $row ): bool {
        if ( current_user_can( 'tt_manage_prospects' ) || current_user_can( 'tt_edit_settings' ) ) {
            return true;
        }
        return (int) ( $row->scout_user_id ?? 0 ) === get_current_user_id();
    }

    private static function normaliseTime( $raw ): ?string {
        if ( $raw === null || $raw === '' ) return null;
        $raw = (string) $raw;
        if ( preg_match( '/^\d{2}:\d{2}$/', $raw ) ) {
            return $raw . ':00';
        }
        if ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $raw ) ) {
            return $raw;
        }
        return null;
    }

    private static function sanitiseCsv( string $raw ): ?string {
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        if ( ! $parts ) return null;
        $parts = array_map( static fn( $v ) => sanitize_key( (string) $v ), $parts );
        return implode( ',', array_filter( $parts ) );
    }

    private static function normaliseStatus( string $raw ): string {
        $allowed = [
            ScoutingVisitsRepository::STATUS_PLANNED,
            ScoutingVisitsRepository::STATUS_COMPLETED,
            ScoutingVisitsRepository::STATUS_CANCELLED,
        ];
        return in_array( $raw, $allowed, true ) ? $raw : ScoutingVisitsRepository::STATUS_PLANNED;
    }
}
