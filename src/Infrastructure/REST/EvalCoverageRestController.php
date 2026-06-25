<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\EvalWindowsRepository;
use WP_REST_Request;

/**
 * EvalCoverageRestController (#1380) — REST surface for the HoD
 * evaluation-window coverage report.
 *
 *   GET  /eval-coverage          — the coverage matrix + per-coach gaps
 *                                  + per-team attendance compliance
 *   GET  /eval-coverage/windows  — the configured windows
 *   PUT  /eval-coverage/windows  — replace the windows ({windows:[…]})
 *
 * Both the matrix and the windows editor are HoD-level: gated on
 * `tt_view_analytics`. The business logic lives in EvalCoverageService /
 * EvalWindowsRepository so this controller stays a thin transport.
 */
final class EvalCoverageRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/eval-coverage', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'matrix' ],
                'permission_callback' => self::permCan( 'tt_view_analytics' ),
            ],
        ] );
        register_rest_route( self::NS, '/eval-coverage/windows', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'getWindows' ],
                'permission_callback' => self::permCan( 'tt_view_analytics' ),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'saveWindows' ],
                'permission_callback' => self::permCan( 'tt_view_analytics' ),
            ],
        ] );
    }

    public static function matrix( WP_REST_Request $request ): \WP_REST_Response {
        $service  = new EvalCoverageService();
        $coverage = $service->coverage();

        $compliance = [];
        foreach ( $coverage['windows'] as $window ) {
            $compliance[] = [
                'window' => $window,
                'teams'  => $service->attendanceCompliance( $window ),
            ];
        }

        return RestResponse::success( [
            'windows'                => $coverage['windows'],
            'teams'                  => $coverage['teams'],
            'coach_gaps'             => $coverage['coach_gaps'],
            'total_players'          => $coverage['total_players'],
            'total_gaps'             => $coverage['total_gaps'],
            'attendance_compliance'  => $compliance,
            'evaluators'             => $service->evaluators(),
        ] );
    }

    public static function getWindows( WP_REST_Request $request ): \WP_REST_Response {
        return RestResponse::success( [
            'windows' => ( new EvalWindowsRepository() )->all(),
        ] );
    }

    public static function saveWindows( WP_REST_Request $request ): \WP_REST_Response {
        $raw = $request->get_param( 'windows' );
        if ( ! is_array( $raw ) ) {
            return RestResponse::error(
                'bad_payload',
                __( 'Expected a list of windows.', 'talenttrack' ),
                400
            );
        }
        $stored = ( new EvalWindowsRepository() )->save( $raw );
        return RestResponse::success( [ 'windows' => $stored ] );
    }
}
