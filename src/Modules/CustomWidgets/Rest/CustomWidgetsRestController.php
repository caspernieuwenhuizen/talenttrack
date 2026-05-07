<?php
namespace TT\Modules\CustomWidgets\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomWidgets\CustomDataSourceRegistry;
use TT\Modules\CustomWidgets\CustomWidgetException;
use TT\Modules\CustomWidgets\CustomWidgetService;
use WP_REST_Request;

/**
 * CustomWidgetsRestController (#0078 Phase 2) — REST surface for
 * `tt_custom_widgets` CRUD and the data-source catalogue the builder
 * UI consumes.
 *
 * Routes:
 *
 *   GET    /custom-widgets                  — list (current club)
 *   POST   /custom-widgets                  — create
 *   GET    /custom-widgets/{id}             — single (id or uuid)
 *   PUT    /custom-widgets/{id}             — update (id or uuid)
 *   DELETE /custom-widgets/{id}             — soft-delete (id or uuid)
 *   GET    /custom-data-sources             — catalogue for the builder
 *   GET    /custom-widgets/{id}/data        — render-time data fetch
 *                                              (Phase 4 fills the body)
 *   POST   /custom-widgets/{id}/clear-cache — manual cache flush
 *                                              (Phase 5 wires the cache)
 *
 * Caps for Phase 2:
 *   - listing + read use `tt_edit_persona_templates` (admin / HoD).
 *   - write actions also use `tt_edit_persona_templates` until Phase 5
 *     introduces the dedicated `tt_author_custom_widgets` cap.
 *   - the data-fetch route falls back to `read` for now; Phase 4
 *     replaces this with the underlying source's view cap.
 */
final class CustomWidgetsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/custom-widgets', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_widgets' ],
                'permission_callback' => [ __CLASS__, 'permWrite' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_widget' ],
                'permission_callback' => [ __CLASS__, 'permWrite' ],
            ],
        ] );

        register_rest_route( self::NS, '/custom-widgets/(?P<id>[A-Za-z0-9_-]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_widget' ],
                'permission_callback' => [ __CLASS__, 'permRead' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_widget' ],
                'permission_callback' => [ __CLASS__, 'permWrite' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_widget' ],
                'permission_callback' => [ __CLASS__, 'permWrite' ],
            ],
        ] );

        register_rest_route( self::NS, '/custom-data-sources', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_sources' ],
                'permission_callback' => [ __CLASS__, 'permWrite' ],
            ],
        ] );

        register_rest_route( self::NS, '/custom-widgets/(?P<id>[A-Za-z0-9_-]+)/data', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'fetch_data' ],
                'permission_callback' => [ __CLASS__, 'permRead' ],
            ],
        ] );

        register_rest_route( self::NS, '/custom-widgets/(?P<id>[A-Za-z0-9_-]+)/clear-cache', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'clear_cache' ],
                'permission_callback' => [ __CLASS__, 'permWrite' ],
            ],
        ] );
    }

    public static function permRead(): bool {
        return is_user_logged_in() && current_user_can( 'tt_edit_persona_templates' );
    }

    public static function permWrite(): bool {
        // Phase 5 swaps this for `tt_author_custom_widgets`.
        return is_user_logged_in() && current_user_can( 'tt_edit_persona_templates' );
    }

    public static function list_widgets( WP_REST_Request $req ) {
        $service = new CustomWidgetService();
        $include_archived = filter_var( $req->get_param( 'include_archived' ), FILTER_VALIDATE_BOOLEAN );
        $widgets = $service->listAll( (bool) $include_archived );
        $out = [];
        foreach ( $widgets as $w ) {
            $out[] = $w->toArray();
        }
        return rest_ensure_response( [ 'widgets' => $out ] );
    }

    public static function create_widget( WP_REST_Request $req ) {
        $service = new CustomWidgetService();
        try {
            $widget = $service->create( (array) $req->get_json_params(), get_current_user_id() );
        } catch ( CustomWidgetException $e ) {
            return self::errorFromKind( $e );
        }
        return rest_ensure_response( $widget->toArray() );
    }

    public static function get_widget( WP_REST_Request $req ) {
        $service = new CustomWidgetService();
        $widget  = $service->findByIdOrUuid( (string) $req['id'] );
        if ( $widget === null ) {
            return new \WP_Error( 'not_found', __( 'Custom widget not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( $widget->toArray() );
    }

    public static function update_widget( WP_REST_Request $req ) {
        $service = new CustomWidgetService();
        $widget  = $service->findByIdOrUuid( (string) $req['id'] );
        if ( $widget === null ) {
            return new \WP_Error( 'not_found', __( 'Custom widget not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        try {
            $updated = $service->update( $widget->id, (array) $req->get_json_params(), get_current_user_id() );
        } catch ( CustomWidgetException $e ) {
            return self::errorFromKind( $e );
        }
        return rest_ensure_response( $updated->toArray() );
    }

    public static function delete_widget( WP_REST_Request $req ) {
        $service = new CustomWidgetService();
        $widget  = $service->findByIdOrUuid( (string) $req['id'] );
        if ( $widget === null ) {
            return new \WP_Error( 'not_found', __( 'Custom widget not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        try {
            $service->archive( $widget->id, get_current_user_id() );
        } catch ( CustomWidgetException $e ) {
            return self::errorFromKind( $e );
        }
        return rest_ensure_response( [ 'archived' => true, 'id' => $widget->id, 'uuid' => $widget->uuid ] );
    }

    public static function list_sources( WP_REST_Request $req ) {
        $out = [];
        foreach ( CustomDataSourceRegistry::all() as $id => $source ) {
            $out[] = [
                'id'           => $id,
                'label'        => $source->label(),
                'columns'      => $source->columns(),
                'filters'      => $source->filters(),
                'aggregations' => $source->aggregations(),
            ];
        }
        return rest_ensure_response( [ 'sources' => $out ] );
    }

    /**
     * Render-time data fetch — returns the rows the renderer would
     * draw. Phase 4 replaces the body with a call into a future
     * `CustomWidgetRenderer::fetchRows()` that handles caching +
     * source-cap inheritance. Phase 2 exposes the route shape so the
     * builder UI's preview can hit it.
     */
    public static function fetch_data( WP_REST_Request $req ) {
        $service = new CustomWidgetService();
        $widget  = $service->findByIdOrUuid( (string) $req['id'] );
        if ( $widget === null ) {
            return new \WP_Error( 'not_found', __( 'Custom widget not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        $source = CustomDataSourceRegistry::find( $widget->dataSourceId );
        if ( $source === null ) {
            return new \WP_Error( 'unknown_data_source', __( 'Data source no longer registered.', 'talenttrack' ), [ 'status' => 410 ] );
        }
        $columns = isset( $widget->definition['columns'] ) && is_array( $widget->definition['columns'] )
            ? $widget->definition['columns']
            : [];
        $filters = isset( $widget->definition['filters'] ) && is_array( $widget->definition['filters'] )
            ? $widget->definition['filters']
            : [];
        $limit = (int) ( $req->get_param( 'limit' ) ?? 100 );
        if ( $limit < 1 ) $limit = 1;
        if ( $limit > 5000 ) $limit = 5000;
        $rows = $source->fetch( get_current_user_id(), $filters, $columns, $limit );
        return rest_ensure_response( [
            'uuid'       => $widget->uuid,
            'chart_type' => $widget->chartType,
            'columns'    => $columns,
            'rows'       => $rows,
        ] );
    }

    /**
     * Manual cache flush — Phase 2 returns a no-op success response so
     * the builder UI can wire the button shape; Phase 5 plugs in the
     * actual transient delete.
     */
    public static function clear_cache( WP_REST_Request $req ) {
        $service = new CustomWidgetService();
        $widget  = $service->findByIdOrUuid( (string) $req['id'] );
        if ( $widget === null ) {
            return new \WP_Error( 'not_found', __( 'Custom widget not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        do_action( 'tt_custom_widget_cache_flush_requested', $widget );
        return rest_ensure_response( [ 'flushed' => true, 'uuid' => $widget->uuid ] );
    }

    private static function errorFromKind( CustomWidgetException $e ): \WP_Error {
        $statuses = [
            'not_found'           => 404,
            'forbidden'           => 403,
            'invalid_chart_type'  => 400,
            'unknown_data_source' => 400,
            'missing_columns'     => 400,
            'missing_aggregation' => 400,
            'bad_aggregation'     => 400,
            'bad_name'            => 400,
        ];
        $status = $statuses[ $e->kind ] ?? 500;
        return new \WP_Error( $e->kind, $e->getMessage(), [ 'status' => $status ] );
    }
}
