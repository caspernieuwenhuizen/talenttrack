<?php
namespace TT\Modules\DemoData\Excel;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TemplateBuilder (#0059) — generates the demo-data .xlsx template
 * on-demand from `SheetSchemas`. Called when the wizard's "Download
 * template" link is clicked; the file streams directly to the browser
 * without ever being checked into the repo.
 *
 * On-demand generation means there's no CI gate to keep a checked-in
 * .xlsx in sync with the schema — they can't drift because the file
 * is built fresh on every download.
 *
 * v1.5: emits all 15 sheets in the spec, with per-group tab colours
 * (master = green, transactional = blue, config = purple, reference =
 * grey) and pre-populated `auto_key` formulas for 200 rows on every
 * entity sheet.
 */
final class TemplateBuilder {

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

            // Tab colour per group.
            $color = SheetSchemas::tabColor( (string) ( $schema['group'] ?? '' ) );
            $sheet->getTabColor()->setRGB( $color );

            // Header row.
            $col = 'A';
            foreach ( $schema['columns'] as $col_key => $meta ) {
                $sheet->setCellValue( $col . '1', $meta['label'] );
                $col++;
            }

            // Bold + light-grey background on header row.
            $last_col = chr( ord( 'A' ) + count( $schema['columns'] ) - 1 );
            $sheet->getStyle( 'A1:' . $last_col . '1' )->getFont()->setBold( true );
            $sheet->getStyle( 'A1:' . $last_col . '1' )
                ->getFill()
                ->setFillType( \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID )
                ->getStartColor()->setRGB( 'EEEEEE' );

            // auto_key formula on rows 2..201 for entity sheets.
            if ( isset( $schema['columns']['auto_key'] ) ) {
                self::populateAutoKeyFormula( $sheet, $key, $schema );
            }

            // Generation_Settings: pre-fill a couple of useful keys so
            // admins know what's available.
            if ( $key === 'generation_settings' ) {
                self::populateGenerationSettingsHints( $sheet );
            }

            // Auto-size columns for readability.
            foreach ( range( 'A', $last_col ) as $letter ) {
                $sheet->getColumnDimension( $letter )->setAutoSize( true );
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

    /**
     * Pre-populate the auto_key formula on rows 2..201. The formula
     * references the column most likely to be the natural identifier
     * for the entity (e.g. Players → first/last name combined).
     * Verified working in LibreOffice + Microsoft Excel.
     */
    private static function populateAutoKeyFormula( $sheet, string $key, array $schema ): void {
        // Pick the source column letter based on the entity's natural
        // identifier — name field for entities that have one, otherwise
        // fall back to the first non-auto_key column.
        $source_col = self::sourceColumnFor( $key, $schema );
        $entity     = (string) $schema['entity'];

        for ( $r = 2; $r <= 201; $r++ ) {
            $formula = sprintf(
                '=IF(%1$s%2$d="","",CONCAT("%3$s_",LOWER(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(TRIM(%1$s%2$d)," ","_"),"-","_"),"\'","")),"_",%2$d))',
                $source_col, $r, $entity
            );
            $sheet->setCellValue( 'A' . $r, $formula );
        }
    }

    private static function sourceColumnFor( string $key, array $schema ): string {
        // Map each entity to the column letter whose value drives the
        // slug part of auto_key. Defaults to column B (first column
        // after auto_key).
        $defaults = [
            'teams'        => 'B', // Name
            'people'       => 'C', // Last name
            'players'      => 'C', // Last name
            'trial_cases'  => 'B', // Player key
            'sessions'     => 'D', // Title
            'evaluations'  => 'C', // eval_date
            'goals'        => 'C', // Title
        ];
        return $defaults[ $key ] ?? 'B';
    }

    /**
     * Two example rows on Generation_Settings so admins know what
     * keys are read by the hybrid dispatcher.
     */
    private static function populateGenerationSettingsHints( $sheet ): void {
        $hints = [
            [ 'demo_period_start', '2026-01-01' ],
            [ 'demo_period_end',   '2026-12-31' ],
            [ 'season_id',         '' ],
        ];
        $r = 2;
        foreach ( $hints as $row ) {
            $sheet->setCellValue( 'A' . $r, $row[0] );
            $sheet->setCellValue( 'B' . $r, $row[1] );
            $r++;
        }
    }
}
