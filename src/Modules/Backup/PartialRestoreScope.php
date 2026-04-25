<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PartialRestoreScope — given a starting set of records and a snapshot,
 * computes the closure of records that should travel with them.
 *
 * Two directions of walk:
 *
 *   - **Up** (parents): mandatory. If we restore a player, we must
 *     also include their team if it isn't already in the closure;
 *     otherwise the foreign-key-like reference is dangling.
 *
 *   - **Down** (children): opt-in. Restoring a player can OPTIONALLY
 *     include their evaluations, sessions attended, goals, etc. The
 *     caller declares which child types to include (via the
 *     $include_children list).
 *
 * The closure is returned as a per-table list of row IDs. Diff
 * computation in DiffComputer reads from those IDs.
 *
 * The walk operates against the SNAPSHOT (not the live database) —
 * the goal is "from this backup, give me these rows plus the rows
 * they reference." Live-database state isn't relevant until the diff
 * step compares closure-rows against current-rows.
 */
class PartialRestoreScope {

    /**
     * Compute the closure starting from $starting.
     *
     * @param array<string,array{columns:array<int,string>,rows:array<int,array<string,mixed>>}> $tables  Snapshot tables
     * @param array<string, array<int,int>> $starting  table => [ids]  initial scope
     * @param string[] $include_children  Tables to follow downward (e.g. ['tt_evaluations','tt_eval_ratings'])
     *
     * @return array<string, array<int,int>>  table => [ids]  closure
     */
    public static function compute( array $tables, array $starting, array $include_children = [] ): array {
        $result = [];
        foreach ( $starting as $tbl => $ids ) {
            $tbl = (string) $tbl;
            $result[ $tbl ] = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
        }

        $refs        = BackupDependencyMap::refs();
        $inverse     = BackupDependencyMap::inverse();
        $child_set   = array_fill_keys( $include_children, true );

        // Index rows by id per table for O(1) lookups during walk.
        $by_id = [];
        foreach ( $tables as $tbl => $payload ) {
            $rows = is_array( $payload['rows'] ?? null ) ? $payload['rows'] : [];
            $idx  = [];
            foreach ( $rows as $r ) {
                if ( ! is_array( $r ) || ! isset( $r['id'] ) ) continue;
                $idx[ (int) $r['id'] ] = $r;
            }
            $by_id[ (string) $tbl ] = $idx;
        }

        // BFS — each iteration either expands an entry or terminates.
        $changed = true;
        $guard   = 0;
        while ( $changed && $guard < 50 ) {
            $changed = false;
            $guard++;

            // Up-walk: pull missing parents for every row in the closure.
            foreach ( $result as $tbl => $ids ) {
                $tbl_refs = $refs[ $tbl ] ?? [];
                if ( empty( $tbl_refs ) ) continue;
                foreach ( $ids as $id ) {
                    $row = $by_id[ $tbl ][ (int) $id ] ?? null;
                    if ( ! $row ) continue;
                    foreach ( $tbl_refs as $r ) {
                        $col           = (string) $r['column'];
                        $parent_tbl    = (string) $r['parent_table'];
                        if ( ! isset( $by_id[ $parent_tbl ] ) ) continue; // parent not in snapshot
                        $parent_id     = isset( $row[ $col ] ) ? (int) $row[ $col ] : 0;
                        if ( $parent_id <= 0 ) continue;
                        $existing      = $result[ $parent_tbl ] ?? [];
                        if ( ! in_array( $parent_id, $existing, true ) ) {
                            $existing[]                 = $parent_id;
                            $result[ $parent_tbl ]      = $existing;
                            $changed                    = true;
                        }
                    }
                }
            }

            // Down-walk (opt-in): for each parent in the closure, add
            // any child rows from $include_children whose foreign key
            // points at it.
            foreach ( $result as $parent_tbl => $parent_ids ) {
                $children = $inverse[ $parent_tbl ] ?? [];
                if ( empty( $children ) ) continue;
                $parent_set = array_fill_keys( array_map( 'intval', $parent_ids ), true );
                foreach ( $children as $child_tbl => $columns ) {
                    if ( ! isset( $child_set[ $child_tbl ] ) ) continue;
                    if ( ! isset( $by_id[ $child_tbl ] ) ) continue;
                    foreach ( $by_id[ $child_tbl ] as $cid => $crow ) {
                        $hit = false;
                        foreach ( $columns as $col ) {
                            $val = isset( $crow[ $col ] ) ? (int) $crow[ $col ] : 0;
                            if ( $val > 0 && isset( $parent_set[ $val ] ) ) { $hit = true; break; }
                        }
                        if ( ! $hit ) continue;
                        $existing = $result[ $child_tbl ] ?? [];
                        if ( ! in_array( (int) $cid, $existing, true ) ) {
                            $existing[]              = (int) $cid;
                            $result[ $child_tbl ]    = $existing;
                            $changed                 = true;
                        }
                    }
                }
            }
        }

        // Sort each table's id list for deterministic output.
        foreach ( $result as $tbl => $ids ) {
            $sorted = array_values( array_unique( array_map( 'intval', $ids ) ) );
            sort( $sorted );
            $result[ $tbl ] = $sorted;
        }
        return $result;
    }
}
