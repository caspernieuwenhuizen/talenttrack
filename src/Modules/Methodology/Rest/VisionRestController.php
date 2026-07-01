<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\MethodologyVisionRepository;

/**
 * VisionRestController (#2226) —
 * /wp-json/talenttrack/v1/methodology/vision
 *
 * The club vision is a SINGLETON, so this controller exposes read + update
 * only — no create, no delete. It overrides the base route table to wire:
 *
 *   GET /methodology/vision        the active club vision (club-scoped)
 *   GET /methodology/vision/{id}   one vision, NL + EN decoded
 *   PUT /methodology/vision/{id}   edit the club-authored vision
 *
 * Shares MethodologyVisionRepository + MultilingualField + MethodologyEnums
 * with the manage tab, so a SaaS front end gets identical answers (§4).
 * Shipped rows are read-only reference content: update refuses them.
 */
final class VisionRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/vision';
    }

    /**
     * Singleton route table: a collection GET (the active vision) and an
     * item GET + PUT. No POST/DELETE — the vision is one row per club.
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

    /** The active club vision (club-authored, else shipped fallback). */
    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $row = ( new MethodologyVisionRepository() )->activeForClub();
        return self::ok( [ 'vision' => $row ? self::shape( $row, true ) : null ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new MethodologyVisionRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'vision_not_found', __( 'Vision not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new MethodologyVisionRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'vision_not_found', __( 'Vision not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'vision_shipped', __( 'The shipped sample vision is read-only.', 'talenttrack' ), 409 );
        }

        if ( $r->has_param( 'style_of_play_key' ) ) {
            $style = sanitize_key( (string) $r['style_of_play_key'] );
            if ( $style !== '' && ! MethodologyEnums::isValidStyle( $style ) ) {
                return self::fail( 'invalid_style', __( 'Invalid style of play.', 'talenttrack' ), 400 );
            }
        }

        $ok = $repo->update( $id, self::writePayload( $r ) );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    // ── unsupported (singleton) ──────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        return self::fail( 'method_not_allowed', __( 'The vision is a single record and cannot be created over REST.', 'talenttrack' ), 405 );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        return self::fail( 'method_not_allowed', __( 'The vision is a single record and cannot be deleted over REST.', 'talenttrack' ), 405 );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Build the partial write payload from the request — only the fields
     * actually supplied are touched.
     *
     * @return array<string,mixed>
     */
    private static function writePayload( \WP_REST_Request $r ): array {
        $out = [];

        if ( $r->has_param( 'formation_id' ) ) {
            $out['formation_id'] = absint( $r['formation_id'] ) ?: null;
        }
        if ( $r->has_param( 'style_of_play_key' ) ) {
            $style = sanitize_key( (string) $r['style_of_play_key'] );
            $out['style_of_play_key'] = $style !== '' ? $style : null;
        }

        foreach ( [ 'way_of_playing', 'notes' ] as $field ) {
            if ( ! $r->has_param( $field ) ) continue;
            $val = $r[ $field ] ?? [];
            $out[ $field . '_json' ] = MultilingualField::encode( [
                'nl' => sanitize_textarea_field( is_array( $val ) ? (string) ( $val['nl'] ?? '' ) : '' ),
                'en' => sanitize_textarea_field( is_array( $val ) ? (string) ( $val['en'] ?? '' ) : '' ),
            ] );
        }

        if ( $r->has_param( 'important_traits' ) ) {
            $val = $r['important_traits'] ?? [];
            $out['important_traits_json'] = MultilingualField::encode( [
                'nl' => self::sanitizeList( is_array( $val ) ? ( $val['nl'] ?? [] ) : [] ),
                'en' => self::sanitizeList( is_array( $val ) ? ( $val['en'] ?? [] ) : [] ),
            ] );
        }

        return $out;
    }

    /**
     * @param mixed $list
     * @return string[]
     */
    private static function sanitizeList( $list ): array {
        if ( ! is_array( $list ) ) return [];
        $out = [];
        foreach ( $list as $item ) {
            $clean = trim( sanitize_text_field( is_string( $item ) ? $item : '' ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }

    /**
     * Shape a vision row for the API. When $full, the raw NL + EN values
     * are included under `*_i18n` so an authoring client can edit both
     * languages.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $row, bool $full = false ): array {
        $out = [
            'id'                => (int) $row->id,
            'formation_id'      => $row->formation_id !== null ? (int) $row->formation_id : null,
            'style_of_play_key' => (string) ( $row->style_of_play_key ?? '' ),
            'is_shipped'        => ! empty( $row->is_shipped ),
            'way_of_playing'    => MultilingualField::string( $row->way_of_playing_json ),
            'notes'             => MultilingualField::string( $row->notes_json ),
            'important_traits'  => MultilingualField::stringList( $row->important_traits_json ),
        ];
        if ( $full ) {
            $out['way_of_playing_i18n']   = MultilingualField::decode( $row->way_of_playing_json ) ?: (object) [];
            $out['notes_i18n']            = MultilingualField::decode( $row->notes_json ) ?: (object) [];
            $out['important_traits_i18n'] = MultilingualField::decode( $row->important_traits_json ) ?: (object) [];
        }
        return $out;
    }
}
