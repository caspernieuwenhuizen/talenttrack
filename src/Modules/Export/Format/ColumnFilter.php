<?php
namespace TT\Modules\Export\Format;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ColumnFilter (#986) — apply the request's column selection to a
 * tabular payload before it hits the CSV / XLSX renderer.
 *
 * The exporter declares its known columns via
 * `ExporterInterface::availableColumns()` — an ordered
 * `column_key => label` map whose position in the map matches the
 * position of the column in the payload's `headers` / `rows`. When
 * the user opens the picker on the Exports page and deselects some
 * columns, the POST carries the *kept* column keys; this helper
 * drops the unselected positions from headers and from every row.
 *
 * Tail columns beyond the static set declared by the exporter
 * (e.g. dynamic evaluation main-category columns in
 * `PlayerEvaluationsCsvExporter`) always pass through unchanged.
 * The picker only governs what the exporter declared statically.
 *
 * Contract:
 *   - `$availableColumns` is the map returned by the exporter.
 *   - `$selectedKeys` is the list of column_keys the user kept
 *     (or `null` / empty when the picker was untouched / not
 *     rendered — in which case the payload is returned as-is).
 *   - `$payload['headers']` / `$payload['rows']` are filtered
 *     positionally; sheet-shaped payloads are not filtered (the
 *     picker is only rendered for single-sheet tabular exporters).
 */
final class ColumnFilter {

    /**
     * @param array<string,mixed>           $payload          headers/rows or sheets shape
     * @param array<string,string>          $availableColumns column_key => label, ordered
     * @param array<int,string>|null        $selectedKeys     kept column_keys, or null
     * @return array<string,mixed>
     */
    public static function apply( array $payload, array $availableColumns, ?array $selectedKeys ): array {
        // No declared columns → exporter opts out of the picker entirely.
        if ( $availableColumns === [] ) {
            return $payload;
        }
        // No selection provided → keep everything (default behaviour).
        if ( $selectedKeys === null || $selectedKeys === [] ) {
            return $payload;
        }
        // Multi-sheet payloads are out of scope for the picker.
        if ( isset( $payload['sheets'] ) ) {
            return $payload;
        }
        if ( ! isset( $payload['headers'] ) || ! isset( $payload['rows'] ) ) {
            return $payload;
        }

        $keys     = array_keys( $availableColumns );
        $declared = count( $keys );
        $keep_set = array_flip( array_map( 'strval', $selectedKeys ) );

        // If every declared column is kept, nothing to do.
        $all_kept = true;
        foreach ( $keys as $k ) {
            if ( ! isset( $keep_set[ $k ] ) ) { $all_kept = false; break; }
        }
        if ( $all_kept ) {
            return $payload;
        }

        // Indexes to keep within the declared range, in declared order.
        $keep_idx = [];
        foreach ( $keys as $i => $k ) {
            if ( isset( $keep_set[ $k ] ) ) $keep_idx[] = $i;
        }

        $headers      = array_values( (array) $payload['headers'] );
        $rows         = (array) $payload['rows'];
        $total_cols   = count( $headers );
        $tail_indexes = [];
        for ( $i = $declared; $i < $total_cols; $i++ ) {
            $tail_indexes[] = $i;
        }
        $final_indexes = array_merge( $keep_idx, $tail_indexes );

        $new_headers = [];
        foreach ( $final_indexes as $i ) {
            if ( array_key_exists( $i, $headers ) ) {
                $new_headers[] = $headers[ $i ];
            }
        }

        $new_rows = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $row = array_values( $row );
            $out = [];
            foreach ( $final_indexes as $i ) {
                $out[] = array_key_exists( $i, $row ) ? $row[ $i ] : '';
            }
            $new_rows[] = $out;
        }

        $payload['headers'] = $new_headers;
        $payload['rows']    = $new_rows;
        return $payload;
    }

    /**
     * Sanitise + validate a list of column keys against the exporter's
     * declared map. Returns the list of keys actually present in the
     * map, in the map's declared order (so a renderer never has to
     * reason about user-supplied ordering). Returns `null` when the
     * input is not a list or contains no recognised keys — caller
     * should treat that as "no filter applied".
     *
     * @param mixed                $raw  the raw POST value
     * @param array<string,string> $availableColumns
     * @return array<int,string>|null
     */
    public static function sanitiseSelection( $raw, array $availableColumns ): ?array {
        if ( ! is_array( $raw ) ) return null;
        $valid = [];
        foreach ( $raw as $k ) {
            if ( is_scalar( $k ) ) $valid[ (string) $k ] = true;
        }
        if ( $valid === [] ) return null;
        $out = [];
        foreach ( array_keys( $availableColumns ) as $key ) {
            if ( isset( $valid[ $key ] ) ) $out[] = $key;
        }
        return $out === [] ? null : $out;
    }
}
