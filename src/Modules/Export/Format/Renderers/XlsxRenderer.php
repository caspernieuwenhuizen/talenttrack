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
 * Payload shape — one of three forms:
 *
 *   1. **Single-sheet** (`[ headers, rows ]`) — mirrors `CsvRenderer`'s
 *      contract; the renderer puts both into a sheet named "Data".
 *   2. **Multi-sheet** (`[ 'sheets' => [ 'Sheet name' => [ headers, rows ],
 *      'Other sheet' => [ headers, rows ], ... ] ]`) — sheet names are
 *      truncated to Excel's 31-char limit and stripped of forbidden
 *      characters (`[]:*?\/`).
 *   3. **Styled sheets** (#1269) — `[ 'styled_sheets' => [ 'Name' => [
 *      'rows' => list<list<cell|array>>, 'merges' => list<string>,
 *      'col_widths' => array<string,float>, 'styles' => array<string,array> ] ] ]`.
 *      Each cell is either a scalar value (rendered raw) or an array
 *      `[ 'v' => <value>, 'style' => '<style-key>' ]` referencing a
 *      named style from the sheet's `styles` map. The style map supports
 *      `font.bold`, `fill.color` (hex without `#`), `borders.all`
 *      (color + style), and `alignment.{horizontal,vertical,wrap}`.
 *      `merges` is a list of A1-style range strings (e.g. `'A1:G1'`).
 *      `col_widths` keys are Excel column letters; values are widths in
 *      character units (≈ pixels / 7). An optional `freeze` key (an
 *      A1-style cell reference, e.g. `'A3'`) freezes every row above it
 *      so a header block + column-header row stay pinned on scroll.
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

        // #1269 — styled-sheets path takes precedence when the payload
        // declares it; renderer walks rows + applies named styles +
        // merges + column widths. Falls through to the flat-table path
        // for legacy payloads (every existing exporter is flat-table).
        if ( self::isStyledPayload( $payload ) ) {
            self::renderStyledSheets( $spreadsheet, (array) $payload['styled_sheets'] );
        } else {
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
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
        // Charts are opt-in on the Xlsx writer; a styled sheet may attach one
        // (e.g. the measurement trends line chart, #2194). Harmless when no
        // sheet declares a chart.
        $writer->setIncludeCharts( true );

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

    private static function isStyledPayload( $payload ): bool {
        return is_array( $payload )
            && isset( $payload['styled_sheets'] )
            && is_array( $payload['styled_sheets'] )
            && ! empty( $payload['styled_sheets'] );
    }

    /**
     * #1269 — render a styled-sheets payload. Each sheet declares its
     * own rows (with optional per-cell style refs), merges, column
     * widths, and a name-keyed style map. Style map values mirror the
     * shape PhpSpreadsheet's `Style::applyFromArray` accepts so the
     * mapping is one-to-one.
     *
     * @param array<string, array<string,mixed>> $sheets
     */
    private static function renderStyledSheets( \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $sheets ): void {
        $first = true;
        foreach ( $sheets as $name => $sheet_spec ) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;
            $sheet->setTitle( self::cleanSheetName( (string) $name ) );

            $styles      = is_array( $sheet_spec['styles']     ?? null ) ? $sheet_spec['styles']     : [];
            $col_widths  = is_array( $sheet_spec['col_widths'] ?? null ) ? $sheet_spec['col_widths'] : [];
            $merges      = is_array( $sheet_spec['merges']     ?? null ) ? $sheet_spec['merges']     : [];
            $rows        = is_array( $sheet_spec['rows']       ?? null ) ? $sheet_spec['rows']       : [];

            // Pre-resolve every style key into a PhpSpreadsheet-ready
            // array exactly once per sheet — applying the same style to
            // 200 cells should NOT re-allocate the style array 200x.
            $resolved_styles = [];
            foreach ( $styles as $style_key => $style_spec ) {
                $resolved_styles[ (string) $style_key ] = self::toPhpSpreadsheetStyle( (array) $style_spec );
            }

            $r = 1;
            foreach ( $rows as $row ) {
                $col = 1;
                if ( ! is_array( $row ) ) { $r++; continue; }
                foreach ( $row as $cell ) {
                    if ( is_array( $cell ) ) {
                        $value     = $cell['v']     ?? '';
                        $style_key = isset( $cell['style'] ) ? (string) $cell['style'] : '';
                    } else {
                        $value     = $cell;
                        $style_key = '';
                    }
                    $sheet->setCellValueByColumnAndRow( $col, $r, $value );
                    if ( $style_key !== '' && isset( $resolved_styles[ $style_key ] ) ) {
                        $cell_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col ) . $r;
                        $sheet->getStyle( $cell_coord )->applyFromArray( $resolved_styles[ $style_key ] );
                    }
                    $col++;
                }
                $r++;
            }

            foreach ( $merges as $range ) {
                $range = (string) $range;
                if ( $range === '' ) continue;
                $sheet->mergeCells( $range );
            }

            foreach ( $col_widths as $col_letter => $width ) {
                $sheet->getColumnDimension( (string) $col_letter )->setWidth( (float) $width );
            }

            $freeze = isset( $sheet_spec['freeze'] ) ? (string) $sheet_spec['freeze'] : '';
            if ( $freeze !== '' ) {
                $sheet->freezePane( $freeze );
            }

            // #2194 — an optional line chart bound to a rectangular value grid
            // on this sheet. Each data row becomes a plotted series over the
            // shared category (date) axis.
            if ( isset( $sheet_spec['chart'] ) && is_array( $sheet_spec['chart'] ) ) {
                self::attachChart( $sheet, (string) $name, $sheet_spec['chart'] );
            }
        }
    }

    /**
     * #2194 — build and attach a line chart to a sheet from a payload chart
     * spec. The spec references cells by column letter + row so the exporter
     * stays free of PhpSpreadsheet imports (the vocabulary lives in the
     * payload, mirroring `toPhpSpreadsheetStyle`).
     *
     * Spec keys: `type` (only `line` today), `title`, `categories_row` with
     * `categories_from`/`categories_to` (the date header cells), one series
     * per data row `series_first_row`..`series_last_row` taking its name from
     * `series_name_col` and its values across `values_from_col`..`values_to_col`,
     * an `anchor` cell for placement, and optional axis titles.
     *
     * @param array<string, mixed> $chart
     */
    private static function attachChart( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $sheet_name, array $chart ): void {
        $type = (string) ( $chart['type'] ?? 'line' );
        if ( $type !== 'line' ) {
            return; // only line charts are supported today
        }

        $first_row = (int) ( $chart['series_first_row'] ?? 0 );
        $last_row  = (int) ( $chart['series_last_row'] ?? 0 );
        if ( $first_row <= 0 || $last_row < $first_row ) {
            return;
        }

        $q = "'" . str_replace( "'", "''", $sheet_name ) . "'"; // quoted sheet ref

        $cat_row  = (int) ( $chart['categories_row'] ?? 0 );
        $cat_from = (string) ( $chart['categories_from'] ?? 'B' );
        $cat_to   = (string) ( $chart['categories_to'] ?? $cat_from );
        $val_from = (string) ( $chart['values_from_col'] ?? 'B' );
        $val_to   = (string) ( $chart['values_to_col'] ?? $val_from );
        $name_col = (string) ( $chart['series_name_col'] ?? 'A' );

        $categories = [
            new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues::DATASERIES_TYPE_STRING,
                $q . '!$' . $cat_from . '$' . $cat_row . ':$' . $cat_to . '$' . $cat_row,
                null,
                null
            ),
        ];

        $series_values = [];
        $series_labels = [];
        $series_order  = [];
        $i = 0;
        for ( $row = $first_row; $row <= $last_row; $row++ ) {
            $series_values[] = new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues::DATASERIES_TYPE_NUMBER,
                $q . '!$' . $val_from . '$' . $row . ':$' . $val_to . '$' . $row,
                null,
                null
            );
            $series_labels[] = new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues::DATASERIES_TYPE_STRING,
                $q . '!$' . $name_col . '$' . $row,
                null,
                1
            );
            $series_order[] = $i++;
        }

        if ( $series_values === [] ) {
            return;
        }

        $data_series = new \PhpOffice\PhpSpreadsheet\Chart\DataSeries(
            \PhpOffice\PhpSpreadsheet\Chart\DataSeries::TYPE_LINECHART,
            \PhpOffice\PhpSpreadsheet\Chart\DataSeries::GROUPING_STANDARD,
            $series_order,
            $series_labels,
            $categories,
            $series_values
        );

        $plot_area = new \PhpOffice\PhpSpreadsheet\Chart\PlotArea( null, [ $data_series ] );
        $legend    = new \PhpOffice\PhpSpreadsheet\Chart\Legend(
            \PhpOffice\PhpSpreadsheet\Chart\Legend::POSITION_RIGHT,
            null,
            false
        );

        $title    = isset( $chart['title'] ) ? new \PhpOffice\PhpSpreadsheet\Chart\Title( (string) $chart['title'] ) : null;
        $x_title  = isset( $chart['x_axis_title'] ) ? new \PhpOffice\PhpSpreadsheet\Chart\Title( (string) $chart['x_axis_title'] ) : null;
        $y_title  = isset( $chart['y_axis_title'] ) ? new \PhpOffice\PhpSpreadsheet\Chart\Title( (string) $chart['y_axis_title'] ) : null;

        $obj = new \PhpOffice\PhpSpreadsheet\Chart\Chart(
            'tt-trend-chart',
            $title,
            $legend,
            $plot_area,
            true,
            \PhpOffice\PhpSpreadsheet\Chart\DataSeries::EMPTY_AS_GAP,
            $x_title,
            $y_title
        );

        $anchor = (string) ( $chart['anchor'] ?? 'A' . ( $last_row + 2 ) );
        $obj->setTopLeftPosition( $anchor );
        // A wide, readable plot below the pivot table.
        $obj->setBottomRightPosition( self::offsetAnchor( $anchor, 9, 16 ) );

        $sheet->addChart( $obj );
    }

    /**
     * Shift an A1 anchor by a number of columns + rows, for the chart's
     * bottom-right corner. Keeps the geometry off the exporter.
     */
    private static function offsetAnchor( string $anchor, int $cols, int $rows ): string {
        if ( ! preg_match( '/^([A-Z]+)(\d+)$/', $anchor, $m ) ) {
            return $anchor;
        }
        $col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $m[1] );
        $new_col   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col_index + $cols );
        $new_row   = (int) $m[2] + $rows;
        return $new_col . $new_row;
    }

    /**
     * Translate the payload-side style spec into the PhpSpreadsheet
     * `applyFromArray` shape. Keeps the payload vocabulary stable
     * across PhpSpreadsheet upgrades — callers don't import the lib.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private static function toPhpSpreadsheetStyle( array $spec ): array {
        $out = [];

        if ( isset( $spec['font'] ) && is_array( $spec['font'] ) ) {
            $font = [];
            if ( isset( $spec['font']['bold'] ) )  $font['bold']  = (bool) $spec['font']['bold'];
            if ( isset( $spec['font']['size'] ) )  $font['size']  = (int) $spec['font']['size'];
            if ( isset( $spec['font']['color'] ) ) $font['color'] = [ 'rgb' => (string) $spec['font']['color'] ];
            if ( $font !== [] ) $out['font'] = $font;
        }
        if ( isset( $spec['fill']['color'] ) ) {
            $out['fill'] = [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'color'    => [ 'rgb' => (string) $spec['fill']['color'] ],
            ];
        }
        if ( isset( $spec['borders']['all'] ) && is_array( $spec['borders']['all'] ) ) {
            $border_style = (string) ( $spec['borders']['all']['style'] ?? 'thin' );
            $border_color = (string) ( $spec['borders']['all']['color'] ?? '000000' );
            $out['borders'] = [
                'allBorders' => [
                    'borderStyle' => $border_style,
                    'color'       => [ 'rgb' => $border_color ],
                ],
            ];
        }
        if ( isset( $spec['alignment'] ) && is_array( $spec['alignment'] ) ) {
            $align = [];
            if ( isset( $spec['alignment']['horizontal'] ) ) $align['horizontal'] = (string) $spec['alignment']['horizontal'];
            if ( isset( $spec['alignment']['vertical'] ) )   $align['vertical']   = (string) $spec['alignment']['vertical'];
            if ( isset( $spec['alignment']['wrap'] ) )       $align['wrapText']   = (bool) $spec['alignment']['wrap'];
            if ( $align !== [] ) $out['alignment'] = $align;
        }
        return $out;
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
        // CSV-shape payload — `[ 'headers' => list<string>, 'rows' => list<list> ]`
        // is the assoc form returned by `PlayersListCsvExporter` /
        // `AttendanceRegisterCsvExporter` / `GoalsCsvExporter` (#864).
        // Treat as a single-sheet workbook.
        if ( is_array( $payload ) && isset( $payload['headers'], $payload['rows'] ) ) {
            return [
                'Data' => [
                    array_values( (array) $payload['headers'] ),
                    array_values( (array) $payload['rows'] ),
                ],
            ];
        }
        // Fall back to numeric-indexed shape `[ headers, rows ]`.
        if ( is_array( $payload ) && count( $payload ) >= 2 && isset( $payload[0], $payload[1] ) ) {
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
