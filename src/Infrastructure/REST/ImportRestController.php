<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Import\ImportBatchRegistry;
use TT\Modules\Import\ImportService;
use TT\Modules\Import\ImportUndoService;

/**
 * ImportRestController (#2956, epic #2954) — spreadsheet import over REST.
 *
 *   POST /imports          — upload a workbook. Validates and reports; only
 *                            writes when `commit` is set.
 *   GET  /imports          — list the real import batches on this club.
 *
 * The PHP surfaces and this controller both call `ImportService`, so a
 * future non-WordPress front end gets the same validation and the same
 * answers (CLAUDE.md §4).
 *
 * CAPABILITY — `manage_options`, matching the existing import surface
 * (`DemoDataPage::CAP`) exactly rather than inventing a looser gate. A
 * dedicated `tt_manage_import` capability belongs with the import-history
 * surface in #2959, where there is a screen for the matrix to point at;
 * adding it here would seed a capability nothing yet renders.
 */
final class ImportRestController {

    private const NS  = 'talenttrack/v1';
    private const CAP = 'manage_options';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/imports', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'listBatches' ],
                'permission_callback' => [ __CLASS__, 'canManage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'upload' ],
                'permission_callback' => [ __CLASS__, 'canManage' ],
                'args'                => [
                    'commit' => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Write the rows. Omit to validate and report only.',
                    ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/imports/(?P<batch_key>[A-Za-z0-9._-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'undo' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );
    }

    public static function canManage(): bool {
        return current_user_can( self::CAP );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function undo( \WP_REST_Request $request ) {
        $batch_key = (string) $request->get_param( 'batch_key' );

        $result = ( new ImportUndoService() )->undo( $batch_key );

        if ( ! $result['ok'] ) {
            return new \WP_Error( 'tt_import_undo_failed', $result['error'], [ 'status' => 400 ] );
        }

        return rest_ensure_response( [
            'ok'      => true,
            'deleted' => $result['deleted'],
        ] );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function listBatches( \WP_REST_Request $request ) {
        return rest_ensure_response( [ 'batches' => ImportBatchRegistry::listBatches() ] );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function upload( \WP_REST_Request $request ) {
        $files = $request->get_file_params();
        $file  = $files['file'] ?? null;

        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
            return new \WP_Error(
                'tt_import_no_file',
                __( 'No workbook was uploaded.', 'talenttrack' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! empty( $file['error'] ) ) {
            // Most commonly the upload exceeded the host's limits, which
            // PHP reports here rather than as a readable message.
            return new \WP_Error(
                'tt_import_upload_failed',
                __( 'The upload did not complete. It may be larger than this server accepts.', 'talenttrack' ),
                [ 'status' => 400 ]
            );
        }

        $tmp_path = (string) $file['tmp_name'];
        $name     = (string) ( $file['name'] ?? 'workbook.xlsx' );
        $commit   = (bool) $request->get_param( 'commit' );

        $service = new ImportService();
        $result  = $commit
            ? $service->import( $tmp_path, $name )
            : $service->preview( $tmp_path, $name );

        if ( empty( $result['ok'] ) ) {
            // A workbook that fails validation is a normal outcome, not a
            // server fault — the blockers are the useful payload.
            return rest_ensure_response( [
                'ok'       => false,
                'committed'=> false,
                'blockers' => array_values( (array) ( $result['blockers'] ?? [] ) ),
                'warnings' => array_values( (array) ( $result['warnings'] ?? [] ) ),
            ] );
        }

        if ( $commit ) {
            Logger::info( 'import.excel.committed', [
                'file'     => $name,
                'batch'    => (string) ( $result['batch_id'] ?? '' ),
                'imported' => (array) ( $result['imported'] ?? [] ),
            ] );
        }

        return rest_ensure_response( [
            'ok'        => true,
            'committed' => ! empty( $result['batch_id'] ),
            'batch_key' => $result['batch_id'] ?? null,
            'imported'  => (array) ( $result['imported'] ?? [] ),
            'warnings'  => array_values( (array) ( $result['warnings'] ?? [] ) ),
            'sheets'    => array_values( (array) ( $result['present_sheets'] ?? [] ) ),
        ] );
    }
}
