<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MinutesGridPreferencesRestController — /me/preferences/minutes-grid
 *
 * #3094. Which statistic columns the minutes grid shows for this user.
 *
 * The sub-columns triple the grid's width, so a coach who only records
 * minutes has to be able to collapse it back — and that choice has to
 * survive a reload, or the first thing they do every session is switch two
 * chips off again.
 *
 * **Per user, not per club.** It is how one person likes to look at a
 * screen, not a decision about the academy, so it lives in user meta rather
 * than in `tt_config`. Exposed through REST for the same reason the
 * team-detail preference is (CLAUDE.md §4): a non-WordPress front end
 * rendering this grid should read the same preference the plugin does
 * rather than invent a second store for it.
 *
 * Gated on being logged in only. The route writes the caller's own meta and
 * nothing else, so there is no cross-user surface to protect; someone who
 * crafted a PUT by hand would rearrange their own columns.
 */
final class MinutesGridPreferencesRestController {

    const NS = 'talenttrack/v1';

    /** Also read by `FrontendMinutesGridView`. */
    public const META_KEY = 'tt_minutes_grid_stats';

    /** @var list<string> */
    public const STATS = [ 'goals', 'assists' ];

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $can = static function (): bool {
            return is_user_logged_in() && get_current_user_id() > 0;
        };

        register_rest_route( self::NS, '/me/preferences/minutes-grid', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_preference' ],
                'permission_callback' => $can,
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_preference' ],
                'permission_callback' => $can,
            ],
        ] );
    }

    /**
     * Both columns for a user who has never touched the switches — a coach
     * should see the feature exists before deciding to hide it.
     *
     * @return list<string>
     */
    public static function forUser( int $user_id ): array {
        $stored = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $stored ) ) return self::STATS;

        return array_values( array_intersect( self::STATS, array_map( 'strval', $stored ) ) );
    }

    public static function get_preference( \WP_REST_Request $r ): \WP_REST_Response {
        return RestResponse::success( [ 'stats' => self::forUser( get_current_user_id() ) ] );
    }

    public static function put_preference( \WP_REST_Request $r ): \WP_REST_Response {
        $payload = $r->get_param( 'stats' );
        if ( ! is_array( $payload ) ) {
            return RestResponse::error(
                'bad_payload',
                __( 'Expected a list under `stats`.', 'talenttrack' ),
                400
            );
        }

        // Intersected against the known set rather than stored as posted:
        // an unknown column name would be meaningless on read and is not
        // worth keeping for the day someone wonders where it came from.
        $stats = array_values( array_intersect( self::STATS, array_map( 'strval', $payload ) ) );
        update_user_meta( get_current_user_id(), self::META_KEY, $stats );

        return RestResponse::success( [ 'stats' => $stats ] );
    }
}
