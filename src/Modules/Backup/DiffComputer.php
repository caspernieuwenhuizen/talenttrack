<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DiffComputer — compares snapshot rows-in-scope against the current
 * database state and classifies each row.
 *
 * Per-row classification:
 *   - **green**:  in snapshot, not currently in DB. Will be inserted.
 *   - **yellow**: in both, but values differ. User picks the action
 *                 (keep-current / overwrite / skip).
 *   - **red**:    in DB, not in snapshot. User picks (leave / delete).
 *
 * Output is a per-table summary of counts plus optional per-row
 * detail (capped to keep the UI responsive — the full row list is
 * available in the snapshot itself if the user wants to inspect it).
 */
class DiffComputer {

    public const GREEN  = 'green';
    public const YELLOW = 'yellow';
    public const RED    = 'red';

    /**
     * @param array<string, array{columns:array<int,string>, rows:array<int,array<string,mixed>>}> $snapshot_tables
     * @param array<string, array<int,int>> $closure  table => [ids]
     *
     * @return array<string, array{
     *   green:int,
     *   yellow:int,
     *   red:int,
     *   sample:array<int,array{id:int, status:string, snapshot?:array<string,mixed>, current?:array<string,mixed>}>
     * }>
     */
    public static function compute( array $snapshot_tables, array $closure ): array {
        global $wpdb;
        $out = [];

        foreach ( $closure as $tbl => $ids ) {
            $tbl  = (string) $tbl;
            $ids  = array_values( array_map( 'intval', (array) $ids ) );
            $rows = is_array( $snapshot_tables[ $tbl ]['rows'] ?? null ) ? $snapshot_tables[ $tbl ]['rows'] : [];

            // Index snapshot rows by id for O(1) lookup.
            $snap_by_id = [];
            foreach ( $rows as $r ) {
                if ( is_array( $r ) && isset( $r['id'] ) ) {
                    $snap_by_id[ (int) $r['id'] ] = $r;
                }
            }

            // Fetch matching live rows in one query.
            $live_by_id = [];
            $physical   = $wpdb->prefix . $tbl;
            if ( ! empty( $ids ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $physical ) ) === $physical ) {
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

            $green = 0; $yellow = 0; $red = 0;
            $sample = [];
            foreach ( $ids as $id ) {
                $snap = $snap_by_id[ $id ] ?? null;
                $live = $live_by_id[ $id ] ?? null;

                if ( $snap !== null && $live === null ) {
                    $green++;
                    if ( count( $sample ) < 5 ) $sample[] = [ 'id' => $id, 'status' => self::GREEN, 'snapshot' => $snap ];
                } elseif ( $snap !== null && $live !== null ) {
                    if ( self::rowsEqual( $snap, $live ) ) {
                        // Matches — not interesting; keep out of the
                        // colored counts entirely.
                    } else {
                        $yellow++;
                        if ( count( $sample ) < 5 ) $sample[] = [ 'id' => $id, 'status' => self::YELLOW, 'snapshot' => $snap, 'current' => $live ];
                    }
                }
            }

            // RED rows: in live, not in snapshot, but only matter if the
            // table is in the closure. We compute them by scanning live
            // for rows that match the closure's "scope intent" — for
            // this v1 we approximate by reporting 0 (a red diff requires
            // a richer scope definition than just "these IDs").
            // The settings UI today restores into a closure of IDs, so
            // RED is meaningful only when the user explicitly picks
            // "scope = all rows of table X" — that path isn't in v1
            // and defaults to leaving live red rows alone.
            $red = 0;

            $out[ $tbl ] = [
                'green'  => $green,
                'yellow' => $yellow,
                'red'    => $red,
                'sample' => $sample,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function rowsEqual( array $a, array $b ): bool {
        // Cast everything to string so we don't trip over int-vs-string
        // mismatches between DB driver returns and JSON-decoded payloads.
        $na = []; $nb = [];
        foreach ( $a as $k => $v ) $na[ (string) $k ] = $v === null ? null : (string) $v;
        foreach ( $b as $k => $v ) $nb[ (string) $k ] = $v === null ? null : (string) $v;
        return $na === $nb;
    }
}
