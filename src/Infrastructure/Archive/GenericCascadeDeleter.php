<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * GenericCascadeDeleter (#1783) — referential-integrity-checked hard
 * delete driven by a CascadeRegistry plan.
 *
 * Fail-closed: before deleting anything it scans every tt_* table that
 * references the entity (via the plan's ref_columns). References the plan
 * declares as owned are cascaded; references declared as outliving facts
 * are set to NULL (nullable columns) or reset to 0 (NOT NULL DEFAULT 0
 * orphan columns — see `set_zero`); ANY OTHER reference BLOCKS the delete
 * with a dependency report (DeleteBlockedException). The whole operation
 * runs in one transaction and is club-scoped on the final delete,
 * mirroring PlayerDeletionCascade / PersonDeletionCascade.
 */
final class GenericCascadeDeleter {

    /**
     * Read-only dependency scan + classification for the confirm dialog.
     *
     * @param int[] $ids
     * @return array{
     *   removals: list<array{table:string,count:int}>,
     *   nullifications: list<array{table:string,column:string,count:int}>,
     *   zeroings: list<array{table:string,column:string,count:int}>,
     *   blockers: array<string,int>
     * }
     */
    public function preview( string $entity, array $ids ): array {
        $plan = CascadeRegistry::plan( $entity );
        $ids  = $this->cleanIds( $ids );
        if ( $plan === null || empty( $ids ) ) {
            return [ 'removals' => [], 'nullifications' => [], 'zeroings' => [], 'blockers' => [] ];
        }

        $removals = [];
        $nulls    = [];
        $zeros    = [];
        $blockers = [];

        // Declared owned children discovered by the ref-column scan.
        foreach ( $this->scanReferences( $plan, $ids ) as $ref ) {
            $key = $ref['table'] . '.' . $ref['column'];
            if ( $ref['count'] <= 0 ) continue;
            if ( $this->isCascade( $plan, $ref['table'], $ref['column'] ) ) {
                $removals[] = [ 'table' => $ref['table'], 'count' => $ref['count'] ];
            } elseif ( $this->isSetNull( $plan, $ref['table'], $ref['column'] ) ) {
                $nulls[] = [ 'table' => $ref['table'], 'column' => $ref['column'], 'count' => $ref['count'] ];
            } elseif ( $this->isSetZero( $plan, $ref['table'], $ref['column'] ) ) {
                $zeros[] = [ 'table' => $ref['table'], 'column' => $ref['column'], 'count' => $ref['count'] ];
            } else {
                $blockers[ $ref['table'] ] = ( $blockers[ $ref['table'] ] ?? 0 ) + $ref['count'];
            }
        }

        // Parent-keyed, polymorphic + thread children (not visible to the
        // ref-column scan).
        foreach ( (array) ( $plan['children'] ?? [] ) as [ $child, $cfk, $parent, $ppk, $pref ] ) {
            $n = $this->countChild( $child, $cfk, $parent, $ppk, $pref, $ids );
            if ( $n > 0 ) $removals[] = [ 'table' => $child, 'count' => $n ];
        }
        foreach ( (array) ( $plan['cascade_poly'] ?? [] ) as [ $table, $type_col, $id_col, $type_val ] ) {
            $n = $this->countPoly( $table, $type_col, $id_col, $type_val, $ids );
            if ( $n > 0 ) $removals[] = [ 'table' => $table, 'count' => $n ];
        }
        if ( ! empty( $plan['threads'] ) ) {
            $n = $this->countThreads( (string) $plan['threads'], $ids );
            if ( $n > 0 ) $removals[] = [ 'table' => 'tt_thread_messages', 'count' => $n ];
        }

        return [ 'removals' => $removals, 'nullifications' => $nulls, 'zeroings' => $zeros, 'blockers' => $blockers ];
    }

    /**
     * Execute the plan. Throws DeleteBlockedException (no writes) if any
     * undeclared reference exists.
     *
     * @param int[] $ids
     * @return array{deleted:int, per_table:array<string,int>, nulled:array<string,int>, zeroed:array<string,int>}
     */
    public function cascade( string $entity, array $ids ): array {
        $plan = CascadeRegistry::plan( $entity );
        $ids  = $this->cleanIds( $ids );
        if ( $plan === null || empty( $ids ) ) {
            return [ 'deleted' => 0, 'per_table' => [], 'nulled' => [], 'zeroed' => [] ];
        }

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();
        $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // 1) Fail-closed dependency check BEFORE any write.
        $refs     = $this->scanReferences( $plan, $ids );
        $blockers = [];
        foreach ( $refs as $ref ) {
            if ( $ref['count'] <= 0 ) continue;
            if ( $this->isCascade( $plan, $ref['table'], $ref['column'] ) ) continue;
            if ( $this->isSetNull( $plan, $ref['table'], $ref['column'] ) ) continue;
            if ( $this->isSetZero( $plan, $ref['table'], $ref['column'] ) ) continue;
            $blockers[ $ref['table'] ] = ( $blockers[ $ref['table'] ] ?? 0 ) + $ref['count'];
        }
        if ( ! empty( $blockers ) ) {
            Logger::info( 'delete.blocked', [ 'entity' => $entity, 'ids' => $ids, 'blockers' => $blockers ] );
            throw new DeleteBlockedException( $blockers );
        }

        $per_table = [];
        $nulled    = [];
        $zeroed    = [];

        $wpdb->query( 'START TRANSACTION' );
        try {
            // 2) Polymorphic owned children.
            foreach ( (array) ( $plan['cascade_poly'] ?? [] ) as [ $table, $type_col, $id_col, $type_val ] ) {
                if ( ! $this->tableExists( $table ) ) continue;
                $sql = "DELETE FROM {$p}{$table} WHERE {$type_col} = %s AND {$id_col} IN ({$ph})";
                $n   = $wpdb->query( $wpdb->prepare( $sql, ...array_merge( [ $type_val ], $ids ) ) );
                $this->guard( $n, $table );
                if ( (int) $n > 0 ) $per_table[ $table ] = (int) $n;
            }

            // 3) Thread messages + reads owned by this entity.
            if ( ! empty( $plan['threads'] ) ) {
                $type_val = (string) $plan['threads'];
                foreach ( [ 'tt_thread_messages', 'tt_thread_reads' ] as $tt ) {
                    if ( ! $this->tableExists( $tt ) ) continue;
                    $sql = "DELETE FROM {$p}{$tt} WHERE thread_type = %s AND thread_id IN ({$ph})";
                    $n   = $wpdb->query( $wpdb->prepare( $sql, ...array_merge( [ $type_val ], $ids ) ) );
                    $this->guard( $n, $tt );
                    if ( (int) $n > 0 ) $per_table[ $tt ] = (int) $n;
                }
            }

            // 3b) Parent-keyed children — rows that hang off a child table
            // (no direct ref column of their own), removed before that
            // parent is deleted in step 4. e.g. tournament assignments live
            // under matches: delete them where the match belongs to the
            // tournament being removed.
            foreach ( (array) ( $plan['children'] ?? [] ) as [ $child, $cfk, $parent, $ppk, $pref ] ) {
                if ( ! $this->tableExists( $child ) || ! $this->tableExists( $parent ) ) continue;
                $sql = "DELETE c FROM {$p}{$child} c
                         INNER JOIN {$p}{$parent} pa ON pa.{$ppk} = c.{$cfk}
                         WHERE pa.{$pref} IN ({$ph})";
                $n   = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                $this->guard( $n, $child );
                if ( (int) $n > 0 ) $per_table[ $child ] = ( $per_table[ $child ] ?? 0 ) + (int) $n;
            }

            // 4) Declared direct owned children.
            foreach ( (array) ( $plan['cascade'] ?? [] ) as [ $table, $col ] ) {
                if ( ! $this->tableExists( $table ) ) continue;
                $sql = "DELETE FROM {$p}{$table} WHERE {$col} IN ({$ph})";
                $n   = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                $this->guard( $n, $table );
                if ( (int) $n > 0 ) $per_table[ $table ] = ( $per_table[ $table ] ?? 0 ) + (int) $n;
            }

            // 5) Set-null the outliving references.
            foreach ( (array) ( $plan['set_null'] ?? [] ) as [ $table, $col ] ) {
                if ( ! $this->tableExists( $table ) ) continue;
                $sql = "UPDATE {$p}{$table} SET {$col} = NULL WHERE {$col} IN ({$ph})";
                $n   = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                $this->guard( $n, $table );
                if ( (int) $n > 0 ) $nulled[ "{$table}.{$col}" ] = (int) $n;
            }

            // 5b) Set-zero the orphan references — `NOT NULL DEFAULT 0`
            // columns that can't take NULL but should not block or
            // cascade. The referencing row outlives the entity, re-homed
            // to the 0 sentinel (unassigned / club-level). e.g. a player's
            // team_id resets to 0 when their team is deleted, so the player
            // survives as unassigned rather than being deleted or stranded.
            foreach ( (array) ( $plan['set_zero'] ?? [] ) as [ $table, $col ] ) {
                if ( ! $this->tableExists( $table ) ) continue;
                $sql = "UPDATE {$p}{$table} SET {$col} = 0 WHERE {$col} IN ({$ph})";
                $n   = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                $this->guard( $n, $table );
                if ( (int) $n > 0 ) $zeroed[ "{$table}.{$col}" ] = (int) $n;
            }

            // 6) The entity rows themselves — club-scoped.
            $entity_table = (string) $plan['table'];
            $sql_final = "DELETE FROM {$p}{$entity_table} WHERE id IN ({$ph}) AND club_id = %d";
            $deleted = $wpdb->query( $wpdb->prepare( $sql_final, ...array_merge( $ids, [ $club ] ) ) );
            $this->guard( $deleted, $entity_table );

            $wpdb->query( 'COMMIT' );

            Logger::info( 'entity.deleted_with_cascade', [
                'entity'    => $entity,
                'ids'       => $ids,
                'club_id'   => $club,
                'deleted'   => (int) $deleted,
                'per_table' => $per_table,
                'nulled'    => $nulled,
                'zeroed'    => $zeroed,
                'by_user'   => get_current_user_id(),
            ] );

            return [ 'deleted' => (int) $deleted, 'per_table' => $per_table, 'nulled' => $nulled, 'zeroed' => $zeroed ];
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            Logger::error( 'entity.cascade.failed', [
                'entity' => $entity, 'ids' => $ids, 'error' => $e->getMessage(),
            ] );
            throw $e;
        }
    }

    /**
     * Every (table, column, count) referencing the entity via the plan's
     * ref_columns. Excludes the entity's own table.
     *
     * @param array<string,mixed> $plan
     * @param int[] $ids
     * @return list<array{table:string, column:string, count:int}>
     */
    private function scanReferences( array $plan, array $ids ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $ref_columns = (array) ( $plan['ref_columns'] ?? [] );
        if ( empty( $ref_columns ) ) return [];

        // A ref_columns entry is either a bare column name (string) — the
        // scan discovers every tt_* table carrying it — or a qualified
        // [table, column] pair, scanned on that table only. Use qualified
        // entries when the column name is ambiguous across entities (e.g.
        // `exercise_id` exists on both tt_exercises' and tt_vct_*' children).
        $bare_cols = [];
        $pairs     = []; // list<array{table:string, column:string}>
        foreach ( $ref_columns as $entry ) {
            if ( is_array( $entry ) ) {
                $pairs[] = [ 'table' => (string) $entry[0], 'column' => (string) $entry[1] ];
            } else {
                $bare_cols[] = (string) $entry;
            }
        }

        $found = []; // list<array{bare:string, col:string}>
        if ( ! empty( $bare_cols ) ) {
            $pattern = str_replace( '_', '\\_', $p ) . 'tt\\_%';
            $col_ph  = implode( ',', array_fill( 0, count( $bare_cols ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME LIKE %s
                    AND COLUMN_NAME IN ({$col_ph})",
                ...array_merge( [ $pattern ], $bare_cols )
            ) );
            foreach ( (array) $rows as $row ) {
                $found[] = [ 'bare' => substr( (string) $row->t, strlen( $p ) ), 'col' => (string) $row->c ];
            }
        }
        foreach ( $pairs as $pair ) {
            if ( $this->tableExists( $pair['table'] ) ) {
                $found[] = [ 'bare' => $pair['table'], 'col' => $pair['column'] ];
            }
        }

        $own = (string) $plan['table'];
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $out = [];
        foreach ( $found as $ref ) {
            $bare = $ref['bare'];
            $col  = $ref['col'];
            if ( $bare === $own && $col === 'id' ) continue; // never the PK
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}{$bare} WHERE {$col} IN ({$ph})",
                ...$ids
            ) );
            $out[] = [ 'table' => $bare, 'column' => $col, 'count' => $n ];
        }
        return $out;
    }

    private function countChild( string $child, string $cfk, string $parent, string $ppk, string $pref, array $ids ): int {
        global $wpdb;
        if ( ! $this->tableExists( $child ) || ! $this->tableExists( $parent ) ) return 0;
        $p  = $wpdb->prefix;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}{$child} c
              INNER JOIN {$p}{$parent} pa ON pa.{$ppk} = c.{$cfk}
              WHERE pa.{$pref} IN ({$ph})",
            ...$ids
        ) );
    }

    private function countPoly( string $table, string $type_col, string $id_col, string $type_val, array $ids ): int {
        global $wpdb;
        if ( ! $this->tableExists( $table ) ) return 0;
        $p  = $wpdb->prefix;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}{$table} WHERE {$type_col} = %s AND {$id_col} IN ({$ph})",
            ...array_merge( [ $type_val ], $ids )
        ) );
    }

    private function countThreads( string $type_val, array $ids ): int {
        global $wpdb;
        if ( ! $this->tableExists( 'tt_thread_messages' ) ) return 0;
        $p  = $wpdb->prefix;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_thread_messages WHERE thread_type = %s AND thread_id IN ({$ph})",
            ...array_merge( [ $type_val ], $ids )
        ) );
    }

    private function isCascade( array $plan, string $table, string $col ): bool {
        foreach ( (array) ( $plan['cascade'] ?? [] ) as [ $t, $c ] ) {
            if ( $t === $table && $c === $col ) return true;
        }
        return false;
    }

    private function isSetNull( array $plan, string $table, string $col ): bool {
        foreach ( (array) ( $plan['set_null'] ?? [] ) as [ $t, $c ] ) {
            if ( $t === $table && $c === $col ) return true;
        }
        return false;
    }

    private function isSetZero( array $plan, string $table, string $col ): bool {
        foreach ( (array) ( $plan['set_zero'] ?? [] ) as [ $t, $c ] ) {
            if ( $t === $table && $c === $col ) return true;
        }
        return false;
    }

    private function guard( $result, string $table ): void {
        global $wpdb;
        if ( $result === false ) {
            throw new \RuntimeException( "Cascade query failed on {$table}: " . $wpdb->last_error );
        }
    }

    private function tableExists( string $bare_table ): bool {
        global $wpdb;
        $table = $wpdb->prefix . $bare_table;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * @param int[] $raw
     * @return int[]
     */
    private function cleanIds( array $raw ): array {
        $out = [];
        foreach ( $raw as $v ) {
            $i = (int) $v;
            if ( $i > 0 ) $out[ $i ] = true;
        }
        return array_keys( $out );
    }
}
