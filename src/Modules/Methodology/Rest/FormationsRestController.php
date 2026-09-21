<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FormationsRepository;

/**
 * FormationsRestController (#2227) —
 * /wp-json/talenttrack/v1/methodology/formations
 *
 * Full CRUD for formations and their nested position cards, sharing the
 * FormationsRepository + MultilingualField domain layer the manage tab
 * uses, so a future SaaS front end gets identical answers (§4).
 *
 * Formation routes (inherited shape):
 *   GET    /methodology/formations              list (club-scoped)
 *   POST   /methodology/formations              create a club formation
 *   GET    /methodology/formations/{id}         one formation + positions
 *   PUT    /methodology/formations/{id}         edit a club formation
 *   DELETE /methodology/formations/{id}         delete a club formation
 *
 * Nested position routes (added below):
 *   GET    /methodology/formations/{id}/positions        list positions
 *   POST   /methodology/formations/{id}/positions        create a position
 *   PUT    /methodology/formations/{id}/positions/{pid}  edit a position
 *   DELETE /methodology/formations/{id}/positions/{pid}  delete a position
 *
 * Shipped rows are read-only reference content: create always writes a
 * club-authored row (is_shipped = 0), and update / delete refuse shipped
 * rows with a 409 — the clone-to-edit path handles those elsewhere.
 */
final class FormationsRestController extends AbstractMethodologyRestController {

    /**
     * The shared collection + item routes, plus the nested position
     * sub-collection and item routes.
     */
    public static function register(): void {
        $gate = [ self::class, 'can_edit' ];

        register_rest_route( self::NS, '/methodology/formations', [
            [ 'methods' => 'GET',  'callback' => [ self::class, 'list_items' ],    'permission_callback' => $gate ],
            [ 'methods' => 'POST', 'callback' => [ self::class, 'handle_create' ], 'permission_callback' => $gate, 'args' => self::createArgs() ],
        ] );

        register_rest_route( self::NS, '/methodology/formations/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ self::class, 'get_item' ],      'permission_callback' => $gate ],
            [ 'methods' => 'PUT',    'callback' => [ self::class, 'handle_update' ], 'permission_callback' => $gate, 'args' => self::itemWriteArgs() ],
            [ 'methods' => 'DELETE', 'callback' => [ self::class, 'delete_item' ],   'permission_callback' => $gate ],
        ] );

        register_rest_route( self::NS, '/methodology/formations/(?P<id>\d+)/positions', [
            [
                'methods'             => 'GET',
                'callback'            => [ static::class, 'list_positions' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ static::class, 'create_position' ],
                'permission_callback' => [ static::class, 'can_edit' ],
                'args'                => self::positionArgs(),
            ],
        ] );

        register_rest_route( self::NS, '/methodology/formations/(?P<id>\d+)/positions/(?P<pid>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ static::class, 'get_position' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ static::class, 'update_position' ],
                'permission_callback' => [ static::class, 'can_edit' ],
                'args'                => self::positionItemArgs(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ static::class, 'delete_position' ],
                'permission_callback' => [ static::class, 'can_edit' ],
            ],
        ] );
    }

    // ── formations · read ────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $rows = ( new FormationsRepository() )->listAll();
        return self::ok( [ 'formations' => array_map( [ self::class, 'shapeFormation' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new FormationsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'formation_not_found', __( 'Formation not found.', 'talenttrack' ) );
        }
        $out = self::shapeFormation( $row, true );
        $out['positions'] = array_map(
            [ self::class, 'shapePosition' ],
            $repo->positionsFor( $id )
        );
        return self::ok( $out );
    }

    // ── formations · write ───────────────────────────────────────────

    /**
     * #3819 — the body the formation writes take.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function writeArgs(): array {
        return [
            'slug'         => [ 'type' => 'string', 'description' => 'The key that identifies the formation.' ],
            'name'         => [ 'type' => 'object', 'description' => 'The formation name per locale, as {nl, en}.' ],
            'description'  => [ 'type' => 'object', 'description' => 'What the formation is for, per locale, as {nl, en}.' ],
            'diagram_data' => [ 'type' => [ 'object', 'array', 'string' ], 'description' => 'The pitch diagram, as an object or its JSON.' ],
        ];
    }

    /**
     * #3819 — the body the nested position writes take.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function positionArgs(): array {
        return [
            'id'              => [ 'type' => [ 'integer', 'string' ], 'description' => 'The formation, from the URL. A copy in the body is accepted and ignored.' ],
            'jersey_number'   => [ 'type' => [ 'integer', 'string' ], 'description' => 'The shirt number the position card sits on, 1-11.' ],
            'short_name'      => [ 'type' => 'object', 'description' => 'The short position name per locale, as {nl, en}.' ],
            'long_name'       => [ 'type' => 'object', 'description' => 'The full position name per locale, as {nl, en}.' ],
            'attacking_tasks' => [ 'type' => 'object', 'description' => 'The attacking tasks per locale, each a list of lines.' ],
            'defending_tasks' => [ 'type' => 'object', 'description' => 'The defending tasks per locale, each a list of lines.' ],
        ];
    }

    /**
     * #3819 — `PUT` on one position, on top of `positionArgs()`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function positionItemArgs(): array {
        return [ 'pid' => [
            'type'        => [ 'integer', 'string' ],
            'description' => 'The position, from the URL. A copy in the body is accepted and ignored.',
        ] ] + self::positionArgs();
    }

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $slug = sanitize_text_field( (string) ( $r['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return self::fail( 'missing_slug', __( 'A formation needs a slug.', 'talenttrack' ), 400 );
        }

        $payload = self::formationWritePayload( $r );
        $payload['slug']       = $slug;
        $payload['is_shipped'] = 0;

        $id = ( new FormationsRepository() )->createFormation( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_formation.create.failed', [ 'slug' => $slug ] );
            return self::fail( 'db_error', __( 'Could not save the formation.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new FormationsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'formation_not_found', __( 'Formation not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'formation_shipped', __( 'Shipped formations are read-only. Clone it to edit.', 'talenttrack' ), 409 );
        }

        $data = self::formationWritePayload( $r, true );
        if ( $r->has_param( 'slug' ) ) {
            $slug = sanitize_text_field( (string) $r['slug'] );
            if ( $slug === '' ) {
                return self::fail( 'missing_slug', __( 'A formation needs a slug.', 'talenttrack' ), 400 );
            }
            $data['slug'] = $slug;
        }

        $ok = $repo->updateFormation( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new FormationsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'formation_not_found', __( 'Formation not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'formation_shipped', __( 'Shipped formations cannot be deleted.', 'talenttrack' ), 409 );
        }
        $ok = $repo->deleteFormation( $id );
        return self::ok( [ 'deleted' => $ok, 'id' => $id ] );
    }

    // ── positions · read ─────────────────────────────────────────────

    public static function list_positions( \WP_REST_Request $r ): \WP_REST_Response {
        $fid  = absint( $r['id'] );
        $repo = new FormationsRepository();
        if ( ! $repo->find( $fid ) ) {
            return self::notFound( 'formation_not_found', __( 'Formation not found.', 'talenttrack' ) );
        }
        return self::ok( [ 'positions' => array_map(
            [ self::class, 'shapePosition' ],
            $repo->positionsFor( $fid )
        ) ] );
    }

    public static function get_position( \WP_REST_Request $r ): \WP_REST_Response {
        $fid  = absint( $r['id'] );
        $pid  = absint( $r['pid'] );
        $repo = new FormationsRepository();
        $row  = $repo->findPosition( $pid );
        if ( ! $row || (int) $row->formation_id !== $fid ) {
            return self::notFound( 'position_not_found', __( 'Position not found.', 'talenttrack' ) );
        }
        return self::ok( self::shapePosition( $row, true ) );
    }

    // ── positions · write ────────────────────────────────────────────

    public static function create_position( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::positionArgs() );
        if ( $refused !== null ) return $refused;

        $fid  = absint( $r['id'] );
        $repo = new FormationsRepository();
        $parent = $repo->find( $fid );
        if ( ! $parent ) {
            return self::notFound( 'formation_not_found', __( 'Formation not found.', 'talenttrack' ) );
        }
        if ( ! empty( $parent->is_shipped ) ) {
            return self::fail( 'formation_shipped', __( 'Shipped formations are read-only. Clone it to edit.', 'talenttrack' ), 409 );
        }

        $payload = self::positionWritePayload( $r );
        $payload['formation_id']  = $fid;
        $payload['jersey_number'] = self::jersey( $r['jersey_number'] ?? 1 );
        $payload['is_shipped']    = 0;

        $id = $repo->createPosition( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_formation_position.create.failed', [ 'formation_id' => $fid ] );
            return self::fail( 'db_error', __( 'Could not save the position.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_position( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = \TT\Infrastructure\REST\BaseController::checkBody( $r, self::positionItemArgs() );
        if ( $refused !== null ) return $refused;

        $fid  = absint( $r['id'] );
        $pid  = absint( $r['pid'] );
        $repo = new FormationsRepository();
        $row  = $repo->findPosition( $pid );
        if ( ! $row || (int) $row->formation_id !== $fid ) {
            return self::notFound( 'position_not_found', __( 'Position not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'position_shipped', __( 'Shipped positions are read-only. Clone it to edit.', 'talenttrack' ), 409 );
        }

        $data = self::positionWritePayload( $r, true );
        if ( $r->has_param( 'jersey_number' ) ) {
            $data['jersey_number'] = self::jersey( $r['jersey_number'] );
        }

        $ok = $repo->updatePosition( $pid, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $pid ] );
    }

    public static function delete_position( \WP_REST_Request $r ): \WP_REST_Response {
        $fid  = absint( $r['id'] );
        $pid  = absint( $r['pid'] );
        $repo = new FormationsRepository();
        $row  = $repo->findPosition( $pid );
        if ( ! $row || (int) $row->formation_id !== $fid ) {
            return self::notFound( 'position_not_found', __( 'Position not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'position_shipped', __( 'Shipped positions cannot be deleted.', 'talenttrack' ), 409 );
        }
        $ok = $repo->deletePosition( $pid );
        return self::ok( [ 'deleted' => $ok, 'id' => $pid ] );
    }

    // ── payload builders ─────────────────────────────────────────────

    /**
     * Build the formation write payload. On update ($partial) only the
     * fields present in the request are touched.
     *
     * @return array<string,mixed>
     */
    private static function formationWritePayload( \WP_REST_Request $r, bool $partial = false ): array {
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

        if ( ! $partial || $r->has_param( 'diagram_data' ) ) {
            $out['diagram_data_json'] = self::sanitizeDiagram( $r['diagram_data'] ?? null );
        }

        return $out;
    }

    /**
     * Build the position write payload. On update ($partial) only the
     * multilingual fields present in the request are touched.
     *
     * @return array<string,mixed>
     */
    private static function positionWritePayload( \WP_REST_Request $r, bool $partial = false ): array {
        $out = [];

        foreach ( [ 'short_name' => 'short_name_json', 'long_name' => 'long_name_json' ] as $field => $col ) {
            if ( $partial && ! $r->has_param( $field ) ) continue;
            $val = $r[ $field ] ?? [];
            $out[ $col ] = MultilingualField::encode( [
                'nl' => self::sanitizeLocale( is_array( $val ) ? ( $val['nl'] ?? '' ) : '', false ),
                'en' => self::sanitizeLocale( is_array( $val ) ? ( $val['en'] ?? '' ) : '', false ),
            ] );
        }

        foreach ( [ 'attacking_tasks' => 'attacking_tasks_json', 'defending_tasks' => 'defending_tasks_json' ] as $field => $col ) {
            if ( $partial && ! $r->has_param( $field ) ) continue;
            $val = $r[ $field ] ?? [];
            $out[ $col ] = MultilingualField::encode( [
                'nl' => self::sanitizeList( is_array( $val ) ? ( $val['nl'] ?? [] ) : [] ),
                'en' => self::sanitizeList( is_array( $val ) ? ( $val['en'] ?? [] ) : [] ),
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
     * Sanitize an array-of-strings task list, dropping blanks.
     *
     * @param mixed $value
     * @return string[]
     */
    private static function sanitizeList( $value ): array {
        if ( ! is_array( $value ) ) return [];
        $out = [];
        foreach ( $value as $entry ) {
            $clean = trim( sanitize_text_field( is_string( $entry ) ? $entry : '' ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }

    /** Clamp a jersey number into the 1–11 range. @param mixed $raw */
    private static function jersey( $raw ): int {
        return max( 1, min( 11, (int) $raw ) );
    }

    /**
     * Compact the optional diagram-data structure to storage JSON. Accepts
     * an already-decoded array (JSON request body) or a raw string.
     *
     * @param mixed $value
     */
    private static function sanitizeDiagram( $value ): string {
        if ( is_array( $value ) ) {
            return (string) wp_json_encode( $value );
        }
        $raw = trim( is_string( $value ) ? $value : '' );
        if ( $raw === '' ) return '';
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? (string) wp_json_encode( $decoded ) : sanitize_textarea_field( $raw );
    }

    // ── shaping ──────────────────────────────────────────────────────

    /**
     * Shape a formation row for the API. Localized strings resolve to the
     * current locale; when $full, the raw NL + EN values are included under
     * `*_i18n` so an authoring client can edit both languages.
     *
     * @return array<string,mixed>
     */
    private static function shapeFormation( object $f, bool $full = false ): array {
        $out = [
            'id'          => (int) $f->id,
            'slug'        => (string) $f->slug,
            'is_shipped'  => ! empty( $f->is_shipped ),
            'name'        => MultilingualField::string( $f->name_json ),
            'description' => MultilingualField::string( $f->description_json ),
        ];
        if ( $full ) {
            $out['name_i18n']        = MultilingualField::decode( $f->name_json ) ?: (object) [];
            $out['description_i18n'] = MultilingualField::decode( $f->description_json ) ?: (object) [];
            $out['diagram_data']     = MultilingualField::decode( $f->diagram_data_json ) ?: (object) [];
        }
        return $out;
    }

    /**
     * Shape a position row for the API. Localized names + task lists
     * resolve to the current locale; when $full, the raw NL + EN values
     * are included under `*_i18n`.
     *
     * @return array<string,mixed>
     */
    private static function shapePosition( object $p, bool $full = false ): array {
        $out = [
            'id'              => (int) $p->id,
            'formation_id'    => (int) $p->formation_id,
            'jersey_number'   => (int) $p->jersey_number,
            'is_shipped'      => ! empty( $p->is_shipped ),
            'short_name'      => MultilingualField::string( $p->short_name_json ),
            'long_name'       => MultilingualField::string( $p->long_name_json ),
            'attacking_tasks' => MultilingualField::stringList( $p->attacking_tasks_json ),
            'defending_tasks' => MultilingualField::stringList( $p->defending_tasks_json ),
        ];
        if ( $full ) {
            $out['short_name_i18n']      = MultilingualField::decode( $p->short_name_json ) ?: (object) [];
            $out['long_name_i18n']       = MultilingualField::decode( $p->long_name_json ) ?: (object) [];
            $out['attacking_tasks_i18n'] = MultilingualField::decode( $p->attacking_tasks_json ) ?: (object) [];
            $out['defending_tasks_i18n'] = MultilingualField::decode( $p->defending_tasks_json ) ?: (object) [];
        }
        return $out;
    }
}
