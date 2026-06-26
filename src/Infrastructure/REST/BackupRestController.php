<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Logging\Logger;
use TT\Modules\Authorization\Impersonation\ImpersonationContext;
use TT\Modules\Backup\BackupRestorer;
use TT\Modules\Backup\BackupRunner;
use TT\Modules\Backup\BackupSerializer;
use TT\Modules\Backup\BackupSettings;
use TT\Modules\Backup\Destinations\LocalDestination;
use TT\Modules\Backup\MigrationExporter;
use TT\Modules\Backup\MigrationImporter;
use TT\Modules\Backup\Scheduler;

/**
 * BackupRestController (#1937, child of #1533) — write + read surface for
 * the frontend Backups view (`?tt_view=backups`, FrontendBackupsView).
 * Ports the wp-admin Backups page (Configuration → Backups) to the
 * frontend without a wp-admin bounce.
 *
 *   GET    /backups                       — list stored local backups (+ download URL)
 *   POST   /backups/settings              — save schedule / retention / destinations
 *   POST   /backups/run                   — run a backup now
 *   DELETE /backups/{id}                  — delete a stored backup file
 *   GET    /backups/{id}/preview          — restore preview (table row counts)
 *   POST   /backups/{id}/restore          — DESTRUCTIVE full restore (typed-confirm RESTORE)
 *   POST   /backups/migration/preview     — upload a .ttmig and read-only preview (stages the archive)
 *   POST   /backups/migration/dry-run     — dry-run the staged import (no writes)
 *   POST   /backups/migration/commit      — DESTRUCTIVE commit of the staged import (typed-confirm IMPORT)
 *
 * Downloads + the .ttmig export are streamed from the PHP view layer
 * (binary responses don't fit the JSON envelope); this controller returns
 * a download URL for each stored backup (SaaS §4 — a URL, never a
 * server-relative path).
 *
 * Every route gates its permission_callback on `tt_manage_backups`
 * (matches BackupSettingsPage::CAP) — restore + import are the most
 * sensitive and gate identically; never a role-string compare, never
 * __return_true. The two destructive writes (restore, import commit)
 * additionally refuse to run while impersonating, preserve the typed
 * confirmation gate, and are audit-logged.
 *
 * The controller stays thin: serialization, snapshot validation, the
 * restore engine, and the migration import engine all live in the Backup
 * module services. The wp-admin page and this surface call the same
 * domain layer, so a future SaaS frontend gets identical behaviour.
 */
final class BackupRestController {

    private const NS  = 'talenttrack/v1';
    private const CAP = 'tt_manage_backups';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/backups', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'listBackups' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/settings', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'saveSettings' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/run', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'runNow' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/(?P<id>[A-Za-z0-9._-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'deleteBackup' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/(?P<id>[A-Za-z0-9._-]+)/preview', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'previewRestore' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/(?P<id>[A-Za-z0-9._-]+)/restore', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'restore' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/migration/preview', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'migrationPreview' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/migration/dry-run', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'migrationDryRun' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );

        register_rest_route( self::NS, '/backups/migration/commit', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'migrationCommit' ],
            'permission_callback' => [ __CLASS__, 'canManage' ],
        ] );
    }

    public static function canManage(): bool {
        return current_user_can( self::CAP );
    }

    // Stored backups

    /**
     * List local backups with a download URL each (SaaS §4 — a URL, not a
     * server-relative path). The download route is the wp-admin admin-post
     * stream, which stays the single binary chokepoint; a future
     * object-storage backend swaps the URL without changing this shape.
     */
    public static function listBackups( \WP_REST_Request $r ): \WP_REST_Response {
        $local = new LocalDestination();
        $rows  = $local->listBackups();
        $out   = [];
        foreach ( $rows as $row ) {
            $id = (string) ( $row['id'] ?? '' );
            if ( $id === '' ) continue;
            $out[] = [
                'id'           => $id,
                'filename'     => (string) ( $row['filename'] ?? $id ),
                'size'         => (int) ( $row['size'] ?? 0 ),
                'created_at'   => (string) ( $row['created_at'] ?? '' ),
                'preset'       => (string) ( $row['preset'] ?? '' ),
                'download_url' => self::downloadUrl( $id ),
            ];
        }
        return RestResponse::success( [ 'backups' => $out ] );
    }

    /**
     * Save schedule / retention / destinations. Mirrors the wp-admin
     * handleSaveSettings(); the persistence + cron reconcile live in
     * BackupSettings / Scheduler.
     */
    public static function saveSettings( \WP_REST_Request $r ): \WP_REST_Response {
        $preset    = sanitize_key( (string) ( $r->get_param( 'preset' ) ?? '' ) );
        $schedule  = sanitize_key( (string) ( $r->get_param( 'schedule' ) ?? '' ) );
        $retention = (int) ( $r->get_param( 'retention' ) ?? 30 );

        $tables_raw      = (string) ( $r->get_param( 'selected_tables' ) ?? '' );
        $selected_tables = $tables_raw === '' ? [] : ( preg_split( '/\s+/', $tables_raw ) ?: [] );

        $local_on   = ! empty( $r->get_param( 'dest_local' ) );
        $email_on   = ! empty( $r->get_param( 'dest_email' ) );
        $recipients = (string) ( $r->get_param( 'email_recipients' ) ?? '' );

        BackupSettings::save( [
            'preset'          => $preset,
            'selected_tables' => $selected_tables,
            'schedule'        => $schedule,
            'retention'       => $retention,
            'destinations'    => [
                'local' => [ 'enabled' => $local_on ],
                'email' => [
                    'enabled'    => $email_on,
                    'recipients' => $recipients,
                ],
            ],
        ] );
        Scheduler::reconcile();
        Logger::info( 'rest.backup.settings_saved', [ 'user' => get_current_user_id() ] );

        return RestResponse::success( [ 'saved' => true ] );
    }

    public static function runNow( \WP_REST_Request $r ): \WP_REST_Response {
        $result = BackupRunner::run();
        if ( empty( $result['ok'] ) ) {
            return RestResponse::error(
                'backup_run_failed',
                (string) ( $result['error'] ?? __( 'The backup could not be completed.', 'talenttrack' ) ),
                500
            );
        }
        Logger::info( 'rest.backup.run', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'ok' => true ] );
    }

    public static function deleteBackup( \WP_REST_Request $r ): \WP_REST_Response {
        $id    = self::backupId( $r );
        $local = new LocalDestination();
        if ( $local->fetchLocalPath( $id ) === '' ) {
            return RestResponse::notFound( 'backup_not_found', __( 'Backup not found.', 'talenttrack' ) );
        }
        $local->purge( $id );
        Logger::info( 'rest.backup.deleted', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'deleted' => true ] );
    }

    // Restore

    public static function previewRestore( \WP_REST_Request $r ): \WP_REST_Response {
        $id    = self::backupId( $r );
        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( $id );
        if ( $path === '' ) {
            return RestResponse::notFound( 'backup_not_found', __( 'Backup not found.', 'talenttrack' ) );
        }
        $preview = BackupRestorer::preview( $path );
        if ( empty( $preview['ok'] ) ) {
            return RestResponse::error(
                'preview_failed',
                (string) ( $preview['error'] ?? __( 'The backup could not be read.', 'talenttrack' ) ),
                422
            );
        }
        $summary = [];
        foreach ( (array) ( $preview['summary'] ?? [] ) as $tbl => $count ) {
            $summary[] = [ 'table' => (string) $tbl, 'rows' => (int) $count ];
        }
        return RestResponse::success( [
            'created_at'     => (string) ( $preview['created_at'] ?? '' ),
            'plugin_version' => (string) ( $preview['plugin_version'] ?? '' ),
            'preset'         => (string) ( $preview['preset'] ?? '' ),
            'summary'        => $summary,
        ] );
    }

    /**
     * DESTRUCTIVE — replace the current data with the backup. Preserves
     * the wp-admin gates: refuses while impersonating, requires the
     * operator to type "RESTORE", and audit-logs the outcome.
     */
    public static function restore( \WP_REST_Request $r ) {
        $err = ImpersonationContext::denyIfImpersonating( 'backup.restore' );
        if ( $err instanceof \WP_Error ) {
            return RestResponse::error( 'impersonation_blocks_destructive', (string) $err->get_error_message(), 403 );
        }

        $confirm = trim( (string) ( $r->get_param( 'confirm_text' ) ?? '' ) );
        if ( $confirm !== 'RESTORE' ) {
            return RestResponse::error(
                'confirm_required',
                __( 'Type RESTORE to confirm before any data is replaced.', 'talenttrack' ),
                422
            );
        }

        $id    = self::backupId( $r );
        $local = new LocalDestination();
        $path  = $local->fetchLocalPath( $id );
        if ( $path === '' ) {
            return RestResponse::notFound( 'backup_not_found', __( 'Backup not found.', 'talenttrack' ) );
        }

        $result = BackupRestorer::restore( $path );
        $ok     = ! empty( $result['ok'] );

        ( new AuditService() )->record( 'backup.restored', 'backup', 0, [
            'id'       => $id,
            'ok'       => $ok,
            'restored' => $result['restored'] ?? [],
            'error'    => $ok ? '' : (string) ( $result['error'] ?? '' ),
        ] );
        Logger::info( 'rest.backup.restored', [ 'user' => get_current_user_id(), 'ok' => $ok ] );

        if ( ! $ok ) {
            return RestResponse::error(
                'restore_failed',
                (string) ( $result['error'] ?? __( 'The restore could not be completed.', 'talenttrack' ) ),
                500
            );
        }
        return RestResponse::success( [ 'ok' => true, 'restored' => $result['restored'] ?? [] ] );
    }

    // Migration import (upload → preview → dry-run → commit)

    /**
     * Accept a `.ttmig` multipart upload, validate + summarise read-only,
     * and stage the archive so dry-run + commit can reload it without a
     * re-upload. Size-guarded; never writes academy data.
     */
    public static function migrationPreview( \WP_REST_Request $r ): \WP_REST_Response {
        $files = $r->get_file_params();
        $file  = $files['migration_file'] ?? null;

        $err  = is_array( $file ) ? (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) : UPLOAD_ERR_NO_FILE;
        $tmp  = is_array( $file ) ? (string) ( $file['tmp_name'] ?? '' ) : '';
        $size = is_array( $file ) ? (int) ( $file['size'] ?? 0 ) : 0;

        if ( $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $size > MigrationImporter::MAX_UPLOAD_BYTES ) {
            return RestResponse::error(
                'file_too_large',
                sprintf(
                    /* translators: %s is a human-readable file size, e.g. "25 MB". */
                    __( 'The file is larger than the %s upload limit. Importing larger datasets is a later phase.', 'talenttrack' ),
                    size_format( MigrationImporter::MAX_UPLOAD_BYTES )
                ),
                413
            );
        }
        if ( $err !== UPLOAD_ERR_OK || $tmp === '' || ! is_uploaded_file( $tmp ) ) {
            return RestResponse::error(
                'no_file',
                __( 'No file was uploaded. Choose a .ttmig archive and try again.', 'talenttrack' ),
                422
            );
        }

        $bytes      = (string) file_get_contents( $tmp );
        $snapshot   = BackupSerializer::fromGzippedJson( $bytes );
        $validation = MigrationImporter::validate( $snapshot );

        if ( empty( $validation['ok'] ) ) {
            return RestResponse::error(
                'invalid_archive',
                (string) ( $validation['error'] ?? __( 'The migration archive could not be read.', 'talenttrack' ) ),
                422
            );
        }

        self::stageUpload( $bytes );
        $snap = is_array( $snapshot ) ? $snapshot : [];

        Logger::info( 'rest.backup.migration_preview', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [
            'created_at'     => (string) ( $validation['created_at'] ?? '' ),
            'plugin_version' => (string) ( $validation['plugin_version'] ?? '' ),
            'warnings'       => array_values( array_map( 'strval', (array) ( $validation['warnings'] ?? [] ) ) ),
            'summary'        => self::summaryRows( $snap ),
            'conflicts'      => self::conflictRows( $snap ),
            'importable'     => self::importableRows( $snap ),
            'user_refs'      => self::userRefRows( $snap ),
        ] );
    }

    public static function migrationDryRun( \WP_REST_Request $r ): \WP_REST_Response {
        $snapshot = self::loadStaged();
        if ( $snapshot === null ) {
            return RestResponse::error( 'staging_lost', self::stagingLostMsg(), 410 );
        }
        $opts            = self::readImportOpts( $r );
        $opts['dry_run'] = true;
        $result          = MigrationImporter::commit( $snapshot, $opts );
        return self::importResultResponse( $result, false );
    }

    /**
     * DESTRUCTIVE — write the staged import into this install. Preserves
     * the wp-admin gates: refuses while impersonating, requires the
     * operator to type "IMPORT", and audit-logs the outcome. The staged
     * archive is cleared on success.
     */
    public static function migrationCommit( \WP_REST_Request $r ): \WP_REST_Response {
        $err = ImpersonationContext::denyIfImpersonating( 'migration.import' );
        if ( $err instanceof \WP_Error ) {
            return RestResponse::error( 'impersonation_blocks_destructive', (string) $err->get_error_message(), 403 );
        }

        $confirm = trim( (string) ( $r->get_param( 'confirm_text' ) ?? '' ) );
        if ( $confirm !== 'IMPORT' ) {
            return RestResponse::error(
                'confirm_required',
                __( 'Type IMPORT to confirm before the data is written.', 'talenttrack' ),
                422
            );
        }

        $snapshot = self::loadStaged();
        if ( $snapshot === null ) {
            return RestResponse::error( 'staging_lost', self::stagingLostMsg(), 410 );
        }

        $opts            = self::readImportOpts( $r );
        $opts['dry_run'] = false;
        $result          = MigrationImporter::commit( $snapshot, $opts );
        $ok              = ! empty( $result['ok'] );

        if ( $ok ) {
            self::clearStaged();
        }

        ( new AuditService() )->record( 'migration.imported', 'backup', 0, [
            'ok'       => $ok,
            'entities' => $opts['entities'] ?? [],
            'tables'   => $result['tables'] ?? [],
            'error'    => $ok ? '' : (string) ( $result['error'] ?? '' ),
        ] );
        Logger::info( 'rest.backup.migration_commit', [ 'user' => get_current_user_id(), 'ok' => $ok ] );

        return self::importResultResponse( $result, true );
    }

    // Helpers

    private static function backupId( \WP_REST_Request $r ): string {
        // The route pattern already restricts to [A-Za-z0-9._-]; basename
        // in LocalDestination::fetchLocalPath rejects any residual traversal.
        return sanitize_file_name( (string) $r['id'] );
    }

    private static function downloadUrl( string $id ): string {
        // The binary download stays on the wp-admin admin-post stream (the
        // single binary chokepoint). Returns a full URL — a future
        // object-storage backend swaps this for a signed S3/R2 URL without
        // changing the JSON shape (SaaS §4).
        return wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tt_backup_download', 'id' => $id ],
                admin_url( 'admin-post.php' )
            ),
            'tt_backup_download',
            'tt_backup_nonce'
        );
    }

    /**
     * @return array{entities:string[], conflict:array<string,string>, user_map:array<int,int>}
     */
    private static function readImportOpts( \WP_REST_Request $r ): array {
        $entities = $r->get_param( 'entities' );
        $entities = is_array( $entities ) ? array_map( 'sanitize_key', $entities ) : [];

        $conflict = [];
        $craw     = $r->get_param( 'conflict' );
        if ( is_array( $craw ) ) {
            foreach ( $craw as $k => $v ) {
                $conflict[ sanitize_key( (string) $k ) ] = (string) $v === 'update' ? 'update' : 'insert';
            }
        }

        $user_map = [];
        $uraw     = $r->get_param( 'user_map' );
        if ( is_array( $uraw ) ) {
            foreach ( $uraw as $src => $tgt ) {
                $user_map[ (int) $src ] = (int) $tgt;
            }
        }

        return [ 'entities' => $entities, 'conflict' => $conflict, 'user_map' => $user_map ];
    }

    /**
     * @param array{ok:bool, error?:string, tables?:array<string,array{insert:int,update:int,skip:int}>, warnings?:string[]} $result
     */
    private static function importResultResponse( array $result, bool $committed ): \WP_REST_Response {
        if ( empty( $result['ok'] ) ) {
            return RestResponse::error(
                'import_failed',
                (string) ( $result['error'] ?? __( 'The import could not be completed.', 'talenttrack' ) ),
                422
            );
        }
        $tables = [];
        foreach ( (array) ( $result['tables'] ?? [] ) as $tbl => $c ) {
            $tables[] = [
                'table'  => (string) $tbl,
                'insert' => (int) ( $c['insert'] ?? 0 ),
                'update' => (int) ( $c['update'] ?? 0 ),
                'skip'   => (int) ( $c['skip'] ?? 0 ),
            ];
        }
        return RestResponse::success( [
            'committed' => $committed,
            'tables'    => $tables,
            'warnings'  => array_values( array_map( 'strval', (array) ( $result['warnings'] ?? [] ) ) ),
        ] );
    }

    /** @param array<string,mixed> $snapshot @return array<int,array{label:string,total:int}> */
    private static function summaryRows( array $snapshot ): array {
        $out = [];
        foreach ( MigrationImporter::summarize( $snapshot ) as $row ) {
            $out[] = [ 'label' => (string) $row['label'], 'total' => (int) $row['total'] ];
        }
        return $out;
    }

    /** @param array<string,mixed> $snapshot @return array<int,array<string,mixed>> */
    private static function conflictRows( array $snapshot ): array {
        $out = [];
        foreach ( MigrationImporter::analyzeConflicts( $snapshot ) as $entity => $row ) {
            $out[] = [
                'entity'    => (string) $entity,
                'label'     => (string) $row['label'],
                'incoming'  => (int) $row['incoming'],
                'conflicts' => (int) $row['conflicts'],
                'new'       => (int) $row['new'],
                'key'       => (string) $row['key'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $snapshot @return array<int,array{key:string,label:string,total:int}> */
    private static function importableRows( array $snapshot ): array {
        $summary = MigrationImporter::summarize( $snapshot );
        $groups  = MigrationExporter::entityGroups();
        $out     = [];
        foreach ( MigrationImporter::importableGroupKeys() as $key ) {
            if ( ! isset( $summary[ $key ] ) ) continue;
            $out[] = [
                'key'   => (string) $key,
                'label' => (string) ( $groups[ $key ]['label'] ?? $key ),
                'total' => (int) $summary[ $key ]['total'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $snapshot @return array<int,array{source_id:int,hint:string,suggested_user_id:int}> */
    private static function userRefRows( array $snapshot ): array {
        $out = [];
        foreach ( MigrationImporter::userReferences( $snapshot ) as $ref ) {
            $out[] = [
                'source_id'         => (int) $ref['source_id'],
                'hint'              => (string) $ref['hint'],
                'suggested_user_id' => (int) $ref['suggested_user_id'],
            ];
        }
        return $out;
    }

    // Migration upload staging (one slot per operator) — mirrors the
    // wp-admin BackupSettingsPage staging: stored in the system temp dir,
    // outside the webroot, never directly fetchable.

    private static function stagedFile(): string {
        $dir = get_temp_dir();
        if ( $dir === '' ) return '';
        return trailingslashit( $dir ) . 'tt-migration-stage-' . get_current_user_id() . '.ttmig';
    }

    private static function stageUpload( string $bytes ): void {
        $path = self::stagedFile();
        if ( $path !== '' ) {
            file_put_contents( $path, $bytes );
        }
    }

    /** @return array<string,mixed>|null */
    private static function loadStaged(): ?array {
        $path = self::stagedFile();
        if ( $path === '' || ! is_readable( $path ) ) return null;
        $bytes = (string) file_get_contents( $path );
        return BackupSerializer::fromGzippedJson( $bytes );
    }

    private static function clearStaged(): void {
        $path = self::stagedFile();
        if ( $path !== '' && file_exists( $path ) ) {
            unlink( $path );
        }
    }

    private static function stagingLostMsg(): string {
        return __( 'The uploaded migration archive could not be found. Please upload it again.', 'talenttrack' );
    }
}
