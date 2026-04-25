<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PartialRestorer — execute the user's chosen actions against the
 * live database for a closure produced by PartialRestoreScope and
 * classified by DiffComputer.
 *
 * Per-table actions chosen by the user:
 *   - **green** rows  → 'restore' or 'skip'
 *   - **yellow** rows → 'overwrite', 'skip', or 'keep-current'
 *
 * (RED rows are out of scope for v1 — see DiffComputer comment.)
 *
 * Tables are processed in BackupDependencyMap::restoreOrder() so
 * parents land before children and foreign-key-like checks pass.
 *
 * Wraps writes in a transaction where MySQL allows. TRUNCATE is NOT
 * used — partial restore touches only the rows in scope. Other rows
 * stay untouched.
 */
class PartialRestorer {

    public const ACTION_RESTORE      = 'restore';
    public const ACTION_OVERWRITE    = 'overwrite';
    public const ACTION_SKIP         = 'skip';
    public const ACTION_KEEP_CURRENT = 'keep-current';

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,array<int,int>> $closure
     * @param array<string,array{green:string, yellow:string}> $actions  table => action map
     *
     * @return array{ok:bool, error?:string, applied:array<string,array{inserted:int, updated:int, skipped:int}>}
     */
    public static function execute( array $snapshot, array $closure, array $actions ): array {
        if ( ! isset( $snapshot['tables'] ) || ! is_array( $snapshot['tables'] ) ) {
            return [ 'ok' => false, 'error' => 'Snapshot has no tables', 'applied' => [] ];
        }
        $tables = $snapshot['tables'];

        $diff = DiffComputer::compute( $tables, $closure );

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        $applied = [];
        $ordered = BackupDependencyMap::restoreOrder( array_keys( $closure ) );

        foreach ( $ordered as $tbl ) {
            $physical = $wpdb->prefix . $tbl;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $physical ) ) !== $physical ) {
                continue;
            }
            $rows = is_array( $tables[ $tbl ]['rows'] ?? null ) ? $tables[ $tbl ]['rows'] : [];
            $columns = is_array( $tables[ $tbl ]['columns'] ?? null ) ? $tables[ $tbl ]['columns'] : [];
            $snap_by_id = [];
            foreach ( $rows as $r ) {
                if ( is_array( $r ) && isset( $r['id'] ) ) $snap_by_id[ (int) $r['id'] ] = $r;
            }

            $ids   = array_map( 'intval', $closure[ $tbl ] ?? [] );
            $green_action  = $actions[ $tbl ]['green']  ?? self::ACTION_RESTORE;
            $yellow_action = $actions[ $tbl ]['yellow'] ?? self::ACTION_KEEP_CURRENT;

            // Fetch live rows for the same ids in one query.
            $live_by_id = [];
            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $live = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$physical} WHERE id IN ({$placeholders})", ...$ids ),
                    ARRAY_A
                );
                if ( is_array( $live ) ) {
                    foreach ( $live as $r ) {
                        if ( isset( $r['id'] ) ) $live_by_id[ (int) $r['id'] ] = $r;
                    }
                }
            }

            $inserted = 0; $updated = 0; $skipped = 0;
            foreach ( $ids as $id ) {
                $snap = $snap_by_id[ $id ] ?? null;
                $live = $live_by_id[ $id ] ?? null;

                if ( $snap === null ) { $skipped++; continue; }

                if ( $live === null ) {
                    // Green: in snapshot, not in DB.
                    if ( $green_action === self::ACTION_RESTORE ) {
                        $insert = self::projectColumns( $snap, $columns );
                        $ok = $wpdb->insert( $physical, $insert );
                        if ( $ok !== false ) $inserted++; else $skipped++;
                    } else {
                        $skipped++;
                    }
                } else {
                    // Yellow: both — only act if they differ.
                    $a = self::stringifyRow( $snap );
                    $b = self::stringifyRow( $live );
                    if ( $a === $b ) { $skipped++; continue; }
                    if ( $yellow_action === self::ACTION_OVERWRITE ) {
                        $update = self::projectColumns( $snap, $columns );
                        unset( $update['id'] );
                        $ok = $wpdb->update( $physical, $update, [ 'id' => $id ] );
                        if ( $ok !== false ) $updated++; else $skipped++;
                    } else {
                        $skipped++;
                    }
                }
            }

            $applied[ $tbl ] = [ 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped ];
        }

        $wpdb->query( 'COMMIT' );

        return [ 'ok' => true, 'applied' => $applied ];
    }

    /**
     * @param array<string,mixed> $row
     * @param string[]            $columns
     * @return array<string,mixed>
     */
    private static function projectColumns( array $row, array $columns ): array {
        $out = [];
        foreach ( $columns as $col ) {
            $col = (string) $col;
            $out[ $col ] = $row[ $col ] ?? null;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,?string>
     */
    private static function stringifyRow( array $row ): array {
        $out = [];
        foreach ( $row as $k => $v ) $out[ (string) $k ] = $v === null ? null : (string) $v;
        return $out;
    }
}
