<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DataBrowser\DataBrowserRepository;
use TT\Modules\DataBrowser\DataBrowserService;
use TT\Modules\DataBrowser\SchemaIntrospector;

/**
 * DataBrowserRestController (#1859) — read-only REST surface for the Data
 * Browser. This is the SaaS-facing contract: the same {@see DataBrowserService}
 * the plugin view renders from. Every route is gated on the dedicated
 * `tt_view_data_browser` capability and validates the table name against
 * the live schema allowlist before any query runs.
 *
 *   GET /talenttrack/v1/data-browser/tables
 *   GET /talenttrack/v1/data-browser/tables/{table}/schema
 *   GET /talenttrack/v1/data-browser/tables/{table}/rows?page=&per_page=&q=&pk=
 */
class DataBrowserRestController {

    const NS  = 'talenttrack/v1';
    const CAP = 'tt_view_data_browser';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $table_arg = [
            'table' => [
                'validate_callback' => static fn( $v ) => is_string( $v ) && preg_match( '/^tt_[a-z0-9_]+$/', $v ) === 1,
            ],
        ];

        register_rest_route( self::NS, '/data-browser/tables', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_tables' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );

        register_rest_route( self::NS, '/data-browser/tables/(?P<table>tt_[a-z0-9_]+)/schema', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_schema' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args'                => $table_arg,
            ],
        ] );

        register_rest_route( self::NS, '/data-browser/tables/(?P<table>tt_[a-z0-9_]+)/rows', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_rows' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args'                => $table_arg,
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( self::CAP );
    }

    /** GET /data-browser/tables */
    public static function list_tables() {
        return RestResponse::success( [
            'tables' => DataBrowserService::tablesOverview(),
        ] );
    }

    /** GET /data-browser/tables/{table}/schema */
    public static function get_schema( \WP_REST_Request $request ) {
        $table = (string) $request['table'];
        if ( ! SchemaIntrospector::exists( $table ) ) {
            return RestResponse::notFound( 'table_not_found', __( 'Unknown table.', 'talenttrack' ) );
        }
        return RestResponse::success( [
            'key'           => $table,
            'label'         => \TT\Modules\DataBrowser\SemanticRegistry::tableLabel( $table ),
            'description'   => \TT\Modules\DataBrowser\SemanticRegistry::tableDescription( $table ),
            'sensitive'     => \TT\Modules\DataBrowser\SemanticRegistry::isSensitive( $table ),
            'columns'       => DataBrowserService::columns( $table ),
            'relationships' => [
                'outgoing' => \TT\Modules\DataBrowser\RelationshipResolver::outgoing( $table ),
                'incoming' => \TT\Modules\DataBrowser\RelationshipResolver::incoming( $table ),
            ],
        ] );
    }

    /** GET /data-browser/tables/{table}/rows */
    public static function get_rows( \WP_REST_Request $request ) {
        $table = (string) $request['table'];
        if ( ! SchemaIntrospector::exists( $table ) ) {
            return RestResponse::notFound( 'table_not_found', __( 'Unknown table.', 'talenttrack' ) );
        }

        $page     = max( 1, absint( $request['page'] ?? 1 ) );
        $per_page = absint( $request['per_page'] ?? DataBrowserRepository::PER_PAGE );
        $search   = sanitize_text_field( (string) ( $request['q'] ?? '' ) );
        $pk_raw   = $request['pk'] ?? null;
        $pk       = ( $pk_raw === null || $pk_raw === '' ) ? null : absint( $pk_raw );

        $view = DataBrowserService::tableView( $table, $page, $per_page, $search, $pk );

        return RestResponse::success( [
            'key'         => $view['key'],
            'rows'        => $view['rows'],
            'total'       => $view['total'],
            'page'        => $view['page'],
            'per_page'    => $view['per_page'],
            'total_pages' => $view['total_pages'],
        ] );
    }
}
