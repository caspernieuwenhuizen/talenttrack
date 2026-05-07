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

        // #0063 — Lead the workbook with a `_README` sheet so the user
        // sees how to fill the template before they hit any data sheets.
        // Explains auto_key, the green / blue / purple / grey tab
        // colours, FK references, and which sheets the importer
        // actually consumes (the v1.5 IMPORTABLE_SHEETS list).
        self::emitReadmeSheet( $book );
        $i = 1;
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
    /**
     * #0063 — `_README` sheet leading the workbook. Plain-prose
     * how-to-fill explanation. Indexed first so it opens by default
     * when the user double-clicks the downloaded file.
     */
    private static function emitReadmeSheet( $book ): void {
        $sheet = $book->createSheet( 0 );
        $sheet->setTitle( '_README' );
        $sheet->getTabColor()->setRGB( 'FFC000' ); // amber, distinct from data tabs

        // Sheet identifier tokens (Sessions, Session_Attendance, Teams, etc.)
        // are English literals because the importer reads sheet names exactly
        // — translating those tokens in the instructions would mislead users
        // into renaming sheets and breaking the import. The surrounding prose
        // and section headings translate via __().
        $rows = [
            [ __( 'TalentTrack — demo data template', 'talenttrack' ) ],
            [ '' ],
            [ __( 'How to fill this workbook', 'talenttrack' ) ],
            [ __( 'Each green tab is a master entity (Teams, People, Players, Trial cases). Each blue tab is a transactional record (Activities, Attendance, Evaluations, Goals, Player journey). Purple = settings, grey = reference.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'auto_key (the first column on every entity tab)', 'talenttrack' ) ],
            [ __( 'Type a short, unique label (e.g. "ABC", "U12_RED", "MARTIN") in the auto_key cell. Other tabs reference back to it via team_key / player_key / session_key fields. The importer maps your auto_key to the inserted row id, so cross-sheet links resolve at import time without you needing the database id.', 'talenttrack' ) ],
            [ __( 'Leave auto_key blank if you do not need to reference the row from another sheet — but most rows are referenced somewhere, so filling it in is the safe default.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Foreign-key columns (e.g. team_key, player_key)', 'talenttrack' ) ],
            [ __( 'Type the auto_key value of the row you want to link to. Example: a Players row with team_key="ABC" links to the Teams row whose auto_key is "ABC". The importer rejects the workbook if a foreign-key value points to a row that does not exist.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Importable vs. documentation-only sheets', 'talenttrack' ) ],
            [ __( 'The importer (v1.5) consumes: Teams, People, Players, Trial_Cases, Activities, Session_Attendance, Evaluations, Evaluation_Ratings, Goals, Player_Journey, Generation_Settings.', 'talenttrack' ) ],
            [ __( 'Reference tabs (Eval_Categories, Category_Weights, _Lookups) are documentation-only. Configure those via the wp-admin Configuration screens. They are included here so you can see what categories / weights / lookups your imported data will key into.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Required vs. optional columns', 'talenttrack' ) ],
            [ __( 'Every column header carries a label. Required columns are typically the human-name fields (first_name / last_name / title) plus the foreign keys that establish relationships. The importer will tell you which row + column failed validation when you upload.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Dates', 'talenttrack' ) ],
            [ __( 'YYYY-MM-DD only. Excel sometimes auto-formats dates to your local format on cell entry — set the column format to "Text" first if you see weird date values after upload.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Recommended workflow', 'talenttrack' ) ],
            [ __( '1. Fill Teams first. Pick auto_key labels that mean something to you ("U12_RED", "U14_GREEN").', 'talenttrack' ) ],
            [ __( '2. Fill People next, referencing Teams via team_key on staff rows.', 'talenttrack' ) ],
            [ __( '3. Fill Players, referencing Teams via team_key.', 'talenttrack' ) ],
            [ __( '4. Add Activities for each team (they reference Teams via team_key).', 'talenttrack' ) ],
            [ __( '5. Add Session_Attendance rows for who showed up at each activity.', 'talenttrack' ) ],
            [ __( '6. Add Evaluations + Evaluation_Ratings, referencing Players + Activities.', 'talenttrack' ) ],
            [ __( '7. Add Goals + Player_Journey events as needed.', 'talenttrack' ) ],
            [ '' ],
            [ __( 'Save as .xlsx and upload via TalentTrack → Configuration → Demo data → Step 0 — Source → Excel upload.', 'talenttrack' ) ],
        ];

        $row_index = 1;
        foreach ( $rows as $row ) {
            $sheet->setCellValue( 'A' . $row_index, $row[0] );
            $row_index++;
        }
        $sheet->getStyle( 'A1' )->getFont()->setBold( true )->setSize( 14 );
        $sheet->getStyle( 'A3' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getStyle( 'A6' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getStyle( 'A11' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getStyle( 'A14' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getStyle( 'A18' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getStyle( 'A21' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getStyle( 'A24' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getColumnDimension( 'A' )->setWidth( 110 );
        $sheet->getStyle( 'A1:A33' )->getAlignment()->setWrapText( true );
    }

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
