<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;

/**
 * FrameworkPrimerRestController (#2226) —
 * /wp-json/talenttrack/v1/methodology/framework-primer
 *
 * The framework primer is a SINGLETON, so this controller exposes read +
 * update only — no create, no delete. It overrides the base route table to
 * wire:
 *
 *   GET /methodology/framework-primer        the active club primer
 *   GET /methodology/framework-primer/{id}   one primer, NL + EN decoded
 *   PUT /methodology/framework-primer/{id}   edit the club-authored primer
 *
 * Shares FrameworkPrimerRepository + MultilingualField with the manage tab,
 * so a SaaS front end gets identical answers (§4). The shipped primer is
 * read-only reference content: update refuses it.
 */
final class FrameworkPrimerRestController extends AbstractMethodologyRestController {

    /**
     * The primer's multilingual fields: field slug → is_textarea. Single
     * source of truth shared by the write payload + response shape.
     *
     * @return array<string,bool>
     */
    private static function fields(): array {
        return [
            'title'                    => false,
            'tagline'                  => false,
            'intro'                    => true,
            'voetbalmodel_intro'       => true,
            'voetbalhandelingen_intro' => true,
            'phases_intro'             => true,
            'learning_goals_intro'     => true,
            'influence_factors_intro'  => true,
            'reflection'               => true,
            'future'                   => true,
        ];
    }

    protected static function restBase(): string {
        return 'methodology/framework-primer';
    }

    /**
     * Singleton route table: a collection GET (the active primer) and an
     * item GET + PUT. No POST/DELETE — the primer is one row per club.
     */
    public static function register(): void {
        $base = static::restBase();

        register_rest_route( static::NS, '/' . $base, [
            [
                'methods'             => 'GET',
                'callback'            => [ static::class, 'list_items' ],
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
        ] );
    }

    // ── read ────────────────────────────────────────────────────────

    /** The active club framework primer (club-authored, else shipped). */
    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $row = ( new FrameworkPrimerRepository() )->activeForClub();
        return self::ok( [ 'framework_primer' => $row ? self::shape( $row, true ) : null ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new FrameworkPrimerRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'framework_primer_not_found', __( 'Framework primer not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new FrameworkPrimerRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'framework_primer_not_found', __( 'Framework primer not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'framework_primer_shipped', __( 'The shipped framework primer is read-only. Clone it to edit.', 'talenttrack' ), 409 );
        }

        $ok = $repo->update( $id, self::writePayload( $r ) );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    // ── unsupported (singleton) ──────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        return self::fail( 'method_not_allowed', __( 'The framework primer is a single record and cannot be created over REST.', 'talenttrack' ), 405 );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        return self::fail( 'method_not_allowed', __( 'The framework primer is a single record and cannot be deleted over REST.', 'talenttrack' ), 405 );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Build the partial write payload — only the fields actually supplied
     * are touched.
     *
     * @return array<string,mixed>
     */
    private static function writePayload( \WP_REST_Request $r ): array {
        $out = [];
        foreach ( self::fields() as $field => $textarea ) {
            if ( ! $r->has_param( $field ) ) continue;
            $val = $r[ $field ] ?? [];
            $out[ $field . '_json' ] = MultilingualField::encode( [
                'nl' => self::sanitizeLocale( is_array( $val ) ? ( $val['nl'] ?? '' ) : '', $textarea ),
                'en' => self::sanitizeLocale( is_array( $val ) ? ( $val['en'] ?? '' ) : '', $textarea ),
            ] );
        }
        return $out;
    }

    /** @param mixed $value */
    private static function sanitizeLocale( $value, bool $textarea ): string {
        $value = is_string( $value ) ? $value : '';
        return $textarea ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
    }

    /**
     * Shape a primer row for the API. When $full, the raw NL + EN values
     * per field are included under `<field>_i18n`.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $row, bool $full = false ): array {
        $out = [
            'id'         => (int) $row->id,
            'is_shipped' => ! empty( $row->is_shipped ),
        ];
        foreach ( array_keys( self::fields() ) as $field ) {
            $col          = $field . '_json';
            $out[ $field ] = MultilingualField::string( $row->{$col} ?? null );
            if ( $full ) {
                $out[ $field . '_i18n' ] = MultilingualField::decode( $row->{$col} ?? null ) ?: (object) [];
            }
        }
        return $out;
    }
}
