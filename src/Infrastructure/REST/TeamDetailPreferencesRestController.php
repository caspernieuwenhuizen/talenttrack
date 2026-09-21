<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Teams\TeamDetailSections;

/**
 * TeamDetailPreferencesRestController — /me/preferences/team-detail
 *
 * #1613. Read/write surface for the per-user team-detail section
 * visibility preference. The canonical key is the current user id; user
 * meta is the WordPress-side backing store. Exposing this through REST
 * (CLAUDE.md §4) means a non-WordPress front end gets the same layout
 * the rendered plugin does — the section logic lives in
 * TeamDetailSections, this controller is the thin transport.
 *
 * Routes:
 *   GET /me/preferences/team-detail — effective visibility map + labels
 *   PUT /me/preferences/team-detail — persist the per-user override
 *
 * Both gate on `is_user_logged_in()` only — a preference is the user's
 * own, scoped to their id; there is no cross-user surface here. The
 * customize *control* in the view is additionally cap-gated on
 * coach-of-team (AuthorizationService::canManageTeam); a non-coach who
 * crafted a PUT by hand would only ever rewrite their own meta, which
 * is harmless.
 */
class TeamDetailPreferencesRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $can = static function (): bool {
            return is_user_logged_in() && get_current_user_id() > 0;
        };
        register_rest_route( self::NS, '/me/preferences/team-detail', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_preference' ],
                'permission_callback' => $can,
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'put_preference' ],
                'permission_callback' => $can,
                'args'                => self::putArgs(),
            ],
        ] );
    }

    /**
     * #3819 — the body `PUT /me/preferences/team-detail` takes.
     *
     * Not declared `required`: core checks required params before the
     * permission callback, so a required key would answer a logged-out PUT
     * with a 400 rather than the 401 it is owed. `put_preference()`
     * answers `bad_payload` itself when `sections` is absent.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function putArgs(): array {
        return [
            'sections' => [
                'type'        => 'object',
                'description' => 'Which sections to show, keyed by section. A section the object leaves out is hidden, so send the whole map.',
            ],
        ];
    }

    public static function get_preference( \WP_REST_Request $r ) {
        $user_id = get_current_user_id();
        return RestResponse::success( [
            'sections' => TeamDetailSections::forUser( $user_id ),
            'labels'   => TeamDetailSections::labels(),
        ] );
    }

    public static function put_preference( \WP_REST_Request $r ) {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::putArgs() );
        if ( $refused !== null ) return $refused;

        $user_id = get_current_user_id();
        $payload = $r->get_param( 'sections' );
        if ( ! is_array( $payload ) ) {
            return RestResponse::error(
                'bad_payload',
                __( 'Expected an object under `sections`.', 'talenttrack' ),
                400
            );
        }
        $map = [];
        foreach ( TeamDetailSections::SECTIONS as $key ) {
            // Absent key → off. Truthy values (true, 1, "1", "on") → on.
            $map[ $key ] = array_key_exists( $key, $payload )
                ? filter_var( $payload[ $key ], FILTER_VALIDATE_BOOLEAN )
                : false;
        }
        TeamDetailSections::setUserOverride( $user_id, $map );
        return RestResponse::success( [
            'sections' => TeamDetailSections::forUser( $user_id ),
        ] );
    }
}
