<?php
namespace TT\Modules\Export\Format\Renderers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;
use TT\Modules\Export\Format\FormatRendererInterface;

/**
 * CsvRenderer (#0063) — RFC 4180 CSV output via native `fputcsv`.
 *
 * Payload shape:
 *   [ 'headers' => list<string>, 'rows' => list<list<scalar>> ]
 *
 * The exporter is responsible for serialising values to scalars
 * (dates, booleans, decimals — all stringified by the exporter using
 * the request's locale). The renderer just writes them out.
 *
 * Streaming-friendly via `fputcsv` on a `php://temp` stream so very
 * large row sets don't OOM on shared hosting (the dominant deployment
 * target). For now the buffer is materialised into bytes; an async
 * runner (Action Scheduler) handles the truly multi-thousand-row case
 * — that lands with the per-use-case migration when needed.
 *
 * BOM is prepended so Excel opens UTF-8 CSVs with the right encoding
 * on first try (well-known Excel-on-Windows pain point).
 */
final class CsvRenderer implements FormatRendererInterface {

    public function format(): string { return 'csv'; }

    public function render( ExportRequest $request, $payload ): ExportResult {
        $headers = isset( $payload['headers'] ) && is_array( $payload['headers'] ) ? $payload['headers'] : [];
        $rows    = isset( $payload['rows'] )    && is_array( $payload['rows'] )    ? $payload['rows']    : [];

        $fp = fopen( 'php://temp', 'r+' );
        if ( $fp === false ) {
            return ExportResult::fromString( '', 'text/csv; charset=utf-8', $request->exporterKey . '.csv' );
        }

        // UTF-8 BOM — Excel-on-Windows opens correctly.
        fwrite( $fp, "\xEF\xBB\xBF" );

        if ( ! empty( $headers ) ) {
            fputcsv( $fp, array_map( 'strval', $headers ) );
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $stringed = [];
            foreach ( $row as $cell ) {
                if ( is_bool( $cell ) ) {
                    $stringed[] = $cell ? '1' : '0';
                } elseif ( $cell === null ) {
                    $stringed[] = '';
                } else {
                    $stringed[] = (string) $cell;
                }
            }
            fputcsv( $fp, $stringed );
        }

        rewind( $fp );
        $bytes = (string) stream_get_contents( $fp );
        fclose( $fp );

        $filename = $request->exporterKey . '-' . gmdate( 'Y-m-d' ) . '.csv';
        $note     = sprintf( '%d row%s', count( $rows ), count( $rows ) === 1 ? '' : 's' );
        return ExportResult::fromString( $bytes, 'text/csv; charset=utf-8', $filename, $note );
    }
}
