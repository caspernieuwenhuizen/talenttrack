<?php
namespace TT\Modules\Export\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExportService;
use WP_REST_Request;

/**
 * ExportRestController (#0063) — `/wp-json/talenttrack/v1/exports/{key}`.
 *
 * Two routes:
 *   GET  /exports                 — list registered exporters (operator menu).
 *   GET  /exports/{key}           — synchronous download of `{key}` in
 *                                   the requested format.
 *
 * Async dispatch (`POST /exports/{key}` queuing an Action Scheduler
 * job) lands with the first big-export use case. The sync GET path
 * covers every v1 use case under the 30 s budget.
 *
 * Format selection comes from `?format=csv|json|ics` (default = the
 * first supported format the exporter declares). Filters come from
 * additional query params; the exporter validates the keys it accepts.
 *
 * Output mode: the controller streams the rendered bytes directly via
 * `wp_send_headers()` + `echo`, then `exit`s. WordPress's REST envelope
 * isn't appropriate for a binary download.
 */
final class ExportRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/exports', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_exporters' ],
                'permission_callback' => [ __CLASS__, 'permissionCallback' ],
            ],
        ] );
        register_rest_route( self::NS, '/exports/(?P<key>[a-z0-9_-]+)', [
            [
                'methods'             => [ 'GET', 'POST' ],
                'callback'            => [ __CLASS__, 'run' ],
                'permission_callback' => [ __CLASS__, 'permissionCallback' ],
            ],
        ] );
    }

    /**
     * The route gate is "logged in"; per-exporter cap-gating runs
     * inside `ExportService::run()` against the exporter's
     * `requiredCap()`. This keeps the route open to any holder of any
     * `tt_export_*` cap and pushes the precise check into the service.
     */
    public static function permissionCallback(): bool {
        return is_user_logged_in();
    }

    public static function list_exporters( WP_REST_Request $req ) {
        $out = [];
        foreach ( ExporterRegistry::all() as $key => $exporter ) {
            // Hide exporters the caller has no chance of running.
            if ( $exporter->requiredCap() !== '' && ! current_user_can( $exporter->requiredCap() ) ) {
                continue;
            }
            $out[] = [
                'key'     => $key,
                'label'   => $exporter->label(),
                'formats' => $exporter->supportedFormats(),
                'cap'     => $exporter->requiredCap(),
            ];
        }
        return rest_ensure_response( [ 'exporters' => $out ] );
    }

    public static function run( WP_REST_Request $req ) {
        $key      = (string) $req['key'];
        $exporter = ExporterRegistry::get( $key );
        if ( $exporter === null ) {
            return new \WP_Error( 'unknown_exporter', __( 'No such export.', 'talenttrack' ), [ 'status' => 404 ] );
        }

        $format = (string) ( $req->get_param( 'format' ) ?? '' );
        if ( $format === '' ) {
            $supported = $exporter->supportedFormats();
            $format    = $supported[0] ?? 'csv';
        }

        $entity_id = $req->get_param( 'entity_id' );
        $entity_id = $entity_id !== null ? absint( $entity_id ) : null;

        $brand = $req->get_param( 'brand' );
        $brand = is_string( $brand ) && in_array( $brand, [ 'auto', 'blank', 'letterhead' ], true ) ? $brand : null;

        // Everything else on the query string OR in the POST JSON
        // body is treated as a filter and handed to the exporter for
        // validation. The reserved params above are stripped first.
        // The FrontendExportsView JS submits filters as a JSON body
        // via POST (#862), so reading get_query_params alone misses
        // every filter — merge both sources.
        $query   = $req->get_query_params();
        $body    = $req->get_json_params();
        $filters = array_merge(
            is_array( $query ) ? $query : [],
            is_array( $body )  ? $body  : []
        );
        unset( $filters['format'], $filters['entity_id'], $filters['brand'] );

        // #986 — accept either `columns` (mirrors the Exports page form
        // name) or the canonical `selected_columns` key. Either lands
        // in the request as `selected_columns` which `ExportService`
        // hands to `ColumnFilter::apply()`.
        if ( isset( $filters['columns'] ) ) {
            if ( is_array( $filters['columns'] ) && $filters['columns'] !== [] ) {
                $filters['selected_columns'] = array_values( array_map( 'strval', $filters['columns'] ) );
            }
            unset( $filters['columns'] );
        }

        $request = new ExportRequest(
            $key,
            $format,
            (int) CurrentClub::id(),
            (int) get_current_user_id(),
            $entity_id !== null && $entity_id > 0 ? $entity_id : null,
            is_array( $filters ) ? $filters : [],
            $brand,
            null
        );

        try {
            $result = ( new ExportService() )->run( $request );
        } catch ( ExportException $e ) {
            $status = self::statusFor( $e->errorKey );
            return new \WP_Error( $e->errorKey, $e->getMessage(), [ 'status' => $status ] );
        }

        // Bypass the REST envelope and stream the bytes directly.
        nocache_headers();
        header( 'Content-Type: ' . $result->mime );
        header( 'Content-Length: ' . $result->size );
        header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $result->filename ) . '"' );
        echo $result->bytes; // phpcs:ignore
        exit;
    }

    private static function statusFor( string $errorKey ): int {
        return [
            'unknown_exporter'   => 404,
            'forbidden'          => 403,
            'unsupported_format' => 400,
            'bad_filters'        => 400,
            'no_renderer'        => 500,
        ][ $errorKey ] ?? 500;
    }
}
