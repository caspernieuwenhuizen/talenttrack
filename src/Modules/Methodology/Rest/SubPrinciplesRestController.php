<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\SubPrinciplesRepository;

/**
 * SubPrinciplesRestController (#2369) —
 * /wp-json/talenttrack/v1/methodology/sub-principles
 *
 * Full CRUD for sub-principles (per-line coaching points under a phase),
 * sharing the SubPrinciplesRepository + MultilingualField domain layer the
 * manage tab and read view use, so a future SaaS front end gets identical
 * answers (§4).
 *
 * Routes (inherited shape):
 *   GET    /methodology/sub-principles        list (club-scoped, active set)
 *   POST   /methodology/sub-principles        create a club-authored sub-principle
 *   GET    /methodology/sub-principles/{id}   one, NL + EN decoded
 *   PUT    /methodology/sub-principles/{id}   edit a club-authored sub-principle
 *   DELETE /methodology/sub-principles/{id}   delete a club-authored sub-principle
 *
 * Shipped rows are read-only reference content: create always writes a
 * club-authored row (is_shipped = 0), and update / delete refuse shipped
 * rows.
 */
final class SubPrinciplesRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/sub-principles';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $filters = [];
        if ( $r->has_param( 'phase_side' ) )   $filters['phase_side']   = sanitize_key( (string) $r['phase_side'] );
        if ( $r->has_param( 'phase_number' ) ) $filters['phase_number'] = absint( $r['phase_number'] );
        if ( $r->has_param( 'line_key' ) )     $filters['line_key']     = sanitize_key( (string) $r['line_key'] );
        if ( $r->has_param( 'principle_id' ) ) $filters['principle_id'] = absint( $r['principle_id'] );

        $rows = ( new SubPrinciplesRepository() )->listFiltered( $filters );
        return self::ok( [ 'sub_principles' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new SubPrinciplesRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'sub_principle_not_found', __( 'Sub-principle not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $side = sanitize_key( (string) ( $r['phase_side'] ?? '' ) );
        if ( ! MethodologyEnums::isValidSide( $side ) ) {
            return self::fail( 'invalid_phase_side', __( 'Invalid phase side.', 'talenttrack' ), 400 );
        }
        $line = sanitize_key( (string) ( $r['line_key'] ?? '' ) );
        if ( ! MethodologyEnums::isValidLine( $line ) ) {
            return self::fail( 'invalid_line', __( 'Invalid line.', 'talenttrack' ), 400 );
        }

        $payload = self::writePayload( $r );
        $payload['phase_side']   = $side;
        $payload['phase_number'] = absint( $r['phase_number'] ?? 0 );
        $payload['line_key']     = $line;
        $payload['is_shipped']   = 0;
        if ( $r->has_param( 'principle_id' ) ) {
            $payload['principle_id'] = absint( $r['principle_id'] );
        }

        $id = ( new SubPrinciplesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_sub_principle.create.failed', [ 'phase_side' => $side, 'line_key' => $line ] );
            return self::fail( 'db_error', __( 'Could not save the sub-principle.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new SubPrinciplesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'sub_principle_not_found', __( 'Sub-principle not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'sub_principle_shipped', __( 'Shipped sub-principles are read-only.', 'talenttrack' ), 409 );
        }

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'phase_side' ) ) {
            $side = sanitize_key( (string) $r['phase_side'] );
            if ( ! MethodologyEnums::isValidSide( $side ) ) {
                return self::fail( 'invalid_phase_side', __( 'Invalid phase side.', 'talenttrack' ), 400 );
            }
            $data['phase_side'] = $side;
        }
        if ( $r->has_param( 'phase_number' ) ) {
            $data['phase_number'] = absint( $r['phase_number'] );
        }
        if ( $r->has_param( 'line_key' ) ) {
            $line = sanitize_key( (string) $r['line_key'] );
            if ( ! MethodologyEnums::isValidLine( $line ) ) {
                return self::fail( 'invalid_line', __( 'Invalid line.', 'talenttrack' ), 400 );
            }
            $data['line_key'] = $line;
        }
        if ( $r->has_param( 'principle_id' ) ) {
            $data['principle_id'] = absint( $r['principle_id'] ) ?: null;
        }
        if ( $r->has_param( 'sort_order' ) ) {
            $data['sort_order'] = (int) $r['sort_order'];
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new SubPrinciplesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'sub_principle_not_found', __( 'Sub-principle not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'sub_principle_shipped', __( 'Shipped sub-principles cannot be deleted.', 'talenttrack' ), 409 );
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
        foreach ( [ 'title' => 'title_json', 'description' => 'description_json' ] as $field => $col ) {
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
     * Shape a sub-principle row for the API. The localized strings resolve
     * to the current locale; when $full, the raw NL + EN values are
     * included under `*_i18n` so an authoring client can edit both.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $s, bool $full = false ): array {
        $out = [
            'id'             => (int) $s->id,
            'methodology_id' => $s->methodology_id !== null ? (int) $s->methodology_id : null,
            'principle_id'   => $s->principle_id !== null ? (int) $s->principle_id : null,
            'phase_side'     => (string) ( $s->phase_side ?? '' ),
            'phase_number'   => $s->phase_number !== null ? (int) $s->phase_number : null,
            'line_key'       => (string) ( $s->line_key ?? '' ),
            'sort_order'     => (int) ( $s->sort_order ?? 0 ),
            'is_shipped'     => ! empty( $s->is_shipped ),
            'title'          => MultilingualField::string( $s->title_json ),
            'description'    => MultilingualField::string( $s->description_json ),
        ];
        if ( $full ) {
            $out['title_i18n']       = MultilingualField::decode( $s->title_json ) ?: (object) [];
            $out['description_i18n'] = MultilingualField::decode( $s->description_json ) ?: (object) [];
        }
        return $out;
    }
}
