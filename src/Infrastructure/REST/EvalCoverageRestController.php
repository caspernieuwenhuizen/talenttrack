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
                // #3802 — declared, so the route index publishes them. They
                // were accepted and silently ignored before, which is worse
                // than refusing: a caller asking for one team got every team
                // and had no way to tell.
                'args'                => [
                    'team_id' => [
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'description'       => 'Narrow the matrix to one team. Omit for every team.',
                    ],
                    'season_id' => [
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'description'       => 'Reserved for the seasons entity; the configured windows are the current season today.',
                    ],
                ],
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
                // #3610 — a write takes the write cap. On the read cap, any
                // read-only grant of analytics could move the windows every
                // coach's coverage is measured against.
                'permission_callback' => self::permCan( 'tt_edit_analytics' ),
            ],
        ] );
    }

    public static function matrix( WP_REST_Request $request ): \WP_REST_Response {
        // #3802 — this used to accept `$request` and never read it. The
        // service went further and `unset()` its only parameter, so a
        // `team_id` filter was dropped twice over.
        $team_id   = absint( (int) $request->get_param( 'team_id' ) );
        $season_id = absint( (int) $request->get_param( 'season_id' ) );

        $service  = new EvalCoverageService();
        $coverage = $service->coverage( $season_id, $team_id );

        $compliance = [];
        foreach ( $coverage['windows'] as $window ) {
            $compliance[] = [
                'window' => $window,
                'teams'  => $service->attendanceCompliance( $window ),
            ];
        }

        return RestResponse::success( [
            // #3802 — read this before reading a zero. With no window
            // configured the gap counts below are structurally zero, not
            // measured, and a caller that treats them as "all covered" is
            // reporting a clean bill of health nobody earned.
            'configured'             => $coverage['configured'],
            'windows'                => $coverage['windows'],
            'teams'                  => $coverage['teams'],
            'coach_gaps'             => $coverage['coach_gaps'],
            'total_players'          => $coverage['total_players'],
            'total_gaps'             => $coverage['total_gaps'],
            'attendance_compliance'  => $compliance,
            'evaluators'             => $service->evaluators(),
            'team_id'                => $team_id ?: null,
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
