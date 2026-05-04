<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * SeedExporter — emit the current matrix seed as XLSX or CSV.
 *
 * Exports the LIVE state of `tt_authorization_matrix` (not the
 * checked-in PHP seed file). That keeps the round-trip honest: the
 * operator edits whatever is currently active, hands it to a
 * stakeholder, and the importer can replace the live state with the
 * edited copy without ever touching `config/authorization_seed.php`.
 *
 * The seed file remains the shipped default that "Reset to defaults"
 * restores; the export/import workflow gives the operator a way to
 * customise that default per-install without forking the PHP file.
 *
 * Long-form schema (one row per granted permission):
 *
 *   | persona | entity | activity | scope_kind | module_class | is_default |
 *
 * Long-form makes Excel filters and pivots straightforward and the
 * round-trip lossless. ~250-400 rows for a stock seed.
 */
final class SeedExporter {

    /** Stream the matrix as XLSX directly to the browser. Returns false if PhpSpreadsheet missing. */
    public static function streamXlsx( string $filename = 'talenttrack-matrix-seed.xlsx' ): bool {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            return false;
        }

        $rows = self::collectRows();

        $book  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle( 'Matrix' );

        // Header row.
        $headers = [ 'persona', 'entity', 'activity', 'scope_kind', 'module_class', 'is_default' ];
        $col = 'A';
        foreach ( $headers as $h ) {
            $sheet->setCellValue( $col . '1', $h );
            $col++;
        }
        $sheet->getStyle( 'A1:F1' )->getFont()->setBold( true );
        $sheet->getStyle( 'A1:F1' )
            ->getFill()
            ->setFillType( \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID )
            ->getStartColor()->setRGB( 'EEEEEE' );

        // Data rows. PhpSpreadsheet rows are 1-indexed; data starts at row 2.
        $r = 2;
        foreach ( $rows as $row ) {
            $sheet->setCellValue( 'A' . $r, $row['persona'] );
            $sheet->setCellValue( 'B' . $r, $row['entity'] );
            $sheet->setCellValue( 'C' . $r, $row['activity'] );
            $sheet->setCellValue( 'D' . $r, $row['scope_kind'] );
            $sheet->setCellValue( 'E' . $r, $row['module_class'] );
            $sheet->setCellValue( 'F' . $r, (int) $row['is_default'] );
            $r++;
        }

        // Auto-size + freeze header row.
        foreach ( range( 'A', 'F' ) as $letter ) {
            $sheet->getColumnDimension( $letter )->setAutoSize( true );
        }
        $sheet->freezePane( 'A2' );

        // Add a leading "_README" sheet explaining the columns + activity
        // / scope vocab so a stakeholder editing the file out-of-band has
        // the contract written down. Mirrors the demo-data TemplateBuilder
        // pattern.
        self::emitReadmeSheet( $book );
        $book->setActiveSheetIndex( 0 );

        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book );
        $writer->save( 'php://output' );
        return true;
    }

    /** Stream the matrix as CSV directly to the browser. Universal fallback. */
    public static function streamCsv( string $filename = 'talenttrack-matrix-seed.csv' ): void {
        $rows = self::collectRows();

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // BOM so Excel opens UTF-8 correctly without garbling em-dashes
        // in module class names. Stripped by the importer.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ 'persona', 'entity', 'activity', 'scope_kind', 'module_class', 'is_default' ] );
        foreach ( $rows as $row ) {
            fputcsv( $out, [
                $row['persona'],
                $row['entity'],
                $row['activity'],
                $row['scope_kind'],
                $row['module_class'],
                (int) $row['is_default'],
            ] );
        }
        fclose( $out );
    }

    /**
     * Collect every persona × entity × activity × scope_kind tuple
     * currently in the matrix table. Sorted persona → entity → activity
     * for deterministic file diffs.
     *
     * @return list<array{persona:string,entity:string,activity:string,scope_kind:string,module_class:string,is_default:int}>
     */
    private static function collectRows(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT persona, entity, activity, scope_kind, module_class, is_default
             FROM {$p}tt_authorization_matrix
             ORDER BY persona, entity, activity, scope_kind",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    private static function emitReadmeSheet( $book ): void {
        $sheet = $book->createSheet( 0 );
        $sheet->setTitle( '_README' );
        $sheet->getTabColor()->setRGB( 'FFC000' );

        $rows = [
            [ __( 'TalentTrack — Authorization matrix seed', 'talenttrack' ) ],
            [ '' ],
            [ __( 'How to use this file', 'talenttrack' ) ],
            [ __( 'Edit the Matrix sheet to grant or revoke permissions per persona. Save as .xlsx (or .csv) and re-upload via Authorization → Matrix → Import seed.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Columns', 'talenttrack' ) ],
            [ __( 'persona — one of: player, parent, assistant_coach, head_coach, team_manager, scout, head_of_development, academy_admin', 'talenttrack' ) ],
            [ __( 'entity — the resource being permitted (e.g. players, audit_log, dev_ideas). See Authorization → Matrix in the app for the full list.', 'talenttrack' ) ],
            [ __( 'activity — one of: read, change, create_delete', 'talenttrack' ) ],
            [ __( 'scope_kind — one of: global, team, player, self', 'talenttrack' ) ],
            [ __( 'module_class — fully-qualified PHP class name of the owning module (e.g. TT\\Modules\\Players\\PlayersModule). Leave alone unless you know what you are doing.', 'talenttrack' ) ],
            [ __( 'is_default — 1 = shipped default, 0 = admin-edited. Importer will mark every row as 0 (admin-edited) since the import IS the admin edit.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Rules', 'talenttrack' ) ],
            [ __( 'One row per granted permission. To revoke a permission, delete the row.', 'talenttrack' ) ],
            [ __( 'Import REPLACES the matrix. Anything not in the upload is removed.', 'talenttrack' ) ],
            [ __( 'A diff preview shows you adds + removes before commit. Reset to defaults remains available to restore the shipped seed.', 'talenttrack' ) ],
        ];
        $r = 1;
        foreach ( $rows as $line ) {
            $sheet->setCellValue( 'A' . $r, $line[0] );
            if ( $r === 1 ) {
                $sheet->getStyle( 'A1' )->getFont()->setBold( true )->setSize( 14 );
            } elseif ( in_array( $line[0], [ __( 'How to use this file', 'talenttrack' ), __( 'Columns', 'talenttrack' ), __( 'Rules', 'talenttrack' ) ], true ) ) {
                $sheet->getStyle( 'A' . $r )->getFont()->setBold( true );
            }
            $r++;
        }
        $sheet->getColumnDimension( 'A' )->setWidth( 110 );
    }
}
