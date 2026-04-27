<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;

/**
 * PdpVerdictsRestController — at-most-one verdict per PDP file. PUT
 * is upsert because the database key enforces the cardinality; GET
 * returns the existing verdict or null.
 *
 * Stricter cap gate than the file controller — verdicts are head-of-
 * academy + head-coach only (`tt_edit_pdp_verdict`).
 */
class PdpVerdictsRestController {

    private const NS = 'talenttrack/v1';
    private const ALLOWED_DECISIONS = [ 'promote', 'retain', 'release', 'transfer' ];

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/pdp-files/(?P<id>\d+)/verdict', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_one' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'upsert' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_pdp' )
            || current_user_can( 'tt_edit_pdp' )
            || current_user_can( 'tt_edit_pdp_verdict' );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_pdp_verdict' );
    }

    public static function get_one( \WP_REST_Request $r ): \WP_REST_Response {
        $file_id = absint( $r['id'] );
        if ( $file_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid PDP file id.', 'talenttrack' ), 400 );
        }
        $file = ( new PdpFilesRepository() )->find( $file_id );
        if ( ! $file ) {
            return RestResponse::error( 'not_found', __( 'PDP file not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canSeeFile( $file ) ) {
            return RestResponse::error( 'forbidden',
                __( 'You do not have access to this PDP verdict.', 'talenttrack' ), 403 );
        }
        $row = ( new PdpVerdictsRepository() )->findForFile( $file_id );
        return RestResponse::success( [ 'verdict' => $row ? self::format( $row ) : null ] );
    }

    public static function upsert( \WP_REST_Request $r ): \WP_REST_Response {
        $file_id = absint( $r['id'] );
        if ( $file_id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid PDP file id.', 'talenttrack' ), 400 );
        }
        $file = ( new PdpFilesRepository() )->find( $file_id );
        if ( ! $file ) {
            return RestResponse::error( 'not_found', __( 'PDP file not found.', 'talenttrack' ), 404 );
        }

        $decision = sanitize_text_field( (string) ( $r['decision'] ?? '' ) );
        if ( ! in_array( $decision, self::ALLOWED_DECISIONS, true ) ) {
            return RestResponse::error( 'bad_decision',
                __( 'Decision must be one of promote, retain, release, transfer.', 'talenttrack' ),
                400, [ 'allowed' => self::ALLOWED_DECISIONS ] );
        }

        $payload = [
            'decision' => $decision,
            'summary'  => isset( $r['summary'] ) ? wp_kses_post( (string) $r['summary'] ) : null,
        ];

        // Sign-off captures whichever cap-holder is acting. Head of
        // academy is identified by the `tt_head_dev` role.
        $uid = get_current_user_id();
        if ( current_user_can( 'tt_head_dev' ) || in_array( 'tt_head_dev', (array) wp_get_current_user()->roles, true ) ) {
            $payload['head_of_academy_id'] = $uid;
        } else {
            $payload['coach_id'] = $uid;
        }
        if ( ! empty( $r['signed_off'] ) ) {
            $payload['signed_off_at'] = current_time( 'mysql', true );
        }

        $ok = ( new PdpVerdictsRepository() )->upsertForFile( $file_id, $payload );
        if ( ! $ok ) {
            Logger::error( 'pdp.verdict.upsert.failed', [ 'file_id' => $file_id ] );
            return RestResponse::error( 'db_error',
                __( 'The verdict could not be saved.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [
            'file_id' => $file_id,
            'verdict' => self::format( ( new PdpVerdictsRepository() )->findForFile( $file_id ) ),
        ] );
    }

    private static function canSeeFile( object $file ): bool {
        if ( current_user_can( 'tt_edit_settings' ) ) return true;
        if ( current_user_can( 'tt_edit_pdp_verdict' ) ) return true;
        return current_user_can( 'tt_view_pdp' )
            && QueryHelpers::coach_owns_player( get_current_user_id(), (int) $file->player_id );
    }

    /** @return array<string,mixed> */
    private static function format( ?object $row ): array {
        if ( ! $row ) return [];
        return [
            'id'                  => (int) $row->id,
            'pdp_file_id'         => (int) $row->pdp_file_id,
            'decision'            => (string) $row->decision,
            'summary'             => $row->summary,
            'coach_id'            => $row->coach_id !== null ? (int) $row->coach_id : null,
            'head_of_academy_id'  => $row->head_of_academy_id !== null ? (int) $row->head_of_academy_id : null,
            'signed_off_at'       => $row->signed_off_at,
            'created_at'          => $row->created_at ?? null,
            'updated_at'          => $row->updated_at ?? null,
        ];
    }
}
