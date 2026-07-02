<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FootballActionsRepository;

/**
 * FootballActionsRestController (#2230) —
 * /wp-json/talenttrack/v1/methodology/football-actions
 *
 * Full CRUD for football actions (voetbalhandelingen), sharing the
 * FootballActionsRepository + MultilingualField domain layer the manage
 * tab uses, so a future SaaS front end gets identical answers (§4).
 *
 * Routes (inherited shape):
 *   GET    /methodology/football-actions        list (club-scoped, non-archived)
 *   POST   /methodology/football-actions        create a club-authored action
 *   GET    /methodology/football-actions/{id}   one action, NL + EN decoded
 *   PUT    /methodology/football-actions/{id}   edit a club-authored action
 *   DELETE /methodology/football-actions/{id}   delete a club-authored action
 *
 * Shipped rows are read-only reference content: create always writes a
 * club-authored row (is_shipped = 0), and update / delete refuse shipped
 * rows. Deleting an action still referenced by a goal
 * (`tt_goals.linked_action_id`) is refused with 409 so the link is never
 * orphaned.
 */
final class FootballActionsRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/football-actions';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $rows = ( new FootballActionsRepository() )->listAll();
        return self::ok( [ 'football_actions' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new FootballActionsRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'football_action_not_found', __( 'Football action not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $slug = sanitize_key( (string) ( $r['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return self::fail( 'missing_slug', __( 'A football action needs a slug.', 'talenttrack' ), 400 );
        }
        $category = sanitize_key( (string) ( $r['category_key'] ?? '' ) );
        if ( ! self::isValidCategory( $category ) ) {
            return self::fail( 'invalid_category', __( 'Invalid category.', 'talenttrack' ), 400 );
        }

        $payload = self::writePayload( $r );
        $payload['slug']         = $slug;
        $payload['category_key'] = $category;
        $payload['is_shipped']   = 0;

        $id = ( new FootballActionsRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_football_action.create.failed', [ 'slug' => $slug ] );
            return self::fail( 'db_error', __( 'Could not save the football action.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new FootballActionsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'football_action_not_found', __( 'Football action not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'football_action_shipped', __( 'Shipped football actions are read-only.', 'talenttrack' ), 409 );
        }

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'slug' ) ) {
            $slug = sanitize_key( (string) $r['slug'] );
            if ( $slug === '' ) {
                return self::fail( 'missing_slug', __( 'A football action needs a slug.', 'talenttrack' ), 400 );
            }
            $data['slug'] = $slug;
        }
        if ( $r->has_param( 'category_key' ) ) {
            $category = sanitize_key( (string) $r['category_key'] );
            if ( ! self::isValidCategory( $category ) ) {
                return self::fail( 'invalid_category', __( 'Invalid category.', 'talenttrack' ), 400 );
            }
            $data['category_key'] = $category;
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new FootballActionsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'football_action_not_found', __( 'Football action not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'football_action_shipped', __( 'Shipped football actions cannot be deleted.', 'talenttrack' ), 409 );
        }
        $linked = $repo->countLinkedGoals( $id );
        if ( $linked > 0 ) {
            return self::fail(
                'football_action_linked',
                __( 'This football action is linked to one or more goals and cannot be deleted. Unlink them first.', 'talenttrack' ),
                409,
                [ 'linked_goals' => $linked ]
            );
        }
        $ok = $repo->delete( $id );
        return self::ok( [ 'deleted' => $ok, 'id' => $id ] );
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

    private static function isValidCategory( string $key ): bool {
        return array_key_exists( $key, FootballActionsRepository::categories() );
    }

    /**
     * Shape an action row for the API. The localized strings resolve to
     * the current locale; when $full, the raw NL + EN values are included
     * under `*_i18n` so an authoring client can edit both languages.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $a, bool $full = false ): array {
        $out = [
            'id'           => (int) $a->id,
            'slug'         => (string) $a->slug,
            'category_key' => (string) $a->category_key,
            'sort_order'   => (int) ( $a->sort_order ?? 0 ),
            'is_shipped'   => ! empty( $a->is_shipped ),
            'name'         => MultilingualField::string( $a->name_json ),
            'description'  => MultilingualField::string( $a->description_json ),
        ];
        if ( $full ) {
            $out['name_i18n']        = MultilingualField::decode( $a->name_json ) ?: (object) [];
            $out['description_i18n'] = MultilingualField::decode( $a->description_json ) ?: (object) [];
        }
        return $out;
    }
}
