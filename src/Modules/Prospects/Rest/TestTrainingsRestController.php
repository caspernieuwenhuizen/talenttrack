<?php
namespace TT\Modules\Prospects\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Prospects\Repositories\TestTrainingsRepository;

/**
 * TestTrainingsRestController (v3.110.113) — /wp-json/talenttrack/v1/test-trainings
 *
 * POST endpoint backing the `+ New test training` action card on the
 * HoD dashboard. Mirrors the field surface in `TestTrainingsRepository::create()`:
 *
 *   - date                (DATETIME, required)
 *   - location            (string, optional)
 *   - age_group_lookup_id (int,    optional)
 *   - coach_user_id       (int,    defaults to current user)
 *   - notes               (string, optional)
 *
 * Read endpoint deliberately omitted — list rendering still goes
 * through the `onboarding-pipeline` view's existing surfaces. Pure
 * create surface for the dashboard CTA.
 */
class TestTrainingsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/test-trainings', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
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
        $date = sanitize_text_field( (string) ( $r['date'] ?? '' ) );
        if ( $date === '' ) {
            return RestResponse::error( 'missing_fields',
                __( 'Date is required.', 'talenttrack' ), 400 );
        }

        // Normalise the date — accept either DATE (Y-m-d) or DATETIME
        // (Y-m-d H:i:s) from the form; persist as DATETIME (the
        // repository column type).
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date .= ' 18:00:00'; // sensible default time if the form omitted it
        }

        $payload = [
            'date'                => $date,
            'location'            => isset( $r['location'] )
                ? sanitize_text_field( (string) $r['location'] )
                : null,
            'age_group_lookup_id' => isset( $r['age_group_lookup_id'] ) && (int) $r['age_group_lookup_id'] > 0
                ? (int) $r['age_group_lookup_id']
                : null,
            'coach_user_id'       => isset( $r['coach_user_id'] ) && (int) $r['coach_user_id'] > 0
                ? (int) $r['coach_user_id']
                : get_current_user_id(),
            'notes'               => isset( $r['notes'] )
                ? sanitize_textarea_field( (string) $r['notes'] )
                : null,
        ];

        $id = ( new TestTrainingsRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'test_training.create.failed', [ 'payload' => $payload ] );
            return RestResponse::error( 'db_error',
                __( 'The test training could not be saved.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [ 'id' => $id ] );
    }
}
