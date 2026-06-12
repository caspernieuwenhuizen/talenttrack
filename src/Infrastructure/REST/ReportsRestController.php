<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\CoachEvalQualityQuery;
use WP_REST_Request;

/**
 * ReportsRestController (#1367) — REST surface for standard reports
 * that need a non-WordPress consumer per CLAUDE.md §4.
 *
 *   GET /reports/coach-evaluation-quality
 *       filters: team_id, date_from, date_to (Y-m-d)
 *
 * Permission: `tt_view_reports` PLUS academy-wide scope
 * (`tt_view_all_teams` or the settings-admin roll-up) — this is the
 * HoD's coach-quality lens; coaches must not read each other's
 * stats. Mirrors the scope gate `FrontendStandardReportsView` applies
 * to the same renderer.
 */
final class ReportsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/reports/coach-evaluation-quality', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'coachEvalQuality' ],
                'permission_callback' => static function (): bool {
                    return current_user_can( 'tt_view_reports' )
                        && ( current_user_can( 'tt_view_all_teams' ) || current_user_can( 'tt_edit_settings' ) );
                },
                'args'                => [
                    'team_id'   => [ 'sanitize_callback' => 'absint',              'required' => false ],
                    'date_from' => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                    'date_to'   => [ 'sanitize_callback' => 'sanitize_text_field', 'required' => false ],
                ],
            ],
        ] );
    }

    public static function coachEvalQuality( WP_REST_Request $req ): \WP_REST_Response {
        $rows = ( new CoachEvalQualityQuery() )->rows( [
            'team_id'   => (int) $req->get_param( 'team_id' ),
            'date_from' => (string) $req->get_param( 'date_from' ),
            'date_to'   => (string) $req->get_param( 'date_to' ),
        ] );
        return RestResponse::success( [
            'rows'                   => $rows,
            'low_variance_threshold' => CoachEvalQualityQuery::LOW_VARIANCE_THRESHOLD,
            'min_ratings_for_flag'   => CoachEvalQualityQuery::MIN_RATINGS_FOR_FLAG,
        ] );
    }
}
