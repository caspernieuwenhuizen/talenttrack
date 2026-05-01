<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvalCategoriesRestController — /wp-json/talenttrack/v1/eval-categories
 *
 * #0019 Sprint 5. Wraps `EvalCategoriesRepository` for the new
 * frontend admin-tier surface. Hierarchical CRUD + up/down reorder
 * within a level. Per-age-group weight editing stays in wp-admin
 * for Sprint 5 (it's a separate `CategoryWeightsPage` that'd benefit
 * from its own focused frontend port — out of scope here).
 *
 * Cap gate: `tt_edit_settings` (eval categories are a settings-tier
 * concern; matches the wp-admin page).
 */
class EvalCategoriesRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/eval-categories', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_categories' ],
                'permission_callback' => function () { return current_user_can( 'tt_view_evaluation_categories' ) || current_user_can( 'tt_edit_evaluation_categories' ); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_category' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_evaluation_categories' ); },
            ],
        ] );
        register_rest_route( self::NS, '/eval-categories/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_category' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_evaluation_categories' ); },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_category' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_evaluation_categories' ); },
            ],
        ] );
        register_rest_route( self::NS, '/eval-categories/(?P<id>\d+)/move', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'move_category' ],
                'permission_callback' => function () { return current_user_can( 'tt_edit_evaluation_categories' ); },
            ],
        ] );
    }

    public static function list_categories( \WP_REST_Request $r ) {
        $repo = new EvalCategoriesRepository();
        return RestResponse::success( [
            'rows' => array_map( [ __CLASS__, 'fmt' ], $repo->getAll( false ) ),
        ] );
    }

    public static function create_category( \WP_REST_Request $r ) {
        $data = self::extract( $r );
        if ( $data['label'] === '' ) {
            return RestResponse::error( 'missing_fields', __( 'A label is required.', 'talenttrack' ), 400 );
        }
        if ( $data['category_key'] === '' ) {
            $data['category_key'] = self::generateKey( $data['label'] );
        }
        $repo = new EvalCategoriesRepository();
        $id = $repo->create( $data );
        if ( $id <= 0 ) {
            global $wpdb;
            Logger::error( 'rest.eval_category.create.failed', [ 'db_error' => (string) $wpdb->last_error, 'data' => $data ] );
            return RestResponse::error( 'db_error', __( 'The category could not be created.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function update_category( \WP_REST_Request $r ) {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid category id.', 'talenttrack' ), 400 );
        $data = self::extract( $r );
        // Don't allow editing category_key after creation — it's
        // referenced by ratings.
        unset( $data['category_key'] );
        $repo = new EvalCategoriesRepository();
        if ( ! $repo->update( $id, $data ) ) {
            global $wpdb;
            Logger::error( 'rest.eval_category.update.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The category could not be updated.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    public static function delete_category( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        if ( $id <= 0 ) return RestResponse::error( 'bad_id', __( 'Invalid category id.', 'talenttrack' ), 400 );

        $existing = ( new EvalCategoriesRepository() )->get( $id );
        if ( ! $existing ) return RestResponse::error( 'not_found', __( 'Category not found.', 'talenttrack' ), 404 );
        if ( ! empty( $existing->is_system ) ) {
            return RestResponse::error( 'protected_system', __( 'System categories cannot be deleted.', 'talenttrack' ), 403 );
        }

        // Refuse if there are children OR ratings referencing it.
        $children = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_eval_categories WHERE parent_id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        if ( $children > 0 ) {
            return RestResponse::error( 'has_children', __( 'This category has subcategories. Delete or reparent them first.', 'talenttrack' ), 409 );
        }
        $ratings = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_eval_ratings WHERE category_id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        if ( $ratings > 0 ) {
            return RestResponse::error(
                'in_use',
                /* translators: %d: number of ratings stored */
                sprintf( __( 'This category has %d rating(s) recorded against it. Deactivate the category instead so the data is preserved.', 'talenttrack' ), $ratings ),
                409,
                [ 'ratings' => $ratings ]
            );
        }

        $ok = $wpdb->delete( $wpdb->prefix . 'tt_eval_categories', [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) {
            Logger::error( 'rest.eval_category.delete.failed', [ 'db_error' => (string) $wpdb->last_error, 'id' => $id ] );
            return RestResponse::error( 'db_error', __( 'The category could not be deleted.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    public static function move_category( \WP_REST_Request $r ) {
        global $wpdb;
        $id = absint( $r['id'] );
        $direction = sanitize_key( (string) ( $r['direction'] ?? '' ) );
        if ( $id <= 0 || ! in_array( $direction, [ 'up', 'down' ], true ) ) {
            return RestResponse::error( 'bad_request', __( 'Invalid move parameters.', 'talenttrack' ), 400 );
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_eval_categories WHERE id = %d AND club_id = %d", $id, CurrentClub::id() ) );
        if ( ! $row ) return RestResponse::error( 'not_found', __( 'Category not found.', 'talenttrack' ), 404 );

        $compare = $direction === 'up' ? '<' : '>';
        $order   = $direction === 'up' ? 'DESC' : 'ASC';
        $parent_clause = $row->parent_id === null
            ? 'parent_id IS NULL'
            : $wpdb->prepare( 'parent_id = %d', (int) $row->parent_id );
        $neighbor = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, display_order FROM {$wpdb->prefix}tt_eval_categories
             WHERE {$parent_clause} AND display_order {$compare} %d AND club_id = %d
             ORDER BY display_order {$order}, id {$order}
             LIMIT 1",
            (int) $row->display_order, CurrentClub::id()
        ) );
        if ( ! $neighbor ) return RestResponse::success( [ 'id' => $id, 'no_op' => true ] );

        $wpdb->update( $wpdb->prefix . 'tt_eval_categories', [ 'display_order' => (int) $neighbor->display_order ], [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        $wpdb->update( $wpdb->prefix . 'tt_eval_categories', [ 'display_order' => (int) $row->display_order ], [ 'id' => (int) $neighbor->id, 'club_id' => CurrentClub::id() ] );
        return RestResponse::success( [ 'id' => $id, 'swapped_with' => (int) $neighbor->id ] );
    }

    // Helpers

    /**
     * @return array<string, mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        $parent_id_raw = $r->get_param( 'parent_id' );
        return [
            'category_key'  => sanitize_key( (string) ( $r['category_key'] ?? '' ) ),
            'label'         => sanitize_text_field( (string) ( $r['label']         ?? '' ) ),
            'description'   => sanitize_textarea_field( (string) ( $r['description'] ?? '' ) ),
            'parent_id'     => ( $parent_id_raw === null || $parent_id_raw === '' || (int) $parent_id_raw <= 0 ) ? null : absint( $parent_id_raw ),
            'display_order' => absint( $r['display_order'] ?? 0 ),
            'is_active'     => $r->get_param( 'is_active' ) === null ? 1 : ( ! empty( $r['is_active'] ) ? 1 : 0 ),
        ];
    }

    private static function generateKey( string $label ): string {
        $base = sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
        if ( $base === '' ) $base = 'cat_' . wp_rand( 1000, 9999 );
        return substr( $base, 0, 60 );
    }

    private static function fmt( object $c ): array {
        return [
            'id'            => (int) $c->id,
            'parent_id'     => $c->parent_id !== null ? (int) $c->parent_id : null,
            'category_key'  => (string) $c->category_key,
            'label'         => (string) $c->label,
            'description'   => (string) ( $c->description ?? '' ),
            'display_order' => (int) ( $c->display_order ?? 0 ),
            'is_active'     => ! empty( $c->is_active ),
            'is_system'     => ! empty( $c->is_system ),
        ];
    }
}
