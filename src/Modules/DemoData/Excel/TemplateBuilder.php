<?php
namespace TT\Modules\DemoData\Excel;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TemplateBuilder (#0059) — generates the demo-data .xlsx template
 * on-demand from `SheetSchemas`. Called when the wizard's
 * "Download template" link is clicked; the file streams directly to
 * the browser without ever being checked into the repo.
 *
 * On-demand generation means there's no CI gate to keep a checked-in
 * .xlsx in sync with the schema — they can't drift because the file
 * is built fresh on every download.
 */
final class TemplateBuilder {

    /**
     * Build the workbook + stream it to the browser as an attachment.
     * Caller (the wizard's download handler) is responsible for the
     * cap check and nonce. Returns false when PhpSpreadsheet is missing.
     */
    public static function streamDownload( string $filename = 'talenttrack-demo-data-template.xlsx' ): bool {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            return false;
        }

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex( 0 );

        $i = 0;
        foreach ( SheetSchemas::all() as $key => $schema ) {
            $sheet = $book->createSheet( $i );
            $sheet->setTitle( $schema['sheet'] );

            // Header row.
            $col = 'A';
            foreach ( $schema['columns'] as $col_key => $meta ) {
                $sheet->setCellValue( $col . '1', $meta['label'] );
                $col++;
            }

            // Style header row bold.
            $last_col = chr( ord( 'A' ) + count( $schema['columns'] ) - 1 );
            $sheet->getStyle( 'A1:' . $last_col . '1' )->getFont()->setBold( true );

            // Pre-populate the auto_key formula on rows 2-201 if the
            // schema declares an auto_key column. Lets users see the
            // computed key materialise as they type into the source
            // columns. Pattern: "<entity>_<lowercased-name>_<row>".
            if ( isset( $schema['columns']['auto_key'] ) ) {
                $name_col = $key === 'teams' ? 'B' : ( $key === 'players' ? 'C' : 'B' );
                for ( $r = 2; $r <= 201; $r++ ) {
                    $formula = sprintf(
                        '=IF(%1$s%2$d="","",CONCAT("%3$s_",LOWER(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(TRIM(%1$s%2$d)," ","_"),"-","_"),"\'","")),"_",%2$d))',
                        $name_col, $r, $schema['entity']
                    );
                    $sheet->setCellValue( 'A' . $r, $formula );
                }
            }

            $i++;
        }

        $book->setActiveSheetIndex( 0 );

        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: max-age=0' );

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book );
        $writer->save( 'php://output' );
        return true;
    }
}
