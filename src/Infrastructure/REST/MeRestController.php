<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\ContactSync;
use TT\Infrastructure\Identity\PhoneMeta;
use TT\Infrastructure\Players\AccountPlayerLinks;

/**
 * MeRestController — GET /me (#3568), PATCH /me (#3684).
 *
 * The logged-in account's own links to player records: the player it is,
 * and the children it is a guardian of. It is the first call a
 * non-WordPress client makes, because every per-player route needs an id
 * and nothing else tells a player or a parent theirs.
 *
 * Login is the only gate. The route returns the caller's own links and
 * nothing about anybody else, so there is no capability to ask for, and a
 * player account holds none of the staff `tt_view_*` caps anyway.
 *
 * An account linked to nothing gets 200 with `reason: "no_linked_player"`
 * rather than a 403, so the client can say "your account isn't linked to a
 * player yet" instead of "not allowed".
 *
 * The collection routes (`GET players`, `GET evaluations`, …) stay staff
 * surfaces on purpose: each `my_*` matrix entity is its own grant, and
 * `me` mirrors that split rather than widening a collection.
 *
 * WHY THE PHONE LIVES HERE (#3684)
 *
 * A parent had no way to give the academy a number: My settings had no
 * field and no route wrote one, so an admin typed every guardian's mobile
 * in by hand. The account phone is the store — `PhoneMeta` — and
 * `ContactSync` copies it onto the linked `tt_people` row when there is
 * one. Nothing is written to `tt_players.guardian_phone`, and nothing
 * waits on an admin's approval.
 *
 * The write takes **no user id, in any form**. It only ever touches
 * `get_current_user_id()`, which is what makes "logged in" a sufficient
 * gate: there is no parameter that could point somewhere else.
 */
class MeRestController {

    const NS = 'talenttrack/v1';

    /**
     * Body contract for `PATCH /me` (#3689 — every write declares its args).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function updateArgs(): array {
        return [
            'phone' => [
                'required'    => false,
                'description' => 'The caller\'s own phone number, international format. null or an empty string clears it.',
            ],
        ];
    }

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/me', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_me' ],
                'permission_callback' => [ __CLASS__, 'is_signed_in' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_me' ],
                'permission_callback' => [ __CLASS__, 'is_signed_in' ],
                'args'                => self::updateArgs(),
            ],
        ] );
    }

    public static function is_signed_in(): bool {
        return is_user_logged_in() && get_current_user_id() > 0;
    }

    public static function get_me( \WP_REST_Request $r ): \WP_REST_Response {
        return RestResponse::success( self::payload( get_current_user_id() ) );
    }

    /**
     * Update the caller's own contact details. Today that is the phone
     * number and nothing else.
     *
     * Blank clears. Input that does not normalize to a usable number is a
     * 400 and the stored number is left alone — never cleared. That matters
     * for a Dutch mobile typed as `06 12345678`: `PhoneMeta::isValid()`
     * rejects a leading zero, so treating "invalid" as "clear" would wipe a
     * working number because somebody left off their country code.
     */
    public static function update_me( \WP_REST_Request $r ): \WP_REST_Response {
        $bad = BaseController::checkBody( $r, self::updateArgs() );
        if ( $bad !== null ) return $bad;

        $user_id = get_current_user_id();

        if ( ! self::sentPhone( $r ) ) {
            return RestResponse::error(
                'nothing_to_update',
                __( 'Send a phone number to update.', 'talenttrack' ),
                400
            );
        }

        $raw   = $r->get_param( 'phone' );
        $given = $raw === null ? '' : trim( (string) $raw );

        if ( $given === '' ) {
            PhoneMeta::clear( $user_id );
        } else {
            $normalized = PhoneMeta::normalize( $given );
            if ( $normalized === '' ) {
                return RestResponse::error(
                    'invalid_phone',
                    __( 'Enter your phone number with its country code, for example +31 6 12345678.', 'talenttrack' ),
                    400
                );
            }
            PhoneMeta::set( $user_id, $normalized );
        }

        // Writing user meta does not fire `profile_update`, so the sync that
        // keeps a linked `tt_people` row aligned has to be asked for here.
        ContactSync::pushToPerson( $user_id );

        return RestResponse::success( self::payload( $user_id ) );
    }

    /**
     * Whether `phone` was actually sent. `null` is a real value here — it
     * means "clear it" — so `get_param()` coming back null cannot tell the
     * two apart on its own, and `has_param()` uses `isset()`.
     */
    private static function sentPhone( \WP_REST_Request $r ): bool {
        if ( $r->has_param( 'phone' ) ) return true;
        // Core returns null for a body that is not JSON; the stubs say
        // array, so a falsy test rather than is_array() (as checkBody does).
        $body = $r->get_json_params();
        if ( ! $body ) $body = $r->get_body_params();
        if ( ! $body ) return false;
        return array_key_exists( 'phone', $body );
    }

    /**
     * The `me` payload: the caller's player links plus their own phone.
     *
     * `phone` is only ever the caller's own — the route has no way to ask
     * about anybody else — so decrypting it into the response here is safe
     * in a way it would not be on a staff collection.
     *
     * @return array<string,mixed>
     */
    private static function payload( int $user_id ): array {
        $links          = AccountPlayerLinks::forUser( $user_id );
        $links['phone'] = PhoneMeta::get( $user_id );
        return $links;
    }
}
