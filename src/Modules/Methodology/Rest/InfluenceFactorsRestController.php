<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\InfluenceFactorsRepository;

/**
 * InfluenceFactorsRestController (#2229) —
 * /wp-json/talenttrack/v1/methodology/influence-factors
 *
 * Full CRUD for the framework primer's factoren van invloed on
 * AbstractMethodologyRestController, sharing the
 * InfluenceFactorsRepository + MultilingualField domain layer the manage
 * tab uses, so a future SaaS front end gets identical answers (§4).
 *
 * Influence factors are children of the active framework primer; each
 * carries an optional array of sub-factor cards. Shipped rows are
 * read-only reference content — update / delete refuse them with a 409.
 *
 * Routes (inherited shape):
 *   GET    /methodology/influence-factors        list (active primer)
 *   POST   /methodology/influence-factors        create a club-authored factor
 *   GET    /methodology/influence-factors/{id}   one factor, NL + EN decoded
 *   PUT    /methodology/influence-factors/{id}   edit a club-authored factor
 *   DELETE /methodology/influence-factors/{id}   delete a club-authored factor
 */
final class InfluenceFactorsRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/influence-factors';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            return self::ok( [ 'influence_factors' => [] ] );
        }
        $rows = ( new InfluenceFactorsRepository() )->listForPrimer( (int) $primer->id );
        return self::ok( [ 'influence_factors' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new InfluenceFactorsRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'influence_factor_not_found', __( 'Influence factor not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $slug = sanitize_key( (string) ( $r['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return self::fail( 'missing_slug', __( 'An influence factor needs a slug.', 'talenttrack' ), 400 );
        }

        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            return self::fail( 'no_primer', __( 'Author the framework primer before adding influence factors.', 'talenttrack' ), 409 );
        }

        $payload = self::writePayload( $r );
        $payload['slug']       = $slug;
        $payload['primer_id']  = (int) $primer->id;
        $payload['is_shipped'] = 0;

        $id = ( new InfluenceFactorsRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_influence_factor.create.failed', [ 'slug' => $slug ] );
            return self::fail( 'db_error', __( 'Could not save the influence factor.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new InfluenceFactorsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'influence_factor_not_found', __( 'Influence factor not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'influence_factor_shipped', __( 'Shipped influence factors are read-only. Clone the primer to edit.', 'talenttrack' ), 409 );
        }

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'slug' ) ) {
            $slug = sanitize_key( (string) $r['slug'] );
            if ( $slug === '' ) {
                return self::fail( 'missing_slug', __( 'An influence factor needs a slug.', 'talenttrack' ), 400 );
            }
            $data['slug'] = $slug;
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new InfluenceFactorsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'influence_factor_not_found', __( 'Influence factor not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'influence_factor_shipped', __( 'Shipped influence factors cannot be deleted.', 'talenttrack' ), 409 );
        }
        $ok = $repo->delete( $id );
        return self::ok( [ 'deleted' => $ok, 'id' => $id ] );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private static function writePayload( \WP_REST_Request $r, bool $partial = false ): array {
        $out = [];

        if ( ! $partial || $r->has_param( 'sort_order' ) ) {
            $out['sort_order'] = (int) ( $r['sort_order'] ?? 0 );
        }
        if ( ! $partial || $r->has_param( 'title' ) ) {
            $title = is_array( $r['title'] ?? null ) ? $r['title'] : [];
            $out['title_json'] = MultilingualField::encode( [
                'nl' => sanitize_text_field( is_string( $title['nl'] ?? null ) ? $title['nl'] : '' ),
                'en' => sanitize_text_field( is_string( $title['en'] ?? null ) ? $title['en'] : '' ),
            ] );
        }
        if ( ! $partial || $r->has_param( 'description' ) ) {
            $desc = is_array( $r['description'] ?? null ) ? $r['description'] : [];
            $out['description_json'] = MultilingualField::encode( [
                'nl' => sanitize_textarea_field( is_string( $desc['nl'] ?? null ) ? $desc['nl'] : '' ),
                'en' => sanitize_textarea_field( is_string( $desc['en'] ?? null ) ? $desc['en'] : '' ),
            ] );
        }
        if ( ! $partial || $r->has_param( 'sub_factors' ) ) {
            $sub = $r['sub_factors'] ?? null;
            $out['sub_factors_json'] = is_array( $sub )
                ? (string) wp_json_encode( self::sanitizeSubFactors( $sub ) )
                : '';
        }

        return $out;
    }

    /**
     * Sanitize a sub-factor list. Mirrors
     * InfluenceFactorsManageTab::sanitizeSubFactors.
     *
     * @param array<int,mixed> $list
     * @return array<int,array{slug:string,title:array{nl:string,en:string},description:array{nl:string,en:string}}>
     */
    private static function sanitizeSubFactors( array $list ): array {
        $out = [];
        foreach ( $list as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $slug = isset( $entry['slug'] ) ? sanitize_key( (string) $entry['slug'] ) : '';
            if ( $slug === '' ) continue;
            $out[] = [
                'slug'        => $slug,
                'title'       => [
                    'nl' => isset( $entry['title']['nl'] ) ? sanitize_text_field( (string) $entry['title']['nl'] ) : '',
                    'en' => isset( $entry['title']['en'] ) ? sanitize_text_field( (string) $entry['title']['en'] ) : '',
                ],
                'description' => [
                    'nl' => isset( $entry['description']['nl'] ) ? sanitize_textarea_field( (string) $entry['description']['nl'] ) : '',
                    'en' => isset( $entry['description']['en'] ) ? sanitize_textarea_field( (string) $entry['description']['en'] ) : '',
                ],
            ];
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function shape( object $f, bool $full = false ): array {
        $out = [
            'id'          => (int) $f->id,
            'primer_id'   => (int) $f->primer_id,
            'slug'        => (string) $f->slug,
            'is_shipped'  => ! empty( $f->is_shipped ),
            'title'       => MultilingualField::string( $f->title_json ),
            'description' => MultilingualField::string( $f->description_json ),
        ];
        if ( $full ) {
            $out['title_i18n']       = MultilingualField::decode( $f->title_json ) ?: (object) [];
            $out['description_i18n'] = MultilingualField::decode( $f->description_json ) ?: (object) [];
            $out['sub_factors']      = MultilingualField::decode( $f->sub_factors_json ) ?: [];
        }
        return $out;
    }
}
