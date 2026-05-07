<?php
namespace TT\Modules\SeedReview;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SeedExporter — generates the seed-review .xlsx on demand.
 *
 * Sheets:
 *   - Lookups          — every tt_lookups row for the current club.
 *   - Eval categories  — every tt_eval_categories row (main + sub).
 *   - Roles            — every tt_roles row.
 *   - Functional roles — every tt_functional_roles row.
 *
 * Each sheet's first row is a frozen header. Columns are stable across
 * versions because the importer matches on column names; reordering
 * columns in Excel is fine, renaming them is not.
 *
 * Translations: for every row whose canonical `name` / `label` is an
 * English string with a registered .po translation, we surface the
 * Dutch translation in a separate column. The exporter switches the
 * locale via `switch_to_locale('nl_NL')` for the duration of the
 * translation lookup, then restores. Operators on a Dutch install
 * always get the Dutch reference text in the dedicated column.
 */
final class SeedExporter {

    public static function streamDownload( string $filename = 'talenttrack-seed-review.xlsx' ): bool {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            return false;
        }
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex( 0 );

        self::buildLookupsSheet( $book );
        self::buildEvalCategoriesSheet( $book );
        self::buildRolesSheet( $book );
        self::buildFunctionalRolesSheet( $book );

        $book->setActiveSheetIndex( 0 );

        if ( ! headers_sent() ) {
            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
        }
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book );
        $writer->save( 'php://output' );
        return true;
    }

    /**
     * Render `tt_lookups` rows. Every row has an editable English
     * label (via the `name` column today; some installs override
     * via `meta.label_<locale>`), its Dutch reflection from `__()`,
     * a flag for which language the stored value is in, and the
     * sort / color / locked columns the frontend lookup admin already
     * exposes.
     */
    private static function buildLookupsSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Lookups' );

        $headers = [
            'table', 'id', 'lookup_type', 'name', 'description',
            'label_nl', 'language_of_name', 'sort_order',
            'meta_color', 'locked', 'notes',
        ];
        self::writeHeader( $sheet, $headers );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, lookup_type, name, description, sort_order, meta
               FROM {$wpdb->prefix}tt_lookups
              WHERE club_id = %d
              ORDER BY lookup_type ASC, sort_order ASC, name ASC",
            CurrentClub::id()
        ) );

        $row_idx = 2;
        foreach ( (array) $rows as $r ) {
            $meta_arr = [];
            if ( ! empty( $r->meta ) ) {
                $decoded = json_decode( (string) $r->meta, true );
                if ( is_array( $decoded ) ) $meta_arr = $decoded;
            }
            $sheet->setCellValue( "A{$row_idx}", 'tt_lookups' );
            $sheet->setCellValue( "B{$row_idx}", (int) $r->id );
            $sheet->setCellValue( "C{$row_idx}", (string) $r->lookup_type );
            $sheet->setCellValue( "D{$row_idx}", (string) $r->name );
            $sheet->setCellValue( "E{$row_idx}", (string) ( $r->description ?? '' ) );
            $sheet->setCellValue( "F{$row_idx}", self::translateToNl( (string) $r->name ) );
            $sheet->setCellValue( "G{$row_idx}", self::detectLanguage( (string) $r->name ) );
            $sheet->setCellValue( "H{$row_idx}", (int) ( $r->sort_order ?? 0 ) );
            $sheet->setCellValue( "I{$row_idx}", (string) ( $meta_arr['color'] ?? '' ) );
            $sheet->setCellValue( "J{$row_idx}", ! empty( $meta_arr['locked'] ) ? 'yes' : 'no' );
            $sheet->setCellValue( "K{$row_idx}", '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
    }

    /**
     * Render `tt_eval_categories`. Hierarchy via `parent_id`; main
     * categories sort before their children. Editable columns:
     * `label`, `display_order`, `is_active`. The Dutch reflection
     * comes from `__()` again.
     */
    private static function buildEvalCategoriesSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Eval categories' );

        $headers = [
            'table', 'id', 'parent_id', 'kind', 'label',
            'label_nl', 'language_of_label',
            'display_order', 'is_active', 'rating_max', 'meta', 'notes',
        ];
        self::writeHeader( $sheet, $headers );

        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_eval_categories';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            return;
        }
        $rows = $wpdb->get_results(
            "SELECT id, parent_id, label, display_order, is_active, rating_max, meta
               FROM {$tbl}
              ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, display_order, label"
        );

        $row_idx = 2;
        foreach ( (array) $rows as $r ) {
            $kind = $r->parent_id === null ? 'main' : 'sub';
            $sheet->setCellValue( "A{$row_idx}", 'tt_eval_categories' );
            $sheet->setCellValue( "B{$row_idx}", (int) $r->id );
            $sheet->setCellValue( "C{$row_idx}", $r->parent_id !== null ? (int) $r->parent_id : '' );
            $sheet->setCellValue( "D{$row_idx}", $kind );
            $sheet->setCellValue( "E{$row_idx}", (string) $r->label );
            $sheet->setCellValue( "F{$row_idx}", self::translateToNl( (string) $r->label ) );
            $sheet->setCellValue( "G{$row_idx}", self::detectLanguage( (string) $r->label ) );
            $sheet->setCellValue( "H{$row_idx}", (int) ( $r->display_order ?? 0 ) );
            $sheet->setCellValue( "I{$row_idx}", (int) ( $r->is_active ?? 1 ) ? 'yes' : 'no' );
            $sheet->setCellValue( "J{$row_idx}", (int) ( $r->rating_max ?? 0 ) );
            $sheet->setCellValue( "K{$row_idx}", (string) ( $r->meta ?? '' ) );
            $sheet->setCellValue( "L{$row_idx}", '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
    }

    /**
     * Render `tt_roles` — the system role definitions
     * (Activator::defaultRoleDefinitions). Editable column: `label`.
     */
    private static function buildRolesSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Roles' );

        $headers = [ 'table', 'id', 'role_key', 'label', 'label_nl', 'language_of_label', 'is_system', 'notes' ];
        self::writeHeader( $sheet, $headers );

        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_roles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            return;
        }
        $rows = $wpdb->get_results( "SELECT id, role_key, label, is_system FROM {$tbl} ORDER BY role_key" );

        $row_idx = 2;
        foreach ( (array) $rows as $r ) {
            $sheet->setCellValue( "A{$row_idx}", 'tt_roles' );
            $sheet->setCellValue( "B{$row_idx}", (int) $r->id );
            $sheet->setCellValue( "C{$row_idx}", (string) $r->role_key );
            $sheet->setCellValue( "D{$row_idx}", (string) $r->label );
            $sheet->setCellValue( "E{$row_idx}", self::translateToNl( (string) $r->label ) );
            $sheet->setCellValue( "F{$row_idx}", self::detectLanguage( (string) $r->label ) );
            $sheet->setCellValue( "G{$row_idx}", (int) ( $r->is_system ?? 0 ) ? 'yes' : 'no' );
            $sheet->setCellValue( "H{$row_idx}", '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
    }

    /**
     * Render `tt_functional_roles` — the team-staff role types
     * (head_coach, assistant_coach, manager, etc.). Editable: `label`.
     */
    private static function buildFunctionalRolesSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Functional roles' );

        $headers = [ 'table', 'id', 'role_key', 'label', 'label_nl', 'language_of_label', 'sort_order', 'is_active', 'notes' ];
        self::writeHeader( $sheet, $headers );

        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_functional_roles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            return;
        }
        $rows = $wpdb->get_results( "SELECT id, role_key, label, sort_order, is_active FROM {$tbl} ORDER BY sort_order, role_key" );

        $row_idx = 2;
        foreach ( (array) $rows as $r ) {
            $sheet->setCellValue( "A{$row_idx}", 'tt_functional_roles' );
            $sheet->setCellValue( "B{$row_idx}", (int) $r->id );
            $sheet->setCellValue( "C{$row_idx}", (string) $r->role_key );
            $sheet->setCellValue( "D{$row_idx}", (string) $r->label );
            $sheet->setCellValue( "E{$row_idx}", self::translateToNl( (string) $r->label ) );
            $sheet->setCellValue( "F{$row_idx}", self::detectLanguage( (string) $r->label ) );
            $sheet->setCellValue( "G{$row_idx}", (int) ( $r->sort_order ?? 0 ) );
            $sheet->setCellValue( "H{$row_idx}", (int) ( $r->is_active ?? 1 ) ? 'yes' : 'no' );
            $sheet->setCellValue( "I{$row_idx}", '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
    }

    /**
     * Back-translate an English seed value to its Dutch equivalent
     * via the .po file. We switch the active locale to `nl_NL`,
     * call `__()` with the original string as the msgid, and
     * compare the return value: if it differs, that's the Dutch
     * translation. If it matches the input, no translation exists
     * yet.
     */
    private static function translateToNl( string $en ): string {
        if ( $en === '' ) return '';
        if ( ! function_exists( 'switch_to_locale' ) ) return '';
        $current = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        if ( $current !== 'nl_NL' ) {
            switch_to_locale( 'nl_NL' );
        }
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        $nl = __( $en, 'talenttrack' );
        if ( $current !== 'nl_NL' ) {
            restore_previous_locale();
        }
        return $nl === $en ? '' : (string) $nl;
    }

    /**
     * Best-effort guess at which language the stored string is in.
     * Used as a hint to the operator reviewing the export — they
     * can override by editing the cell.
     *
     * Heuristic:
     *   - Common Dutch words (`Aanwezig`, `Rechts`, `Links`, etc.) → 'nl'.
     *   - All-lowercase snake-case keys (`right`, `present`) → 'en' (key, not label).
     *   - Otherwise → 'en'.
     *
     * Most seed rows ship as English keys / capitalised English
     * labels, so 'en' is the correct default. Operators who have
     * already overridden a label to Dutch tag it 'nl' here.
     */
    private static function detectLanguage( string $stored ): string {
        $nl_markers = [
            'aanwezig', 'afwezig', 'geblesseerd', 'verzuimd',
            'rechts', 'links', 'beide',
            'doelman', 'verdediger', 'middenvelder', 'aanvaller',
            'doel', 'in behandeling', 'voltooid', 'in de wacht', 'geannuleerd',
            'hoofdtrainer', 'assistent-trainer', 'teammanager',
        ];
        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $stored ) : strtolower( $stored );
        foreach ( $nl_markers as $marker ) {
            if ( strpos( $lower, $marker ) !== false ) return 'nl';
        }
        return 'en';
    }

    private static function writeHeader( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers ): void {
        $col = 1;
        foreach ( $headers as $h ) {
            $sheet->setCellValueByColumnAndRow( $col, 1, $h );
            $col++;
        }
        $top = $sheet->getStyle( '1:1' );
        $top->getFont()->setBold( true );
        $top->getFill()->setFillType( \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID )->getStartColor()->setRGB( 'EEEEEE' );
    }

    private static function freezeAndAutosize( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $col_count ): void {
        $sheet->freezePane( 'A2' );
        for ( $c = 1; $c <= $col_count; $c++ ) {
            $col_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c );
            $sheet->getColumnDimension( $col_letter )->setAutoSize( true );
        }
    }
}
