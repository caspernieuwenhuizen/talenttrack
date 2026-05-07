<?php
namespace TT\Modules\Export\Format\Renderers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\Format\FormatRendererInterface;

/**
 * XlsxRenderer (#0063) — multi-sheet `.xlsx` output via PhpSpreadsheet.
 *
 * Use cases: 6 (multi-sheet evaluations export — one tab per evaluation
 * cycle), 15 (round-tripped demo-data export), and any future use case
 * that benefits from native Excel structure (formulas, multiple tabs,
 * column types) over plain CSV.
 *
 * Payload shape — one of two forms:
 *
 *   1. Single-sheet: `[ headers, rows ]` (mirrors `CsvRenderer`'s
 *      contract; the renderer puts both into a sheet named "Data").
 *   2. Multi-sheet: `[ 'sheets' => [ 'Sheet name' => [ headers, rows ],
 *      'Other sheet' => [ headers, rows ], ... ] ]`. Sheet names are
 *      truncated to Excel's 31-char limit and stripped of forbidden
 *      characters (`[]:*?\/`).
 *
 * Self-gates on PhpSpreadsheet — composer ships it as a production
 * dependency (composer.json `require`), so on the release pipeline this
 * always loads. The class_exists check exists for older installs that
 * haven't run `composer install` yet (dev environments) and for the
 * vanishingly-unlikely case the autoloader fails.
 */
final class XlsxRenderer implements FormatRendererInterface {

    public function format(): string { return 'xlsx'; }

    public function render( ExportRequest $request, $payload ): ExportResult {
        if ( ! class_exists( \PhpOffice\PhpSpreadsheet\Spreadsheet::class ) ) {
            throw new ExportException(
                'no_renderer',
                'PhpSpreadsheet not available — composer install needed'
            );
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $sheets = self::resolveSheets( $payload );

        $first = true;
        foreach ( $sheets as $name => [ $headers, $rows ] ) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;
            $sheet->setTitle( self::cleanSheetName( $name ) );

            $col = 1;
            foreach ( $headers as $h ) {
                $sheet->setCellValueByColumnAndRow( $col++, 1, (string) $h );
            }

            $r = 2;
            foreach ( $rows as $row ) {
                $col = 1;
                foreach ( $row as $cell ) {
                    $sheet->setCellValueByColumnAndRow( $col++, $r, $cell );
                }
                $r++;
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );

        $tmp = tempnam( sys_get_temp_dir(), 'tt-xlsx-' );
        if ( $tmp === false ) {
            throw new ExportException( 'no_renderer', 'Could not create temp file for xlsx' );
        }
        $writer->save( $tmp );
        $bytes = (string) file_get_contents( $tmp );
        @unlink( $tmp );

        $filename = $request->exporterKey . '-' . gmdate( 'Y-m-d' ) . '.xlsx';
        return ExportResult::fromString(
            $bytes,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $filename
        );
    }

    /**
     * @return array<string,array{0:array<int,string>,1:array<int,array<int,mixed>>}>
     */
    private static function resolveSheets( $payload ): array {
        if ( is_array( $payload ) && isset( $payload['sheets'] ) && is_array( $payload['sheets'] ) ) {
            $out = [];
            foreach ( $payload['sheets'] as $name => $entry ) {
                if ( ! is_array( $entry ) || count( $entry ) < 2 ) continue;
                $out[ (string) $name ] = [
                    array_values( (array) $entry[0] ),
                    array_values( (array) $entry[1] ),
                ];
            }
            if ( $out !== [] ) return $out;
        }
        // Fall back to CSV-shape `[ headers, rows ]`.
        if ( is_array( $payload ) && count( $payload ) >= 2 ) {
            return [
                'Data' => [
                    array_values( (array) $payload[0] ),
                    array_values( (array) $payload[1] ),
                ],
            ];
        }
        return [ 'Data' => [ [], [] ] ];
    }

    private static function cleanSheetName( string $raw ): string {
        // Excel limits: 31 chars, no `[ ] : * ? / \`. Strip and truncate.
        $clean = preg_replace( '/[\[\]:\*\?\/\\\\]/', '', $raw );
        $clean = $clean === null || $clean === '' ? 'Sheet' : $clean;
        return mb_substr( $clean, 0, 31 );
    }
}
