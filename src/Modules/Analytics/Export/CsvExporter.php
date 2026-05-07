<?php
namespace TT\Modules\Analytics\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\KpiRegistry;

/**
 * CsvExporter — render a KPI / fact-query result as a UTF-8 CSV
 * (#0083 Child 6).
 *
 * Two entry points:
 *   - `forKpi($kpi_key, $extra_filters)` — uses the KPI's fact +
 *     measure + default filters (plus extras), grouped by every
 *     `exploreDimension` so the export carries the full breakdown.
 *   - `raw($fact_key, $dims, $measures, $filters)` — bypasses the
 *     KPI metadata for the explorer's "export this view" affordance
 *     where the caller already has the dim + measure list.
 *
 * Output is UTF-8 with a BOM so Excel-NL opens it correctly without
 * a manual encoding pick. Headers come from each Dimension /
 * Measure label (translatable). Streaming via `fputcsv` to a memory
 * stream — fine for the 5,000-row LIMIT the engine enforces;
 * larger exports defer to the async pipeline (Child 6 follow-up).
 */
final class CsvExporter {

    /**
     * Build a CSV for `$kpi_key` with optional extra filters merged
     * onto the KPI's defaults. Returns the CSV string (caller writes
     * it to a download response or attaches to email).
     *
     * @param array<string,mixed> $extra_filters
     */
    public static function forKpi( string $kpi_key, array $extra_filters = [] ): string {
        $kpi = KpiRegistry::find( $kpi_key );
        if ( $kpi === null ) return '';

        $fact = FactRegistry::find( $kpi->factKey );
        if ( $fact === null ) return '';

        $filters = array_merge( $kpi->defaultFilters, $extra_filters );
        return self::raw( $kpi->factKey, $kpi->exploreDimensions, [ $kpi->measureKey ], $filters, $kpi->label );
    }

    /**
     * Build a CSV for `$fact_key` with the named dimensions and
     * measures. Used by the explorer's "Export CSV" affordance.
     *
     * @param string[]            $dim_keys
     * @param string[]            $measure_keys
     * @param array<string,mixed> $filters
     */
    public static function raw( string $fact_key, array $dim_keys, array $measure_keys, array $filters = [], string $title = '' ): string {
        $fact = FactRegistry::find( $fact_key );
        if ( $fact === null ) return '';

        $rows = FactQuery::run( $fact_key, $dim_keys, $measure_keys, $filters );

        $fp = fopen( 'php://temp', 'r+' );
        if ( $fp === false ) return '';

        // Excel-NL friendly UTF-8 BOM.
        fwrite( $fp, "\xEF\xBB\xBF" );

        // Optional title row above the headers.
        if ( $title !== '' ) {
            fputcsv( $fp, [ $title ] );
            fputcsv( $fp, [ self::generatedAt() ] );
            fputcsv( $fp, [] );
        }

        // Header row — dimension labels then measure labels.
        $headers = [];
        foreach ( $dim_keys as $dk ) {
            $dim = $fact->dimension( $dk );
            $headers[] = $dim ? $dim->label : $dk;
        }
        foreach ( $measure_keys as $mk ) {
            $m = $fact->measure( $mk );
            $headers[] = $m ? $m->label : $mk;
        }
        fputcsv( $fp, $headers );

        foreach ( $rows as $row ) {
            $line = [];
            foreach ( $dim_keys as $dk ) {
                $line[] = (string) ( $row->{ $dk } ?? '' );
            }
            foreach ( $measure_keys as $mk ) {
                $val = $row->{ $mk } ?? '';
                if ( is_float( $val ) || ( is_string( $val ) && is_numeric( $val ) && strpos( $val, '.' ) !== false ) ) {
                    $line[] = number_format( (float) $val, 2, '.', '' );
                } else {
                    $line[] = (string) $val;
                }
            }
            fputcsv( $fp, $line );
        }

        rewind( $fp );
        $out = (string) stream_get_contents( $fp );
        fclose( $fp );
        return $out;
    }

    private static function generatedAt(): string {
        /* translators: %s ISO-8601 datetime */
        return sprintf( __( 'Generated %s UTC', 'talenttrack' ), gmdate( 'Y-m-d H:i:s' ) );
    }
}
