<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\Logging\Logger;

/**
 * CustomFieldsRestController — /wp-json/talenttrack/v1/custom-fields
 *
 * #0019 Sprint 5. Wraps `CustomFieldsRepository` for the new
 * frontend admin-tier surface. CRUD + up/down reorder.
 *
 * Cap gate: `tt_edit_settings` (custom fields are a settings-tier
 * concern; matches the wp-admin page's gate).
 */
class CustomFieldsRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/custom-fields', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_fields' ],
                'permission_callback' => function () { return current_user_can( 'tt_view_settings' ) || current_user_can( 'tt_edit_settings' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_field' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_settings' ); },
            ],
        ] );
        register_rest_route( self::NS, '/custom-fields/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_field' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_settings' ); },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_field' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_settings' ); },
            ],
        ] );
        register_rest_route( self::NS, '/custom-fields/(?P<id>\d+)/move', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'move_field' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_settings' ); },
            ],
        ] );
    }

    public static function list_fields( \WP_REST_Request $r ) {
        global $wpdb;
        $page     = max( 1, absint( $r['page'] ?? 1 ) );
        $per_page = self::clamp_per_page( $r['per_page'] ?? 25 );

        $filter = is_array( $r['filter'] ?? null ) ? $r['filter'] : [];
        $entity = sanitize_key( (string) ( $filter['entity_type'] ?? '' ) );
        if ( $entity !== '' && ! in_array( $entity, CustomFieldsRepository::allowedEntityTypes(), true ) ) {
            return RestResponse::error( 'bad_entity', __( 'Unknown entity type.', 'talenttrack' ), 400 );
        }

        $where = [];
        $params = [];
        if ( $entity !== '' ) { $where[] = 'entity_type = %s'; $params[] = $entity; }
        if ( ! empty( $r['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $r['search'] ) . '%';
            $where[] = '(label LIKE %s OR field_key LIKE %s)';
            $params[] = $like; $params[] = $like;
        }
        $where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

        $list_sql = "SELECT * FROM {$wpdb->prefix}tt_custom_fields {$where_sql}
                     ORDER BY entity_type ASC, sort_order ASC, id ASC
                     LIMIT %d OFFSET %d";
        $offset = ( $page - 1 ) * $per_page;
        $list_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ) ?: [];

        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}tt_custom_fields {$where_sql}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            : (int) $wpdb->get_var( $count_sql );

        return RestResponse::success( [
            'rows'     => array_map( [ __CLASS__, 'fmt' ], $rows ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    public static function create_field( \WP_REST_Request $r ) {
        $data = self::extract( $r );
        $errors = self::validate( $data, true );
        if ( $errors ) {
            return RestResponse::error( 'validation', __( 'The submitted field is invalid.', 'talenttrack' ), 400, [ 'errors' => $errors ] );
        }
        $repo = new CustomFieldsRepository();
        if ( empty( $data['field_key'] ) ) {
            $data['field_key'] = $repo->generateUniqueKey( (string) $data['entity_type'], (string) $data['label'] );
        }
        $id = $repo->create( $data );
        if ( $id <= 0 ) {
            global $wpdb;
            Logger::error( 'rest.custom_field.create.failed', [ 'db_error' => (string) $wpdb->last_error, 'data' => $data ] );
            return RestResponse::error( 'db_error', __( 'The field could not be created.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update_field( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid field id.', 'talenttrack' ), 400 );
        $data = self::extract( $r );
        $errors = self::validate( $data, false );
        if ( $errors ) {
            return RestResponse::error( 'validation', __( 'The submitted field is invalid.', 'talenttrack' ), 400, [ 'errors' => $errors ] );
        }
        $repo = new CustomFieldsRepository();
        if ( ! $repo->update( $id, $data ) ) {
            global $wpdb;
            Logger::error( 'rest.custom_field.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The field could not be updated.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_field( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid field id.', 'talenttrack' ), 400 );
        $values = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_custom_values WHERE field_id = %d",
            $id
        ) );
        if ( $values > 0 ) {
            return RestResponse::error(
                'in_use',
                /* translators: %d: number of stored values */
                sprintf( __( 'This field has %d stored value(s). Deactivate the field instead so the values are preserved.', 'talenttrack' ), $values ),
                409,
                [ 'value_count' => $values ]
            );
        }
        $ok = $wpdb->delete( $wpdb->prefix . 'tt_custom_fields', [ 'id' => $id ] );
        if ( $ok === false ) {
            Logger::error( 'rest.custom_field.delete.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The field could not be deleted.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function move_field( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        $direction = sanitize_key( (string) ( $r['direction'] ?? '' ) );
        if ( $id <= 0 || ! in_array( $direction, [ 'up', 'down' ], true ) ) {
            return RestResponse::error( 'bad_request', __( 'Invalid move parameters.', 'talenttrack' ), 400 );
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_custom_fields WHERE id = %d", $id ) );
        if ( ! $row ) return RestResponse::error( 'not_found', __( 'Field not found.', 'talenttrack' ), 404 );

        $compare = $direction === 'up' ? '<' : '>';
        $order   = $direction === 'up' ? 'DESC' : 'ASC';
        $neighbor = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, sort_order FROM {$wpdb->prefix}tt_custom_fields
             WHERE entity_type = %s AND sort_order {$compare} %d
             ORDER BY sort_order {$order}, id {$order}
             LIMIT 1",
            $row->entity_type, (int) $row->sort_order
        ) );
        if ( ! $neighbor ) {
            return RestResponse::success( [ 'id' => $id, 'no_op' => true ] );
        }

        $wpdb->update( $wpdb->prefix . 'tt_custom_fields', [ 'sort_order' => (int) $neighbor->sort_order ], [ 'id' => $id ] );
        $wpdb->update( $wpdb->prefix . 'tt_custom_fields', [ 'sort_order' => (int) $row->sort_order ], [ 'id' => (int) $neighbor->id ] );
        return RestResponse::success( [ 'id' => $id, 'swapped_with' => (int) $neighbor->id ] );
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function clamp_per_page( $value ): int {
        $n = absint( $value );
        if ( ! in_array( $n, [ 10, 25, 50, 100 ], true ) ) return 25;
        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        $options = $r->get_param( 'options' );
        return [
            'entity_type' => sanitize_text_field( (string) ( $r['entity_type'] ?? '' ) ),
            'field_key'   => sanitize_key( (string) ( $r['field_key']   ?? '' ) ),
            'label'       => sanitize_text_field( (string) ( $r['label']       ?? '' ) ),
            'field_type'  => sanitize_text_field( (string) ( $r['field_type']  ?? '' ) ),
            'options'     => is_array( $options ) ? wp_json_encode( array_map( 'sanitize_text_field', $options ) ) : '',
            'is_required' => empty( $r['is_required'] ) ? 0 : 1,
            'is_active'   => $r->get_param( 'is_active' ) === null ? 1 : ( ! empty( $r['is_active'] ) ? 1 : 0 ),
            'sort_order'  => absint( $r['sort_order'] ?? 0 ),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int, array{code:string, message:string}>
     */
    private static function validate( array $data, bool $is_create ): array {
        $errors = [];
        if ( $data['label'] === '' ) {
            $errors[] = [ 'code' => 'missing_label', 'message' => __( 'A label is required.', 'talenttrack' ) ];
        }
        if ( $is_create && $data['entity_type'] === '' ) {
            $errors[] = [ 'code' => 'missing_entity', 'message' => __( 'An entity type is required.', 'talenttrack' ) ];
        }
        if ( $data['entity_type'] !== '' && ! in_array( $data['entity_type'], CustomFieldsRepository::allowedEntityTypes(), true ) ) {
            $errors[] = [ 'code' => 'bad_entity', 'message' => __( 'Unknown entity type.', 'talenttrack' ) ];
        }
        if ( $data['field_type'] !== '' && ! in_array( $data['field_type'], CustomFieldsRepository::allowedTypes(), true ) ) {
            $errors[] = [ 'code' => 'bad_type', 'message' => __( 'Unknown field type.', 'talenttrack' ) ];
        }
        return $errors;
    }

    private static function fmt( object $f ): array {
        $opts = json_decode( (string) ( $f->options ?? '' ), true );
        return [
            'id'           => (int) $f->id,
            'entity_type'  => (string) $f->entity_type,
            'field_key'    => (string) $f->field_key,
            'label'        => (string) $f->label,
            'field_type'   => (string) $f->field_type,
            'options'      => is_array( $opts ) ? $opts : [],
            'is_required'  => ! empty( $f->is_required ),
            'is_active'    => ! empty( $f->is_active ),
            'sort_order'   => (int) $f->sort_order,
        ];
    }
}
