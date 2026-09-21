<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\ActiveMethodologyResolver;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\MethodologiesRepository;

/**
 * MethodologySetsRestController (#2320, epic #2316) —
 * /wp-json/talenttrack/v1/methodology/sets
 *
 * CRUD for the named, selectable methodology sets (`tt_methodologies`),
 * sharing MethodologiesRepository + MultilingualField with the manage
 * tab so a future SaaS front end gets identical answers (§4).
 *
 * Routes (inherited shape + one extra):
 *   GET    /methodology/sets            list (club-scoped, non-archived)
 *   POST   /methodology/sets            create a club-authored set
 *   GET    /methodology/sets/{id}       one set, NL + EN decoded
 *   PUT    /methodology/sets/{id}       edit a club-authored set
 *   DELETE /methodology/sets/{id}       archive a club-authored set
 *   PUT    /methodology/sets/{id}/default   make this set the active default
 *
 * Shipped sets are read-only reference content: update / delete refuse a
 * shipped row. Archiving the last remaining active set is refused so the
 * install never ends up with zero methodologies.
 */
final class MethodologySetsRestController extends AbstractMethodologyRestController {

    /**
     * Register the standard CRUD routes plus the `make default` action.
     */
    public static function register(): void {
        $gate = [ self::class, 'can_edit' ];

        register_rest_route( self::NS, '/methodology/sets', [
            [ 'methods' => 'GET',  'callback' => [ self::class, 'list_items' ],    'permission_callback' => $gate ],
            [ 'methods' => 'POST', 'callback' => [ self::class, 'handle_create' ], 'permission_callback' => $gate, 'args' => self::createArgs() ],
        ] );

        register_rest_route( self::NS, '/methodology/sets/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ self::class, 'get_item' ],      'permission_callback' => $gate ],
            [ 'methods' => 'PUT',    'callback' => [ self::class, 'handle_update' ], 'permission_callback' => $gate, 'args' => self::itemWriteArgs() ],
            [ 'methods' => 'DELETE', 'callback' => [ self::class, 'delete_item' ],   'permission_callback' => $gate ],
        ] );

        register_rest_route( self::NS, '/methodology/sets/(?P<id>\d+)/default', [
            [
                'methods'             => 'PUT',
                'callback'            => [ static::class, 'set_default' ],
                'permission_callback' => [ static::class, 'can_edit' ],
                'args'                => self::setDefaultArgs(),
            ],
        ] );
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $rows       = ( new MethodologiesRepository() )->allForClub();
        $active_id  = ActiveMethodologyResolver::forInstall();
        return self::ok( [
            'sets' => array_map(
                static fn ( $row ) => self::shape( $row, false, $active_id ),
                $rows
            ),
            'active_id' => $active_id,
        ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new MethodologiesRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'methodology_set_not_found', __( 'Methodology set not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true, ActiveMethodologyResolver::forInstall() ) );
    }

    // ── write ───────────────────────────────────────────────────────

    /**
     * #3819 — the body the methodology-set writes take.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function writeArgs(): array {
        return [
            'slug'        => [ 'type' => 'string', 'description' => 'The key that identifies the set.' ],
            'name'        => [ 'type' => 'object', 'description' => 'The set name per locale, as {nl, en}.' ],
            'description' => [ 'type' => 'object', 'description' => 'What the set covers, per locale, as {nl, en}.' ],
        ];
    }

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $payload = self::writePayload( $r );
        if ( $r->has_param( 'slug' ) ) {
            $payload['slug'] = sanitize_title( (string) $r['slug'] );
        }

        $id = ( new MethodologiesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_set.create.failed', [] );
            return self::fail( 'db_error', __( 'Could not save the methodology set.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new MethodologiesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'methodology_set_not_found', __( 'Methodology set not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'methodology_set_shipped', __( 'Shipped methodology sets are read-only.', 'talenttrack' ), 409 );
        }

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'slug' ) ) {
            $data['slug'] = sanitize_title( (string) $r['slug'] );
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new MethodologiesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'methodology_set_not_found', __( 'Methodology set not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'methodology_set_shipped', __( 'Shipped methodology sets cannot be archived.', 'talenttrack' ), 409 );
        }
        if ( ! $repo->archive( $id ) ) {
            return self::fail(
                'methodology_set_last',
                __( 'This is the last remaining methodology set and cannot be archived.', 'talenttrack' ),
                409
            );
        }
        return self::ok( [ 'archived' => true, 'id' => $id ] );
    }

    /**
     * #3819 — `PUT /methodology/sets/{id}/default` takes no body of its
     * own: the set is the one in the URL.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function setDefaultArgs(): array {
        return [
            'id'             => [ 'type' => [ 'integer', 'string' ], 'description' => 'The set to make the active default, from the URL. A copy in the body is accepted and ignored.' ],
            'methodology_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'Which methodology set the request is scoped to. Usually a query parameter; a copy in the body is accepted.' ],
        ];
    }

    /** Make the set the install-wide active default (#2320). */
    public static function set_default( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::setDefaultArgs() );
        if ( $refused !== null ) return $refused;

        $id   = absint( $r['id'] );
        $repo = new MethodologiesRepository();
        if ( ! $repo->exists( $id ) ) {
            return self::notFound( 'methodology_set_not_found', __( 'Methodology set not found.', 'talenttrack' ) );
        }
        if ( ! $repo->setDefault( $id ) ) {
            return self::fail( 'db_error', __( 'Could not update the active methodology.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'active_id' => $id ] );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Build the multilingual write payload from the request. On update
     * ($partial) only the multilingual fields present in the request are
     * touched; on create every field is written (blank → empty JSON).
     *
     * @return array<string,mixed>
     */
    private static function writePayload( \WP_REST_Request $r, bool $partial = false ): array {
        $out = [];
        foreach ( [ 'name' => 'name_json', 'description' => 'description_json' ] as $field => $col ) {
            if ( $partial && ! $r->has_param( $field ) ) continue;
            $val  = $r[ $field ] ?? [];
            $long = $field === 'description';
            $out[ $col ] = MultilingualField::encode( [
                'nl' => self::sanitizeLocale( is_array( $val ) ? ( $val['nl'] ?? '' ) : '', $long ),
                'en' => self::sanitizeLocale( is_array( $val ) ? ( $val['en'] ?? '' ) : '', $long ),
            ] );
        }
        return $out;
    }

    /** @param mixed $value */
    private static function sanitizeLocale( $value, bool $long ): string {
        $value = is_string( $value ) ? $value : '';
        return $long ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
    }

    /**
     * Shape a set row for the API. The localized strings resolve to the
     * current locale; when $full, the raw NL + EN values are included
     * under `*_i18n` so an authoring client can edit both languages.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $s, bool $full, int $active_id ): array {
        $out = [
            'id'          => (int) $s->id,
            'slug'        => (string) $s->slug,
            'sort_order'  => (int) ( $s->sort_order ?? 0 ),
            'is_default'  => ! empty( $s->is_default ),
            'is_shipped'  => ! empty( $s->is_shipped ),
            'is_active'   => (int) $s->id === $active_id,
            'name'        => MultilingualField::string( $s->name_json ),
            'description' => MultilingualField::string( $s->description_json ),
        ];
        if ( $full ) {
            $out['name_i18n']        = MultilingualField::decode( $s->name_json ) ?: (object) [];
            $out['description_i18n'] = MultilingualField::decode( $s->description_json ) ?: (object) [];
        }
        return $out;
    }
}
