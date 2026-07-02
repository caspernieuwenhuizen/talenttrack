<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\SetPiecesRepository;

/**
 * SetPiecesRestController (#2228) —
 * /wp-json/talenttrack/v1/methodology/set-pieces
 *
 * Full CRUD for set pieces on AbstractMethodologyRestController, sharing
 * the SetPiecesRepository + MultilingualField + MethodologyEnums domain
 * layer the manage view uses, so a future SaaS front end gets identical
 * answers (§4).
 *
 * Routes (inherited shape):
 *   GET    /methodology/set-pieces           list (club-scoped, non-archived)
 *   POST   /methodology/set-pieces           create a club-authored set piece
 *   GET    /methodology/set-pieces/{id}      one set piece, NL + EN decoded
 *   PUT    /methodology/set-pieces/{id}      edit a club-authored set piece
 *   DELETE /methodology/set-pieces/{id}      delete a club-authored set piece
 *
 * Shipped rows are read-only reference content: create always writes a
 * club-authored row (is_shipped = 0), and update / delete refuse shipped
 * rows (409) — the clone-to-edit path handles those elsewhere.
 */
final class SetPiecesRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/set-pieces';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $filters = [];
        if ( $r->has_param( 'kind' ) ) {
            $filters['kind'] = sanitize_key( (string) $r['kind'] );
        }
        if ( $r->has_param( 'side' ) ) {
            $filters['side'] = sanitize_key( (string) $r['side'] );
        }
        if ( $r->has_param( 'source' ) ) {
            $filters['source'] = sanitize_key( (string) $r['source'] );
        }
        $rows = ( new SetPiecesRepository() )->listFiltered( $filters );
        return self::ok( [ 'set_pieces' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new SetPiecesRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'set_piece_not_found', __( 'Set piece not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $error = self::validateTaxonomy( $r );
        if ( $error !== null ) return $error;

        $slug = sanitize_text_field( (string) ( $r['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return self::fail( 'missing_slug', __( 'A set piece needs a slug.', 'talenttrack' ), 400 );
        }

        $payload = self::writePayload( $r );
        $payload['slug']       = $slug;
        $payload['is_shipped'] = 0;

        $id = ( new SetPiecesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_set_piece.create.failed', [ 'slug' => $slug ] );
            return self::fail( 'db_error', __( 'Could not save the set piece.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new SetPiecesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'set_piece_not_found', __( 'Set piece not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'set_piece_shipped', __( 'Shipped set pieces are read-only. Clone it to edit.', 'talenttrack' ), 409 );
        }

        $error = self::validateTaxonomy( $r, true );
        if ( $error !== null ) return $error;

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'slug' ) ) {
            $slug = sanitize_text_field( (string) $r['slug'] );
            if ( $slug === '' ) {
                return self::fail( 'missing_slug', __( 'A set piece needs a slug.', 'talenttrack' ), 400 );
            }
            $data['slug'] = $slug;
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new SetPiecesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'set_piece_not_found', __( 'Set piece not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'set_piece_shipped', __( 'Shipped set pieces cannot be deleted.', 'talenttrack' ), 409 );
        }
        $ok = $repo->delete( $id );
        return self::ok( [ 'deleted' => $ok, 'id' => $id ] );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Build the write payload from the request. On update ($partial) only
     * the fields present in the request are touched; on create every
     * multilingual field is written (blank → empty JSON).
     *
     * @return array<string,mixed>
     */
    private static function writePayload( \WP_REST_Request $r, bool $partial = false ): array {
        $out = [];

        if ( ! $partial || $r->has_param( 'kind_key' ) ) {
            $out['kind_key'] = sanitize_key( (string) ( $r['kind_key'] ?? '' ) );
        }
        if ( ! $partial || $r->has_param( 'side' ) ) {
            $out['side'] = sanitize_key( (string) ( $r['side'] ?? '' ) );
        }

        if ( ! $partial || $r->has_param( 'title' ) ) {
            $title = is_array( $r['title'] ?? null ) ? $r['title'] : [];
            $out['title_json'] = MultilingualField::encode( [
                'nl' => sanitize_text_field( is_string( $title['nl'] ?? null ) ? $title['nl'] : '' ),
                'en' => sanitize_text_field( is_string( $title['en'] ?? null ) ? $title['en'] : '' ),
            ] );
        }

        if ( ! $partial || $r->has_param( 'bullets' ) ) {
            $bullets = is_array( $r['bullets'] ?? null ) ? $r['bullets'] : [];
            $out['bullets_json'] = MultilingualField::encode( [
                'nl' => self::sanitizeBullets( $bullets['nl'] ?? null ),
                'en' => self::sanitizeBullets( $bullets['en'] ?? null ),
            ] );
        }

        if ( ! $partial || $r->has_param( 'diagram_overlay' ) ) {
            $overlay = $r['diagram_overlay'] ?? null;
            $out['diagram_overlay_json'] = is_array( $overlay )
                ? (string) wp_json_encode( $overlay )
                : '{}';
        }

        return $out;
    }

    /**
     * Sanitize a bullet list — accepts an array of strings, drops blanks.
     *
     * @param mixed $value
     * @return string[]
     */
    private static function sanitizeBullets( $value ): array {
        if ( ! is_array( $value ) ) return [];
        $out = [];
        foreach ( $value as $item ) {
            if ( ! is_string( $item ) ) continue;
            $clean = trim( sanitize_text_field( $item ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }

    /**
     * Validate the closed taxonomies. On update, only validates the keys
     * actually supplied. Returns an error response or null when valid.
     */
    private static function validateTaxonomy( \WP_REST_Request $r, bool $partial = false ): ?\WP_REST_Response {
        if ( ! $partial || $r->has_param( 'kind_key' ) ) {
            if ( ! MethodologyEnums::isValidKind( sanitize_key( (string) ( $r['kind_key'] ?? '' ) ) ) ) {
                return self::fail( 'invalid_kind', __( 'Invalid set-piece kind.', 'talenttrack' ), 400 );
            }
        }
        if ( ! $partial || $r->has_param( 'side' ) ) {
            if ( ! MethodologyEnums::isValidSide( sanitize_key( (string) ( $r['side'] ?? '' ) ) ) ) {
                return self::fail( 'invalid_side', __( 'Invalid side.', 'talenttrack' ), 400 );
            }
        }
        return null;
    }

    /**
     * Shape a set-piece row for the API. Localized strings resolve to the
     * current locale; when $full, the raw NL + EN values are included so
     * an authoring client can edit both languages.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $sp, bool $full = false ): array {
        $out = [
            'id'         => (int) $sp->id,
            'slug'       => (string) $sp->slug,
            'kind_key'   => (string) $sp->kind_key,
            'side'       => (string) $sp->side,
            'is_shipped' => ! empty( $sp->is_shipped ),
            'title'      => MultilingualField::string( $sp->title_json ),
            'bullets'    => MultilingualField::stringList( $sp->bullets_json ),
        ];
        if ( $full ) {
            $out['title_i18n']     = MultilingualField::decode( $sp->title_json ) ?: (object) [];
            $out['bullets_i18n']   = MultilingualField::decode( $sp->bullets_json ) ?: (object) [];
            $out['diagram_overlay'] = MultilingualField::decode( $sp->diagram_overlay_json ) ?: (object) [];
        }
        return $out;
    }
}
