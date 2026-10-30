<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Activities\Services\CoachWeek;

/**
 * CoachWeekRestController — GET /me/week (#4379).
 *
 * The caller's week, Monday to Sunday: every activity on the teams they
 * coach and what each still needs. The rows come from `CoachWeek`, the
 * same service the tile landing's "This week" panel renders, so a
 * non-WordPress client draws the same panel.
 *
 * A caller outside the academy's chosen personas, or one who coaches no
 * team, gets 200 with `visible: false` and no rows rather than a 403, so a
 * client hides the panel the way the landing does. The route takes no id:
 * the caller is the scope.
 */
class CoachWeekRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/me/week', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_week' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
                'args'                => [],
            ],
        ] );
    }

    /** Staff who may read activities: `tt_view_activities` or `tt_edit_activities`. */
    public static function can_read(): bool {
        return ActivityAccess::isStaffReader( get_current_user_id() );
    }

    public static function get_week( \WP_REST_Request $r ): \WP_REST_Response {
        return RestResponse::success( CoachWeek::forUser( get_current_user_id() ) );
    }
}
