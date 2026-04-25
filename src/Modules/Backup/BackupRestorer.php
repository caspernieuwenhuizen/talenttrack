<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackupRestorer — replay a snapshot back into the live `tt_*` tables.
 *
 * Two-step flow used by the UI:
 *
 *   1. preview( $path )  — reads the snapshot, validates checksum +
 *                           plugin-version compatibility, and returns
 *                           a per-table summary the UI shows before
 *                           the user confirms.
 *
 *   2. restore( $path )  — actually executes: TRUNCATE then INSERT
 *                           for every table in the snapshot. Wraps in
 *                           a transaction where InnoDB allows.
 *
 * Cross-major-version policy: a snapshot from a different major plugin
 * version is rejected outright. Same-major OK; the schema migrator is
 * trusted to keep within-major changes additive.
 */
class BackupRestorer {

    /**
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   plugin_version?:string,
     *   created_at?:string,
     *   preset?:string,
     *   summary?:array<string,int>
     * }
     */
    public static function preview( string $path ): array {
        if ( ! is_readable( $path ) ) {
            return [ 'ok' => false, 'error' => __( 'Backup file not readable.', 'talenttrack' ) ];
        }
        $bytes = (string) file_get_contents( $path );
        $snapshot = BackupSerializer::fromGzippedJson( $bytes );
        if ( $snapshot === null ) {
            return [ 'ok' => false, 'error' => __( 'Backup file is not a valid TalentTrack snapshot.', 'talenttrack' ) ];
        }
        if ( ! BackupSerializer::verifyChecksum( $snapshot ) ) {
            return [ 'ok' => false, 'error' => __( 'Backup checksum failed verification.', 'talenttrack' ) ];
        }

        $compat = self::checkPluginVersion( (string) ( $snapshot['plugin_version'] ?? '' ) );
        if ( ! $compat['ok'] ) {
            return [ 'ok' => false, 'error' => $compat['error'] ];
        }

        $summary = [];
        foreach ( (array) ( $snapshot['tables'] ?? [] ) as $table => $payload ) {
            $rows = is_array( $payload['rows'] ?? null ) ? $payload['rows'] : [];
            $summary[ (string) $table ] = count( $rows );
        }

        return [
            'ok'             => true,
            'plugin_version' => (string) ( $snapshot['plugin_version'] ?? '' ),
            'created_at'     => (string) ( $snapshot['created_at']     ?? '' ),
            'preset'         => (string) ( $snapshot['preset']         ?? '' ),
            'summary'        => $summary,
        ];
    }

    /**
     * @return array{ok:bool, error?:string, restored:array<string,int>}
     */
    public static function restore( string $path ): array {
        $preview = self::preview( $path );
        if ( empty( $preview['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $preview['error'] ?? 'Unknown error' ), 'restored' => [] ];
        }

        $bytes = (string) file_get_contents( $path );
        $snapshot = BackupSerializer::fromGzippedJson( $bytes );
        if ( ! is_array( $snapshot ) || ! isset( $snapshot['tables'] ) || ! is_array( $snapshot['tables'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Backup file is not valid.', 'talenttrack' ), 'restored' => [] ];
        }

        global $wpdb;
        $restored = [];

        // Best-effort transaction. MySQL DDL (TRUNCATE) auto-commits, so
        // the transaction wrapping is mostly cosmetic; we still try, in
        // case the server happens to support it for the path we hit.
        $wpdb->query( 'START TRANSACTION' );

        foreach ( $snapshot['tables'] as $bare => $payload ) {
            $bare    = (string) $bare;
            $columns = is_array( $payload['columns'] ?? null ) ? $payload['columns'] : [];
            $rows    = is_array( $payload['rows']    ?? null ) ? $payload['rows']    : [];

            $table = $wpdb->prefix . $bare;
            // Skip tables that don't exist on this install (older or
            // optional). Snapshot may be wider than the live schema.
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                continue;
            }

            $wpdb->query( "TRUNCATE TABLE {$table}" );

            $count = 0;
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) continue;
                $insert = [];
                foreach ( $columns as $col ) {
                    $insert[ (string) $col ] = $row[ (string) $col ] ?? null;
                }
                $ok = $wpdb->insert( $table, $insert );
                if ( $ok !== false ) $count++;
            }
            $restored[ $bare ] = $count;
        }

        $wpdb->query( 'COMMIT' );

        // Post-restore: count match check.
        $expected = $preview['summary'] ?? [];
        $mismatches = [];
        foreach ( $expected as $bare => $exp ) {
            $actual = $restored[ $bare ] ?? 0;
            if ( (int) $exp !== (int) $actual ) {
                $mismatches[] = sprintf( '%s: %d expected, %d restored', $bare, $exp, $actual );
            }
        }

        if ( ! empty( $mismatches ) ) {
            return [
                'ok'       => false,
                'error'    => sprintf(
                    /* translators: %s is comma-separated list of "table: expected vs actual" mismatches */
                    __( 'Restore completed but row counts do not match: %s', 'talenttrack' ),
                    implode( ', ', $mismatches )
                ),
                'restored' => $restored,
            ];
        }

        return [ 'ok' => true, 'restored' => $restored ];
    }

    /**
     * Compare snapshot's plugin_version to the running version. We
     * compare by major component only — same-major restores are fine,
     * cross-major are blocked.
     *
     * @return array{ok:bool, error?:string}
     */
    private static function checkPluginVersion( string $snapshot_version ): array {
        $running = defined( 'TT_VERSION' ) ? (string) TT_VERSION : '';
        if ( $snapshot_version === '' || $running === '' ) {
            return [ 'ok' => true ]; // missing data — don't block on it
        }
        $snap_major = (int) explode( '.', $snapshot_version )[0];
        $run_major  = (int) explode( '.', $running )[0];
        if ( $snap_major !== $run_major ) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    /* translators: 1: snapshot version, 2: running version */
                    __( 'Cannot restore: snapshot is from version %1$s, this site runs %2$s. Cross-major-version restores are not supported.', 'talenttrack' ),
                    $snapshot_version,
                    $running
                ),
            ];
        }
        return [ 'ok' => true ];
    }
}
