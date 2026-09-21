<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Methodology\MethodologyScope;

/**
 * AbstractMethodologyRestController (#2225) — the shared REST base for
 * methodology-authoring CRUD. Sibling entity controllers (#2226–#2230)
 * extend this to get, for free:
 *
 *   - permission_callback → current_user_can('tt_edit_methodology'), the
 *     capability model (§4 — no role-string compare, no __return_true).
 *   - club scoping via CurrentClub::id() (SaaS tenancy, §4).
 *   - the standard success / error / not-found envelope (RestResponse).
 *   - the conventional route table: a collection route (GET list, POST
 *     create) and an item route (GET one, PUT update, DELETE remove).
 *
 * A concrete controller implements `register()` plus the five callbacks,
 * and declares the body its writes take in `writeArgs()`:
 *
 *     final class FooRestController extends AbstractMethodologyRestController {
 *         public static function register(): void {
 *             register_rest_route( self::NS, '/methodology/foos', [ … ] );
 *         }
 *         protected static function writeArgs(): array { … }
 *         public static function list_items( \WP_REST_Request $r ) { … }
 *     }
 *     FooRestController::init();
 *
 * #3819 — each controller spells its own routes out rather than inheriting
 * a `register()` that builds a path out of a variable. A path assembled
 * from `static::restBase()` could not be read statically, so the args gate
 * saw one unreadable route standing for eleven and could not tell a newly
 * added undeclared one from the rest. What stays shared is everything that
 * carries behaviour: the gate, the envelope, the body contract and the two
 * write wrappers below.
 *
 * The methodology REST surface lives under `/methodology/<entity>` so the
 * nine entities share one namespace prefix and don't collide with the
 * top-level resource names.
 */
abstract class AbstractMethodologyRestController {

    protected const NS  = 'talenttrack/v1';
    public    const CAP = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'rest_api_init', [ static::class, 'register' ] );
    }

    /**
     * Wire the controller's routes, with literal paths and literal method
     * lists so the args gate can read every one of them.
     */
    abstract public static function register(): void;

    /**
     * The single gate for the whole methodology-authoring surface: the
     * `tt_edit_methodology` capability. Portable across auth backends —
     * a SaaS front end that maps the same cap gets the same answer.
     *
     * Runs before every handler, so it doubles as the pinning point for
     * the optional `methodology_id` query param (#2319): when present it
     * scopes the request to that set; otherwise repositories fall back
     * to the install's active methodology.
     */
    public static function can_edit( ?\WP_REST_Request $request = null ): bool {
        if ( $request instanceof \WP_REST_Request ) {
            $mid = (int) $request->get_param( 'methodology_id' );
            if ( $mid > 0 ) {
                MethodologyScope::set( $mid );
            }
        }
        return current_user_can( static::CAP );
    }

    // ── body contract (#3819) ────────────────────────────────────────

    /**
     * The body fields the collection `POST` and the item `PUT` take. A
     * concrete controller overrides this with the fields its payload
     * builder actually reads; a key outside the list is refused with
     * `400 unknown_field` rather than dropped in silence.
     *
     * Nothing is ever declared `required` here. Core checks required
     * params in `has_valid_params()`, which runs before the permission
     * callback, so a required field answers an unauthenticated write with
     * a 400 naming the fields instead of the 401 it is owed. Each
     * `create_item()` names what it needs itself, behind the capability
     * gate.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function writeArgs(): array {
        return [];
    }

    /**
     * `writeArgs()` plus the set-scoping key every route on this surface
     * accepts. `methodology_id` normally arrives as a query parameter, but
     * `can_edit()` reads it with `get_param()`, so a copy in the body is
     * honoured and must not be refused.
     *
     * @return array<string, array<string, mixed>>
     */
    final protected static function createArgs(): array {
        return static::writeArgs() + [ 'methodology_id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'Which methodology set the write applies to. Usually a query parameter; a copy in the body is accepted.',
        ] ];
    }

    /**
     * `createArgs()` plus the `id` the item route carries in its URL. A
     * client that echoes the id back in the body is not refused for it.
     *
     * @return array<string, array<string, mixed>>
     */
    final protected static function itemWriteArgs(): array {
        return [ 'id' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The record, from the URL. A copy in the body is accepted and ignored.',
        ] ] + static::createArgs();
    }

    /**
     * The collection `POST`, with the body's shape checked before its
     * values. Wrapping it here rather than in each `create_item()` keeps
     * the ten methodology entities on one contract.
     */
    public static function handle_create( \WP_REST_Request $r ): \WP_REST_Response {
        $refused = BaseController::checkBody( $r, static::createArgs() );
        if ( $refused !== null ) return $refused;
        return static::create_item( $r );
    }

    /** The item `PUT`, with the body's shape checked before its values. */
    public static function handle_update( \WP_REST_Request $r ): \WP_REST_Response {
        $refused = BaseController::checkBody( $r, static::itemWriteArgs() );
        if ( $refused !== null ) return $refused;
        return static::update_item( $r );
    }

    /** The active club id — every methodology row is club-scoped. */
    protected static function clubId(): int {
        return CurrentClub::id();
    }

    // ── envelope shortcuts (thin wrappers over RestResponse) ─────────

    /** @param mixed $data */
    protected static function ok( $data = null, int $status = 200 ): \WP_REST_Response {
        return RestResponse::success( $data, $status );
    }

    /** @param array<string,mixed> $details */
    protected static function fail( string $code, string $message, int $status = 400, array $details = [] ): \WP_REST_Response {
        return RestResponse::error( $code, $message, $status, $details );
    }

    protected static function notFound( string $code = 'not_found', string $message = '' ): \WP_REST_Response {
        return RestResponse::notFound( $code, $message );
    }

    // ── CRUD callbacks — concrete controllers implement these ────────

    abstract public static function list_items( \WP_REST_Request $r ): \WP_REST_Response;
    abstract public static function get_item( \WP_REST_Request $r ): \WP_REST_Response;
    abstract public static function create_item( \WP_REST_Request $r ): \WP_REST_Response;
    abstract public static function update_item( \WP_REST_Request $r ): \WP_REST_Response;
    abstract public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response;
}
