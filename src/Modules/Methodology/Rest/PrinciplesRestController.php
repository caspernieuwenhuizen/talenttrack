<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;

/**
 * PrinciplesRestController (#2225) —
 * /wp-json/talenttrack/v1/methodology/principles
 *
 * The reference concrete controller on AbstractMethodologyRestController.
 * Full CRUD for game principles, sharing the PrinciplesRepository +
 * MultilingualField + MethodologyEnums domain layer the manage view uses,
 * so a future SaaS front end gets identical answers (§4).
 *
 * Routes (inherited shape):
 *   GET    /methodology/principles           list (club-scoped, non-archived)
 *   POST   /methodology/principles           create a club-authored principle
 *   GET    /methodology/principles/{id}      one principle, NL + EN decoded
 *   PUT    /methodology/principles/{id}      edit a club-authored principle
 *   DELETE /methodology/principles/{id}      delete a club-authored principle
 *
 * Shipped rows are read-only reference content: create always writes a
 * club-authored row (is_shipped = 0), and update / delete refuse shipped
 * rows — the clone-to-edit path handles those elsewhere.
 */
final class PrinciplesRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/principles';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $filters = [];
        if ( $r->has_param( 'source' ) ) {
            $filters['source'] = sanitize_key( (string) $r['source'] );
        }
        if ( $r->has_param( 'search' ) ) {
            $filters['search'] = sanitize_text_field( (string) $r['search'] );
        }
        $rows = ( new PrinciplesRepository() )->listFiltered( $filters );
        return self::ok( [ 'principles' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new PrinciplesRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'principle_not_found', __( 'Principle not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $error = self::validateTaxonomy( $r );
        if ( $error !== null ) return $error;

        $code = sanitize_text_field( (string) ( $r['code'] ?? '' ) );
        if ( $code === '' ) {
            return self::fail( 'missing_code', __( 'A principle needs a code.', 'talenttrack' ), 400 );
        }

        $payload = self::writePayload( $r );
        $payload['code']       = $code;
        $payload['is_shipped'] = 0;

        $id = ( new PrinciplesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_principle.create.failed', [ 'code' => $code ] );
            return self::fail( 'db_error', __( 'Could not save the principle.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new PrinciplesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'principle_not_found', __( 'Principle not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'principle_shipped', __( 'Shipped principles are read-only. Clone it to edit.', 'talenttrack' ), 409 );
        }

        $error = self::validateTaxonomy( $r, true );
        if ( $error !== null ) return $error;

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'code' ) ) {
            $code = sanitize_text_field( (string) $r['code'] );
            if ( $code === '' ) {
                return self::fail( 'missing_code', __( 'A principle needs a code.', 'talenttrack' ), 400 );
            }
            $data['code'] = $code;
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new PrinciplesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'principle_not_found', __( 'Principle not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'principle_shipped', __( 'Shipped principles cannot be deleted.', 'talenttrack' ), 409 );
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

        if ( ! $partial || $r->has_param( 'team_function_key' ) ) {
            $out['team_function_key'] = sanitize_key( (string) ( $r['team_function_key'] ?? '' ) );
        }
        if ( ! $partial || $r->has_param( 'team_task_key' ) ) {
            $out['team_task_key'] = sanitize_key( (string) ( $r['team_task_key'] ?? '' ) );
        }

        foreach ( [ 'title' => 'title_json', 'explanation' => 'explanation_json', 'team_guidance' => 'team_guidance_json' ] as $field => $col ) {
            if ( $partial && ! $r->has_param( $field ) ) continue;
            $val    = $r[ $field ] ?? [];
            $long   = in_array( $field, [ 'explanation', 'team_guidance' ], true );
            $out[ $col ] = MultilingualField::encode( [
                'nl' => self::sanitizeLocale( is_array( $val ) ? ( $val['nl'] ?? '' ) : '', $long ),
                'en' => self::sanitizeLocale( is_array( $val ) ? ( $val['en'] ?? '' ) : '', $long ),
            ] );
        }

        if ( ! $partial || $r->has_param( 'line_guidance' ) ) {
            $out['line_guidance_json'] = self::encodeLines( is_array( $r['line_guidance'] ?? null ) ? $r['line_guidance'] : [] );
        }

        return $out;
    }

    /** @param mixed $value */
    private static function sanitizeLocale( $value, bool $long ): string {
        $value = is_string( $value ) ? $value : '';
        return $long ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
    }

    /**
     * Encode the `line_guidance` map (line → { nl, en }) into storage JSON.
     *
     * @param array<string,mixed> $lines
     */
    private static function encodeLines( array $lines ): string {
        $out = [];
        foreach ( array_keys( MethodologyEnums::lines() ) as $line ) {
            $entry = is_array( $lines[ $line ] ?? null ) ? $lines[ $line ] : [];
            $out[ $line ] = MultilingualField::decode( MultilingualField::encode( [
                'nl' => sanitize_textarea_field( is_string( $entry['nl'] ?? null ) ? $entry['nl'] : '' ),
                'en' => sanitize_textarea_field( is_string( $entry['en'] ?? null ) ? $entry['en'] : '' ),
            ] ) ) ?? [];
        }
        return (string) wp_json_encode( $out );
    }

    /**
     * Validate the closed taxonomies. On update, only validates the keys
     * actually supplied. Returns an error response or null when valid.
     */
    private static function validateTaxonomy( \WP_REST_Request $r, bool $partial = false ): ?\WP_REST_Response {
        if ( ! $partial || $r->has_param( 'team_function_key' ) ) {
            if ( ! MethodologyEnums::isValidFunction( sanitize_key( (string) ( $r['team_function_key'] ?? '' ) ) ) ) {
                return self::fail( 'invalid_team_function', __( 'Invalid team-function.', 'talenttrack' ), 400 );
            }
        }
        if ( ! $partial || $r->has_param( 'team_task_key' ) ) {
            if ( ! MethodologyEnums::isValidTask( sanitize_key( (string) ( $r['team_task_key'] ?? '' ) ) ) ) {
                return self::fail( 'invalid_team_task', __( 'Invalid team-task.', 'talenttrack' ), 400 );
            }
        }
        return null;
    }

    /**
     * Shape a principle row for the API. The localized strings resolve to
     * the current locale; when $full, the raw NL + EN values are included
     * under `*_i18n` so an authoring client can edit both languages.
     *
     * @return array<string,mixed>
     */
    private static function shape( object $p, bool $full = false ): array {
        $out = [
            'id'                => (int) $p->id,
            'code'              => (string) $p->code,
            'team_function_key' => (string) $p->team_function_key,
            'team_task_key'     => (string) $p->team_task_key,
            'is_shipped'        => ! empty( $p->is_shipped ),
            'title'             => MultilingualField::string( $p->title_json ),
            'explanation'       => MultilingualField::string( $p->explanation_json ),
            'team_guidance'     => MultilingualField::string( $p->team_guidance_json ),
        ];
        if ( $full ) {
            $out['title_i18n']         = MultilingualField::decode( $p->title_json ) ?: (object) [];
            $out['explanation_i18n']   = MultilingualField::decode( $p->explanation_json ) ?: (object) [];
            $out['team_guidance_i18n'] = MultilingualField::decode( $p->team_guidance_json ) ?: (object) [];
            $out['line_guidance']      = MultilingualField::decode( $p->line_guidance_json ) ?: (object) [];
        }
        return $out;
    }
}
