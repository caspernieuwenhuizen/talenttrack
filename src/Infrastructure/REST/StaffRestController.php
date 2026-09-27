<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\StaffDirectory;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * StaffRestController (#4043) — GET /wp-json/talenttrack/v1/staff?search=
 *
 * Turns a staff member's name into the ids the trial panel and the parent
 * link take. Before it, a caller assigning a panel had to read a numeric
 * account id out of wp-admin.
 *
 * Searching staff by name is a directory read over the whole club, so it
 * is open only to someone who assigns staff to something: whoever manages
 * trial cases, or parent links. A panel member who only takes part cannot
 * browse it. No e-mail address is handed back.
 */
class StaffRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/staff', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'search' ],
                'permission_callback' => [ __CLASS__, 'can_search' ],
                'args'                => [
                    'search' => [
                        'type'        => 'string',
                        'required'    => true,
                        'description' => 'At least two characters of the staff member\'s name.',
                    ],
                ],
            ],
        ] );
    }

    public static function can_search(): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return false;
        return AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_trials' )
            || AuthorizationService::userCanOrMatrix( $uid, 'tt_manage_parent_accounts' );
    }

    public static function search( \WP_REST_Request $r ): \WP_REST_Response {
        $search = trim( sanitize_text_field( (string) ( $r['search'] ?? '' ) ) );
        if ( mb_strlen( $search ) < StaffDirectory::MIN_SEARCH ) {
            return RestResponse::error(
                'bad_search',
                __( 'Type at least two characters of the name.', 'talenttrack' ),
                400,
                [ 'field' => 'search' ]
            );
        }

        return RestResponse::success( [
            'staff' => ( new StaffDirectory() )->search( $search, StaffDirectory::MAX_RESULTS ),
        ] );
    }
}
