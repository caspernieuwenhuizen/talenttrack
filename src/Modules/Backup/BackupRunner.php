<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\Destinations\BackupDestinationInterface;
use TT\Modules\Backup\Destinations\EmailDestination;
use TT\Modules\Backup\Destinations\LocalDestination;

/**
 * BackupRunner — orchestrates a single backup run.
 *
 * Resolves the table list from settings, snapshots them via
 * BackupSerializer, writes the gzipped JSON to a temp file, hands the
 * temp file to every enabled destination via store(), then cleans up
 * the temp file and updates the "last successful run" option.
 *
 * Returns a per-destination result so the UI can surface partial
 * failures (e.g. local OK, email failed).
 */
class BackupRunner {

    public const LAST_RUN_OPT = 'tt_backup_last_run';

    /**
     * @return array{
     *   ok:bool,
     *   filename:string,
     *   destinations:array<string,array{ok:bool, error?:string, meta?:array<string,mixed>}>
     * }
     */
    public static function run(): array {
        $settings   = BackupSettings::get();
        $tables     = BackupSettings::resolveTables();
        $preset     = $settings['preset'];

        if ( empty( $tables ) ) {
            self::recordRun( false, '', [], 'No tables selected for backup' );
            return [ 'ok' => false, 'filename' => '', 'destinations' => [] ];
        }

        $snapshot = BackupSerializer::snapshot( $tables, $preset );
        $bytes    = BackupSerializer::toGzippedJson( $snapshot );
        if ( $bytes === '' ) {
            self::recordRun( false, '', [], 'Compression failed' );
            return [ 'ok' => false, 'filename' => '', 'destinations' => [] ];
        }

        $filename = BackupSerializer::filename( $preset );
        $tmp_dir  = get_temp_dir();
        $tmp_path = trailingslashit( $tmp_dir ) . $filename;
        if ( @file_put_contents( $tmp_path, $bytes ) === false ) {
            self::recordRun( false, $filename, [], 'Could not write temp file' );
            return [ 'ok' => false, 'filename' => $filename, 'destinations' => [] ];
        }

        $metadata = [
            'created_at'     => $snapshot['created_at'],
            'plugin_version' => $snapshot['plugin_version'],
            'preset'         => $snapshot['preset'],
            'size'           => strlen( $bytes ),
            'checksum'       => $snapshot['checksum'],
        ];

        $results = [];
        $any_ok  = false;
        foreach ( self::destinations() as $dest ) {
            if ( ! $dest->isEnabled() ) continue;
            $r = $dest->store( $tmp_path, $metadata );
            $results[ $dest->key() ] = [
                'ok'    => $r->ok,
                'error' => $r->error,
                'meta'  => $r->meta,
            ];
            if ( $r->ok ) $any_ok = true;
        }

        // Clean up the temp file once all destinations are done.
        @unlink( $tmp_path );

        self::recordRun( $any_ok, $filename, $results, $any_ok ? '' : 'No destination accepted the backup' );

        return [
            'ok'           => $any_ok,
            'filename'     => $filename,
            'destinations' => $results,
        ];
    }

    /**
     * @return BackupDestinationInterface[]
     */
    public static function destinations(): array {
        return [
            new LocalDestination(),
            new EmailDestination(),
        ];
    }

    /**
     * @return array{ok:bool, at:int, filename:string, destinations:array<string,array<string,mixed>>, error:string}|null
     */
    public static function lastRun(): ?array {
        $raw = get_option( self::LAST_RUN_OPT, '' );
        if ( ! is_string( $raw ) || $raw === '' ) return null;
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $destinations
     */
    private static function recordRun( bool $ok, string $filename, array $destinations, string $error ): void {
        update_option(
            self::LAST_RUN_OPT,
            wp_json_encode( [
                'ok'           => $ok,
                'at'           => time(),
                'filename'     => $filename,
                'destinations' => $destinations,
                'error'        => $error,
            ] ),
            false
        );
    }
}
