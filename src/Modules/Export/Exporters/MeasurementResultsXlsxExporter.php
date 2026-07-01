<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;

/**
 * MeasurementResultsXlsxExporter (#2139, epic #2116) — a single test's
 * recorded results as a formatted Excel workbook.
 *
 * Triggered from the Manage-tests view by `definition_id`. Optional filters
 * narrow the cohort: a single `team_id` and an inclusive `date_from` /
 * `date_to` recorded-date window. Generalises to any test because it is keyed
 * by the definition, not bound to one page.
 *
 * One sheet, a styled header block (test name, unit or "status", date range,
 * club) over a frozen bold column-header row, then one row per result:
 * player, team, recorded date, value, age group, recorded by. Player-centric
 * (CLAUDE.md §1): every row is a dated value for one player, grouped so a
 * player's longitudinal series reads together.
 *
 * For a status-type test the value column shows the matched level's LABEL
 * (from the result's `value_text`) and fills the cell with that level's
 * colour. The colour is resolved level → `color_token` → hex through a small
 * token map sourced from `assets/css/frontend-measurement-levels.css`; an
 * Excel cell can't read the CSS token, so the hex lives here.
 *
 * Dispatched through the generic `ExportRestController` / admin-post export
 * pipeline — no new REST route. The precise gate is `measurements/read` via
 * `MatrixGate::canAnyScope()`, enforced in `collect()` (the export pipeline's
 * coarse `requiredCap()` gate can't express a matrix-scope question).
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/measurement_results_xlsx?format=xlsx`
 *   filters: `definition_id` (required), `team_id`, `date_from`, `date_to`.
 */
final class MeasurementResultsXlsxExporter implements ExporterInterface {

    /**
     * Token → Excel ARGB-less hex, mirroring the resolved values in
     * `assets/css/frontend-measurement-levels.css` (the `--tt-mlvl-*` vars).
     * Kept in lock-step with that sheet; an .xlsx cell can't read a CSS token.
     */
    private const TOKEN_HEX = [
        'green'  => '2F9E5E',
        'lime'   => '7CB342',
        'yellow' => 'F2B500',
        'amber'  => 'E8902B',
        'orange' => 'EF6C00',
        'red'    => 'D8453B',
        'cyan'   => '29ABE2',
        'blue'   => '2D6FB3',
        'grey'   => '6A6D66',
    ];

    public function key(): string { return 'measurement_results_xlsx'; }

    public function label(): string { return __( 'Test results export (XLSX)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'xlsx' ]; }

    /**
     * The export pipeline's coarse cap-gate runs against this string. The
     * measurements entity is matrix-only (no legacy cap maps to it), so the
     * authoritative gate is the `MatrixGate::canAnyScope( …, 'measurements',
     * 'read' )` check in `collect()`. Returning '' keeps the coarse gate a
     * no-op rather than referencing a capability that doesn't exist.
     */
    public function requiredCap(): string { return ''; }

    /**
     * Styled, single-purpose workbook — the column picker is single-sheet
     * tabular only and would let a user strip the player or value column, so
     * this exporter opts out by returning an empty map (#986).
     */
    public function availableColumns(): array {
        return [];
    }

    public function validateFilters( array $raw ): ?array {
        $definition_id = isset( $raw['definition_id'] ) ? (int) $raw['definition_id'] : 0;
        if ( $definition_id <= 0 ) {
            return null;
        }

        $filters = [ 'definition_id' => $definition_id ];

        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id > 0 ) {
            $filters['team_id'] = $team_id;
        }

        if ( isset( $raw['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw['date_from'] ) ) {
            $filters['date_from'] = (string) $raw['date_from'];
        }
        if ( isset( $raw['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw['date_to'] ) ) {
            $filters['date_to'] = (string) $raw['date_to'];
        }
        // Auto-swap a reversed range (mirrors the other date-windowed exporters).
        if ( isset( $filters['date_from'], $filters['date_to'] )
            && $filters['date_from'] > $filters['date_to'] ) {
            [ $filters['date_from'], $filters['date_to'] ] =
                [ $filters['date_to'], $filters['date_from'] ];
        }

        return $filters;
    }

    public function collect( ExportRequest $request ): array {
        // Authoritative permission gate — matrix scope, never role-string or
        // cookie presence (CLAUDE.md §4). Denial maps to a 403.
        if ( ! MatrixGate::canAnyScope( $request->requesterUserId, 'measurements', 'read' ) ) {
            throw new ExportException( 'forbidden', __( 'You do not have permission to export test results.', 'talenttrack' ) );
        }

        $definition_id = (int) ( $request->filters['definition_id'] ?? 0 );

        $def = ( new MeasurementDefinitionsRepository() )->find( $definition_id );
        if ( $def === null ) {
            // Definition gone / archived — a clean header-only workbook so the
            // download still succeeds rather than 500-ing.
            return $this->emptyWorkbook( __( 'Test', 'talenttrack' ) );
        }

        $value_type = (string) $def->value_type;

        // Status tests resolve the recorded label back to its current colour.
        $level_token = [];
        if ( $value_type === 'status' ) {
            foreach ( ( new MeasurementLevelsRepository() )->listForDefinition( $definition_id ) as $lvl ) {
                $level_token[ (string) $lvl->label ] = MeasurementLevelPalette::safe( (string) $lvl->color_token );
            }
        }

        $rows = ( new MeasurementResultsRepository() )->listForDefinitionExport(
            $definition_id,
            $request->filters
        );

        $club_name = (string) get_bloginfo( 'name' );
        $unit      = (string) ( $def->unit ?? '' );
        $date_from = (string) ( $request->filters['date_from'] ?? '' );
        $date_to   = (string) ( $request->filters['date_to'] ?? '' );

        $sheet_rows = [];
        $merges     = [];

        // ── header block ────────────────────────────────────────────────
        $sheet_rows[] = [ [ 'v' => (string) $def->name, 'style' => 'title' ] ];
        $merges[]     = 'A1:F1';

        $type_line = $value_type === 'status'
            ? __( 'Status (coloured levels)', 'talenttrack' )
            : ( $unit !== ''
                ? sprintf( /* translators: %s: unit of measure */ __( 'Unit: %s', 'talenttrack' ), $unit )
                : __( 'No unit', 'talenttrack' ) );
        $sheet_rows[] = [ [ 'v' => $type_line, 'style' => 'subtitle' ] ];
        $merges[]     = 'A2:F2';

        $range_line = ( $date_from !== '' || $date_to !== '' )
            ? sprintf(
                /* translators: 1: from date, 2: to date */
                __( 'Date range: %1$s to %2$s', 'talenttrack' ),
                $date_from !== '' ? $date_from : '—',
                $date_to !== '' ? $date_to : '—'
            )
            : __( 'Date range: all dates', 'talenttrack' );
        $sheet_rows[] = [ [ 'v' => $range_line, 'style' => 'subtitle' ] ];
        $merges[]     = 'A3:F3';

        if ( $club_name !== '' ) {
            $sheet_rows[] = [ [ 'v' => sprintf(
                /* translators: %s: club / academy name */
                __( 'Club: %s', 'talenttrack' ),
                $club_name
            ), 'style' => 'subtitle' ] ];
            $merges[] = 'A4:F4';
            $header_row_index = 6; // 4 header rows + 1 spacer + the column header
        } else {
            $header_row_index = 5;
        }

        // Spacer between the header block and the column-header row.
        $sheet_rows[] = [ '' ];

        // ── column header row ──────────────────────────────────────────
        $sheet_rows[] = [
            [ 'v' => __( 'Player', 'talenttrack' ),        'style' => 'th' ],
            [ 'v' => __( 'Team', 'talenttrack' ),          'style' => 'th' ],
            [ 'v' => __( 'Recorded date', 'talenttrack' ), 'style' => 'th' ],
            [ 'v' => __( 'Value', 'talenttrack' ),         'style' => 'th' ],
            [ 'v' => __( 'Age group', 'talenttrack' ),     'style' => 'th' ],
            [ 'v' => __( 'Recorded by', 'talenttrack' ),   'style' => 'th' ],
        ];

        // ── data rows ──────────────────────────────────────────────────
        foreach ( $rows as $r ) {
            $player = trim( (string) $r->first_name . ' ' . (string) $r->last_name );

            $value_cell = $this->valueCell( $value_type, $unit, $r, $level_token );

            $sheet_rows[] = [
                [ 'v' => $player, 'style' => 'td' ],
                [ 'v' => (string) ( $r->team_name ?? '' ), 'style' => 'td' ],
                [ 'v' => (string) $r->recorded_date, 'style' => 'td' ],
                $value_cell,
                [ 'v' => (string) ( $r->age_group ?? '' ), 'style' => 'td' ],
                [ 'v' => (string) ( $r->recorded_by_name ?? '' ), 'style' => 'td' ],
            ];
        }

        $sheets = [
            __( 'Results', 'talenttrack' ) => [
                'rows'       => $sheet_rows,
                'merges'     => $merges,
                'freeze'     => 'A' . $header_row_index,
                'col_widths' => [
                    'A' => 26, 'B' => 18, 'C' => 16,
                    'D' => 20, 'E' => 14, 'F' => 22,
                ],
                'styles'     => $this->styles( $level_token ),
            ],
        ];

        // #2194 — a second "Trends over time" sheet: one row per player, one
        // column per recorded date (chronological), the value in each cell,
        // plus a line chart bound to that grid so a coach reads a player's
        // longitudinal series at a glance (CLAUDE.md §1). Built from the same
        // rows — no extra query.
        $trends = $this->trendsSheet( $def, $value_type, $unit, $rows );
        if ( $trends !== null ) {
            $sheets[ __( 'Trends', 'talenttrack' ) ] = $trends;
        }

        return [ 'styled_sheets' => $sheets ];
    }

    /**
     * The "Trends over time" sheet: players × dates pivot + a line chart.
     *
     * Rows = players, columns = the distinct recorded dates in chronological
     * order, each cell the value that player recorded on that date (blank
     * where none). A PhpSpreadsheet line chart is bound to the value grid so
     * every player is a plotted series over the shared date axis.
     *
     * Only *ordered* value types (numeric, scale) chart meaningfully — they
     * hold a plottable `value_numeric`. A status-type test carries text levels
     * with no numeric axis, so it degrades gracefully: the pivot still lists
     * each player's label per date for reference, but no chart is attached. A
     * charting test with no numeric history at all yields no sheet.
     *
     * @param array<int, object> $rows  the same export rows collect() shapes
     * @return array<string, mixed>|null  a styled-sheet spec, or null to skip
     */
    private function trendsSheet( object $def, string $value_type, string $unit, array $rows ): ?array {
        if ( empty( $rows ) ) {
            return null;
        }

        $charts = in_array( $value_type, [ 'numeric', 'scale' ], true );

        // Pivot: player => ( date => value ), and the ordered set of dates.
        $players = []; // player_id => name
        $dates   = []; // date => true (used as an ordered set)
        $grid    = []; // player_id => date => scalar value

        foreach ( $rows as $r ) {
            $pid = (int) $r->player_id;
            if ( $pid <= 0 ) continue;
            $name = trim( (string) $r->first_name . ' ' . (string) $r->last_name );
            $date = (string) $r->recorded_date;
            if ( $date === '' ) continue;

            $players[ $pid ] = $name !== '' ? $name : (string) $pid;
            $dates[ $date ]  = true;

            if ( $charts ) {
                // Only the numeric value can drive a line chart. When a player
                // has several results on one date, the last (latest id) wins —
                // rows arrive date-ascending, so this keeps the newest.
                if ( $r->value_numeric !== null ) {
                    $grid[ $pid ][ $date ] = (float) $r->value_numeric;
                }
            } else {
                // Status / text: show the recorded label for reference.
                $grid[ $pid ][ $date ] = (string) ( $r->value_text ?? '' );
            }
        }

        if ( empty( $players ) || empty( $dates ) ) {
            return null;
        }
        // A charting test with no numeric values at all has nothing to plot and
        // nothing useful to pivot — skip the sheet.
        if ( $charts && empty( $grid ) ) {
            return null;
        }

        $date_list = array_keys( $dates );
        sort( $date_list ); // ISO dates sort chronologically as strings.

        $title_line = $charts
            ? ( $unit !== ''
                ? sprintf( /* translators: %s: unit of measure */ __( 'Value over time (%s)', 'talenttrack' ), $unit )
                : __( 'Value over time', 'talenttrack' ) )
            : __( 'Recorded status over time', 'talenttrack' );

        $sheet_rows = [];

        // Header block: test name + what the grid shows.
        $sheet_rows[] = [ [ 'v' => (string) $def->name, 'style' => 'title' ] ];
        $sheet_rows[] = [ [ 'v' => $title_line, 'style' => 'subtitle' ] ];
        $sheet_rows[] = [ '' ];

        $header_row = 4; // 2 header rows + 1 spacer, so the column header is row 4.

        // Column-header row: "Player" then one column per date.
        $col_header = [ [ 'v' => __( 'Player', 'talenttrack' ), 'style' => 'th' ] ];
        foreach ( $date_list as $d ) {
            $col_header[] = [ 'v' => $d, 'style' => 'th' ];
        }
        $sheet_rows[] = $col_header;

        // One row per player, a value (or blank) under each date.
        foreach ( $players as $pid => $name ) {
            $row = [ [ 'v' => $name, 'style' => 'td' ] ];
            foreach ( $date_list as $d ) {
                $cell  = $grid[ $pid ][ $d ] ?? null;
                $row[] = [ 'v' => $cell === null ? '' : $cell, 'style' => 'td' ];
            }
            $sheet_rows[] = $row;
        }

        $first_data_row = $header_row + 1;
        $last_data_row  = $header_row + count( $players );
        $last_col_index = count( $date_list ) + 1; // +1 for the player column
        $last_col       = $this->columnLetter( $last_col_index );

        // Column widths: the player column plus a fixed width per date column.
        $col_widths = [ 'A' => 26 ];
        for ( $c = 2; $c <= $last_col_index; $c++ ) {
            $col_widths[ $this->columnLetter( $c ) ] = 14;
        }

        $spec = [
            'rows'       => $sheet_rows,
            'merges'     => [ 'A1:' . $last_col . '1', 'A2:' . $last_col . '2' ],
            'freeze'     => 'B' . $first_data_row,
            'col_widths' => $col_widths,
            'styles'     => $this->styles( [] ),
        ];

        // A line chart bound to the value grid: each player row is a series
        // plotted over the shared date axis. Only when the values are numeric.
        if ( $charts ) {
            $spec['chart'] = [
                'type'             => 'line',
                'title'            => $title_line,
                // Categories = the date header row across the value columns.
                'categories_row'   => $header_row,
                'categories_from'  => 'B',
                'categories_to'    => $last_col,
                // Each series is one player row; its label is the player cell (col A).
                'series_name_col'  => 'A',
                'values_from_col'  => 'B',
                'values_to_col'    => $last_col,
                'series_first_row' => $first_data_row,
                'series_last_row'  => $last_data_row,
                // Where to drop the chart on the sheet (below the table).
                'anchor'           => 'A' . ( $last_data_row + 2 ),
                'y_axis_title'     => $unit !== '' ? $unit : __( 'Value', 'talenttrack' ),
                'x_axis_title'     => __( 'Date', 'talenttrack' ),
            ];
        }

        return $spec;
    }

    /** 1-based column index → Excel column letters (1 → A, 27 → AA). */
    private function columnLetter( int $index ): string {
        $letter = '';
        while ( $index > 0 ) {
            $mod    = ( $index - 1 ) % 26;
            $letter = chr( 65 + $mod ) . $letter;
            $index  = (int) ( ( $index - $mod ) / 26 );
        }
        return $letter === '' ? 'A' : $letter;
    }

    /**
     * Shape one value cell. Status → the level label, filled with the level's
     * colour (white/dark text per the curated palette). Numeric → the number
     * with the unit appended. Scale / pass-fail → the recorded text or number.
     *
     * @param array<string, string> $level_token  label => colour token
     * @return array<string, mixed>
     */
    private function valueCell( string $value_type, string $unit, object $row, array $level_token ): array {
        if ( $value_type === 'status' ) {
            $label = (string) ( $row->value_text ?? '' );
            $token = $level_token[ $label ] ?? MeasurementLevelPalette::DEFAULT_TOKEN;
            return [ 'v' => $label, 'style' => 'lvl_' . $token ];
        }

        if ( $value_type === 'numeric' && $row->value_numeric !== null ) {
            $num = $this->trimNumber( (float) $row->value_numeric );
            return [ 'v' => $unit !== '' ? $num . ' ' . $unit : $num, 'style' => 'td' ];
        }

        if ( $row->value_numeric !== null ) {
            return [ 'v' => $this->trimNumber( (float) $row->value_numeric ), 'style' => 'td' ];
        }

        return [ 'v' => (string) ( $row->value_text ?? '' ), 'style' => 'td' ];
    }

    /** Render a stored decimal without trailing-zero noise (e.g. 12.500 → 12.5). */
    private function trimNumber( float $n ): string {
        $s = rtrim( rtrim( number_format( $n, 3, '.', '' ), '0' ), '.' );
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptyWorkbook( string $name ): array {
        return [
            'styled_sheets' => [
                __( 'Results', 'talenttrack' ) => [
                    'rows' => [
                        [ [ 'v' => $name, 'style' => 'title' ] ],
                        [ '' ],
                        [
                            [ 'v' => __( 'Player', 'talenttrack' ),        'style' => 'th' ],
                            [ 'v' => __( 'Team', 'talenttrack' ),          'style' => 'th' ],
                            [ 'v' => __( 'Recorded date', 'talenttrack' ), 'style' => 'th' ],
                            [ 'v' => __( 'Value', 'talenttrack' ),         'style' => 'th' ],
                            [ 'v' => __( 'Age group', 'talenttrack' ),     'style' => 'th' ],
                            [ 'v' => __( 'Recorded by', 'talenttrack' ),   'style' => 'th' ],
                        ],
                    ],
                    'merges'     => [ 'A1:F1' ],
                    'freeze'     => 'A3',
                    'col_widths' => [ 'A' => 26, 'B' => 18, 'C' => 16, 'D' => 20, 'E' => 14, 'F' => 22 ],
                    'styles'     => $this->styles( [] ),
                ],
            ],
        ];
    }

    /**
     * Named styles for the styled-sheets payload. One `lvl_<token>` style per
     * curated colour so a status value cell paints in its level's colour with
     * legible text — the .xlsx mirror of the `.tt-meas-value--status` chip.
     *
     * @param array<string, string> $level_token
     * @return array<string, array<string, mixed>>
     */
    private function styles( array $level_token ): array {
        $styles = [
            'title' => [
                'font'      => [ 'bold' => true, 'size' => 14, 'color' => '1D3A2E' ],
                'alignment' => [ 'vertical' => 'center' ],
            ],
            'subtitle' => [
                'font' => [ 'size' => 10, 'color' => '6A6D66' ],
            ],
            'th' => [
                'font'      => [ 'bold' => true, 'color' => 'FFFFFF' ],
                'fill'      => [ 'color' => '1D7874' ],
                'alignment' => [ 'vertical' => 'center' ],
                'borders'   => [ 'all' => [ 'style' => 'thin', 'color' => 'D6DADD' ] ],
            ],
            'td' => [
                'borders' => [ 'all' => [ 'style' => 'thin', 'color' => 'E3E6E1' ] ],
            ],
        ];

        // A status cell needs a colour style whatever level it carries, so emit
        // one per curated token (dark text where the swatch is light, matching
        // the CSS lime / yellow / amber / cyan overrides).
        $dark_text = [ 'lime', 'yellow', 'amber', 'cyan' ];
        foreach ( self::TOKEN_HEX as $token => $hex ) {
            $styles[ 'lvl_' . $token ] = [
                'font'      => [ 'bold' => true, 'color' => in_array( $token, $dark_text, true ) ? '1D3A2E' : 'FFFFFF' ],
                'fill'      => [ 'color' => $hex ],
                'borders'   => [ 'all' => [ 'style' => 'thin', 'color' => 'E3E6E1' ] ],
            ];
        }

        return $styles;
    }
}
