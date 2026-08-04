<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\TacticalScenesRepository;

/**
 * TacticalScenesRestController (#2323, epic #2316) —
 * /wp-json/talenttrack/v1/methodology/tactical-scenes
 *
 * Full CRUD for per-phase tactical scenes, sharing the
 * TacticalScenesRepository + MultilingualField domain layer the read view
 * uses, so a future SaaS front end gets identical answers (§4). This makes
 * scenes authorable via API for a future drag/draw editor — that authoring
 * UI is out of scope here and tracked as a follow-up.
 *
 * Routes (inherited shape):
 *   GET    /methodology/tactical-scenes        list (club + methodology-scoped)
 *   POST   /methodology/tactical-scenes        create a club-authored scene
 *   GET    /methodology/tactical-scenes/{id}   one scene, NL + EN decoded + scene payload
 *   PUT    /methodology/tactical-scenes/{id}   edit a club-authored scene
 *   DELETE /methodology/tactical-scenes/{id}   delete a club-authored scene
 *
 * `title` / `description` are multilingual in the `{nl,en}` shape.
 * `scene` is a free-form JSON object (the keyframe animation payload)
 * validated only as well-formed — the renderer and editor own its schema.
 * Shipped rows are read-only: create always writes a club-authored row
 * (is_shipped = 0), and update / delete refuse shipped rows.
 */
final class TacticalScenesRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/tactical-scenes';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $filters = [];
        if ( $r->has_param( 'phase_side' ) ) {
            $filters['phase_side'] = sanitize_key( (string) $r['phase_side'] );
        }
        if ( $r->has_param( 'phase_number' ) ) {
            $filters['phase_number'] = absint( $r['phase_number'] );
        }
        $rows = ( new TacticalScenesRepository() )->listFiltered( $filters );
        return self::ok( [ 'tactical_scenes' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new TacticalScenesRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'tactical_scene_not_found', __( 'Tactical scene not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $scene = self::readScene( $r );
        if ( $scene === null ) {
            return self::fail( 'invalid_scene', __( 'The scene payload must be a well-formed JSON object.', 'talenttrack' ), 400 );
        }

        $payload = self::writePayload( $r );
        $payload['scene_json']   = $scene;
        $payload['is_shipped']   = 0;
        $payload['phase_side']   = $r->has_param( 'phase_side' ) ? sanitize_key( (string) $r['phase_side'] ) : null;
        $payload['phase_number'] = $r->has_param( 'phase_number' ) ? absint( $r['phase_number'] ) : null;
        if ( $r->has_param( 'formation_id' ) ) {
            $payload['formation_id'] = absint( $r['formation_id'] ) ?: null;
        }
        if ( $r->has_param( 'sort_order' ) ) {
            $payload['sort_order'] = (int) $r['sort_order'];
        }

        $id = ( new TacticalScenesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_tactical_scene.create.failed', [] );
            return self::fail( 'db_error', __( 'Could not save the tactical scene.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new TacticalScenesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'tactical_scene_not_found', __( 'Tactical scene not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'tactical_scene_shipped', __( 'Shipped tactical scenes are read-only.', 'talenttrack' ), 409 );
        }

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'scene' ) ) {
            $scene = self::readScene( $r );
            if ( $scene === null ) {
                return self::fail( 'invalid_scene', __( 'The scene payload must be a well-formed JSON object.', 'talenttrack' ), 400 );
            }
            $data['scene_json'] = $scene;
        }
        if ( $r->has_param( 'phase_side' ) ) {
            $data['phase_side'] = sanitize_key( (string) $r['phase_side'] );
        }
        if ( $r->has_param( 'phase_number' ) ) {
            $data['phase_number'] = absint( $r['phase_number'] );
        }
        if ( $r->has_param( 'formation_id' ) ) {
            $data['formation_id'] = absint( $r['formation_id'] ) ?: null;
        }
        if ( $r->has_param( 'sort_order' ) ) {
            $data['sort_order'] = (int) $r['sort_order'];
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new TacticalScenesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'tactical_scene_not_found', __( 'Tactical scene not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'tactical_scene_shipped', __( 'Shipped tactical scenes cannot be deleted.', 'talenttrack' ), 409 );
        }
        $ok = $repo->delete( $id );
        return self::ok( [ 'deleted' => $ok, 'id' => $id ] );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Read + validate the `scene` param as a well-formed JSON object.
     * Accepts either a decoded array (WP already parsed the JSON body) or
     * a raw JSON string. Returns the array on success, or null when it is
     * absent / malformed. The scene schema itself is intentionally
     * free-form (the renderer + a future editor own it).
     *
     * @return array<string,mixed>|null
     */
    private static function readScene( \WP_REST_Request $r ): ?array {
        $raw = $r['scene'] ?? null;
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : null;
        }
        return null;
    }

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
     * Shape a scene row for the API. The localized strings resolve to the
     * current locale; `scene` carries the raw animation payload. When
     * $full, the raw NL + EN title/description are included under `*_i18n`
     * so an authoring client can edit both languages.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $s, bool $full = false ): array {
        $scene = isset( $s->scene_decoded ) && is_array( $s->scene_decoded ) ? $s->scene_decoded : [];
        $out = [
            'id'             => (int) $s->id,
            'uuid'           => (string) ( $s->uuid ?? '' ),
            'methodology_id' => $s->methodology_id !== null ? (int) $s->methodology_id : null,
            'phase_side'     => (string) ( $s->phase_side ?? '' ),
            'phase_number'   => $s->phase_number !== null ? (int) $s->phase_number : null,
            'formation_id'   => $s->formation_id !== null ? (int) $s->formation_id : null,
            'sort_order'     => (int) ( $s->sort_order ?? 0 ),
            'is_shipped'     => ! empty( $s->is_shipped ),
            'title'          => MultilingualField::string( $s->title_json ),
            'description'    => MultilingualField::string( $s->description_json ),
            'scene'          => (object) $scene,
        ];
        if ( $full ) {
            $out['title_i18n']       = MultilingualField::decode( $s->title_json ) ?: (object) [];
            $out['description_i18n'] = MultilingualField::decode( $s->description_json ) ?: (object) [];
        }
        return $out;
    }
}
