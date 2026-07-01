<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Tenancy\CurrentClub;

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
 * A concrete controller sets `NS` (inherited) + a `restBase()` slug and
 * implements the five callbacks. Registration is uniform:
 *
 *     final class FooRestController extends AbstractMethodologyRestController {
 *         protected static function restBase(): string { return 'methodology/foos'; }
 *         public static function list_items( \WP_REST_Request $r ) { ... }
 *         // ...
 *     }
 *     FooRestController::init();
 *
 * The methodology REST surface lives under `/methodology/<entity>` so the
 * nine entities share one namespace prefix and don't collide with the
 * top-level resource names.
 */
abstract class AbstractMethodologyRestController {

    protected const NS  = 'talenttrack/v1';
    public    const CAP = 'tt_edit_methodology';

    /** The route slug under the namespace, e.g. `methodology/principles`. */
    abstract protected static function restBase(): string;

    public static function init(): void {
        add_action( 'rest_api_init', [ static::class, 'register' ] );
    }

    /**
     * Register the collection + item routes. Concrete controllers may
     * override to add extra sub-routes, calling parent::register() first.
     */
    public static function register(): void {
        $base = static::restBase();

        register_rest_route( static::NS, '/' . $base, [
            [
                'methods'             => 'GET',
                'callback'            => [ static::class, 'list_items' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ static::class, 'create_item' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
        ] );

        register_rest_route( static::NS, '/' . $base . '/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ static::class, 'get_item' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ static::class, 'update_item' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ static::class, 'delete_item' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
        ] );
    }

    /**
     * The single gate for the whole methodology-authoring surface: the
     * `tt_edit_methodology` capability. Portable across auth backends —
     * a SaaS front end that maps the same cap gets the same answer.
     */
    public static function can_edit(): bool {
        return current_user_can( static::CAP );
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
