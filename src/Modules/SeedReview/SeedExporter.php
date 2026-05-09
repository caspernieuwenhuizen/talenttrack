<?php
namespace TT\Modules\SeedReview;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\I18n\I18nModule;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

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
 * Translations (#0090 Phase 5 — v3.110.29): for every entity registered
 * with `TranslatableFieldRegistry`, the export emits one
 * `<field>_<locale>` column per (translatable field × registered
 * locale). Cells are populated from `TranslationsRepository::allFor()`.
 * On re-import, edits to those columns flow into `tt_translations`
 * via `TranslationsRepository::upsert()` instead of the source
 * table's canonical column. The canonical column (`name` / `label`)
 * stays as the immovable English backstop.
 *
 * Prior shape (v3.6 → v3.110.28): a single read-only `label_nl` column
 * computed via `switch_to_locale('nl_NL') + __()`. That column is gone
 * — translations are now first-class editable per locale.
 */
final class SeedExporter {

    public static function streamDownload( string $filename = 'talenttrack-seed-review.xlsx' ): bool {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            return false;
        }
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex( 0 );

        $repo = new TranslationsRepository();

        self::buildLookupsSheet( $book, $repo );
        self::buildEvalCategoriesSheet( $book, $repo );
        self::buildRolesSheet( $book, $repo );
        self::buildFunctionalRolesSheet( $book, $repo );

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
     * Generate `<field>_<locale>` column names for the entity. Columns
     * appear in `(field, locale)` order so a future locale rolls in at
     * the end of each field group rather than splaying across the
     * sheet. Caller appends these to the static base columns.
     *
     * @return list<string>
     */
    private static function translationColumnNames( string $entity_type ): array {
        $fields  = TranslatableFieldRegistry::fieldsFor( $entity_type );
        $locales = I18nModule::REGISTERED_LOCALES;
        $cols    = [];
        foreach ( $fields as $field ) {
            foreach ( $locales as $locale ) {
                $cols[] = $field . '_' . $locale;
            }
        }
        return $cols;
    }

    /**
     * Look up `(field, locale) → value` for the row and write each
     * value into its corresponding column. Empty translation = empty
     * cell. The base column index is the sheet column where the
     * translation block starts.
     */
    private static function writeTranslationCells(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        TranslationsRepository $repo,
        string $entity_type,
        int $entity_id,
        int $row_idx,
        int $start_col_idx
    ): int {
        $existing = $repo->allFor( $entity_type, $entity_id );
        $col      = $start_col_idx;
        foreach ( TranslatableFieldRegistry::fieldsFor( $entity_type ) as $field ) {
            foreach ( I18nModule::REGISTERED_LOCALES as $locale ) {
                $value = isset( $existing[ $field ][ $locale ] ) ? (string) $existing[ $field ][ $locale ] : '';
                $sheet->setCellValueByColumnAndRow( $col, $row_idx, $value );
                $col++;
            }
        }
        return $col;
    }

    /**
     * Render `tt_lookups` rows.
     */
    private static function buildLookupsSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book, TranslationsRepository $repo ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Lookups' );

        $base_headers       = [ 'table', 'id', 'lookup_type', 'name', 'description' ];
        $tx_headers         = self::translationColumnNames( TranslatableFieldRegistry::ENTITY_LOOKUP );
        $trailing_headers   = [ 'sort_order', 'meta_color', 'locked', 'notes' ];
        $headers            = array_merge( $base_headers, $tx_headers, $trailing_headers );
        self::writeHeader( $sheet, $headers );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, lookup_type, name, description, sort_order, meta
               FROM {$wpdb->prefix}tt_lookups
              WHERE club_id = %d
              ORDER BY lookup_type ASC, sort_order ASC, name ASC",
            CurrentClub::id()
        ) );

        $base_count = count( $base_headers );
        $tx_count   = count( $tx_headers );
        $row_idx    = 2;
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
            self::writeTranslationCells(
                $sheet, $repo, TranslatableFieldRegistry::ENTITY_LOOKUP, (int) $r->id, $row_idx, $base_count + 1
            );
            $trailing_col = $base_count + $tx_count + 1;
            $sheet->setCellValueByColumnAndRow( $trailing_col,     $row_idx, (int) ( $r->sort_order ?? 0 ) );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 1, $row_idx, (string) ( $meta_arr['color'] ?? '' ) );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 2, $row_idx, ! empty( $meta_arr['locked'] ) ? 'yes' : 'no' );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 3, $row_idx, '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
    }

    /**
     * Render `tt_eval_categories`. Hierarchy via `parent_id`; main
     * categories sort before their children.
     */
    private static function buildEvalCategoriesSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book, TranslationsRepository $repo ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Eval categories' );

        $base_headers     = [ 'table', 'id', 'parent_id', 'kind', 'label' ];
        $tx_headers       = self::translationColumnNames( TranslatableFieldRegistry::ENTITY_EVAL_CATEGORY );
        $trailing_headers = [ 'display_order', 'is_active', 'rating_max', 'meta', 'notes' ];
        $headers          = array_merge( $base_headers, $tx_headers, $trailing_headers );
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

        $base_count = count( $base_headers );
        $tx_count   = count( $tx_headers );
        $row_idx    = 2;
        foreach ( (array) $rows as $r ) {
            $kind = $r->parent_id === null ? 'main' : 'sub';
            $sheet->setCellValue( "A{$row_idx}", 'tt_eval_categories' );
            $sheet->setCellValue( "B{$row_idx}", (int) $r->id );
            $sheet->setCellValue( "C{$row_idx}", $r->parent_id !== null ? (int) $r->parent_id : '' );
            $sheet->setCellValue( "D{$row_idx}", $kind );
            $sheet->setCellValue( "E{$row_idx}", (string) $r->label );
            self::writeTranslationCells(
                $sheet, $repo, TranslatableFieldRegistry::ENTITY_EVAL_CATEGORY, (int) $r->id, $row_idx, $base_count + 1
            );
            $trailing_col = $base_count + $tx_count + 1;
            $sheet->setCellValueByColumnAndRow( $trailing_col,     $row_idx, (int) ( $r->display_order ?? 0 ) );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 1, $row_idx, (int) ( $r->is_active ?? 1 ) ? 'yes' : 'no' );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 2, $row_idx, (int) ( $r->rating_max ?? 0 ) );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 3, $row_idx, (string) ( $r->meta ?? '' ) );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 4, $row_idx, '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
    }

    /**
     * Render `tt_roles` — the system role definitions.
     */
    private static function buildRolesSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book, TranslationsRepository $repo ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Roles' );

        $base_headers     = [ 'table', 'id', 'role_key', 'label' ];
        $tx_headers       = self::translationColumnNames( TranslatableFieldRegistry::ENTITY_ROLE );
        $trailing_headers = [ 'is_system', 'notes' ];
        $headers          = array_merge( $base_headers, $tx_headers, $trailing_headers );
        self::writeHeader( $sheet, $headers );

        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_roles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            return;
        }
        $rows = $wpdb->get_results( "SELECT id, role_key, label, is_system FROM {$tbl} ORDER BY role_key" );

        $base_count = count( $base_headers );
        $tx_count   = count( $tx_headers );
        $row_idx    = 2;
        foreach ( (array) $rows as $r ) {
            $sheet->setCellValue( "A{$row_idx}", 'tt_roles' );
            $sheet->setCellValue( "B{$row_idx}", (int) $r->id );
            $sheet->setCellValue( "C{$row_idx}", (string) $r->role_key );
            $sheet->setCellValue( "D{$row_idx}", (string) $r->label );
            self::writeTranslationCells(
                $sheet, $repo, TranslatableFieldRegistry::ENTITY_ROLE, (int) $r->id, $row_idx, $base_count + 1
            );
            $trailing_col = $base_count + $tx_count + 1;
            $sheet->setCellValueByColumnAndRow( $trailing_col,     $row_idx, (int) ( $r->is_system ?? 0 ) ? 'yes' : 'no' );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 1, $row_idx, '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
    }

    /**
     * Render `tt_functional_roles` — team-staff role types.
     */
    private static function buildFunctionalRolesSheet( \PhpOffice\PhpSpreadsheet\Spreadsheet $book, TranslationsRepository $repo ): void {
        $sheet = $book->createSheet();
        $sheet->setTitle( 'Functional roles' );

        $base_headers     = [ 'table', 'id', 'role_key', 'label' ];
        $tx_headers       = self::translationColumnNames( TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE );
        $trailing_headers = [ 'sort_order', 'is_active', 'notes' ];
        $headers          = array_merge( $base_headers, $tx_headers, $trailing_headers );
        self::writeHeader( $sheet, $headers );

        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_functional_roles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            return;
        }
        $rows = $wpdb->get_results( "SELECT id, role_key, label, sort_order, is_active FROM {$tbl} ORDER BY sort_order, role_key" );

        $base_count = count( $base_headers );
        $tx_count   = count( $tx_headers );
        $row_idx    = 2;
        foreach ( (array) $rows as $r ) {
            $sheet->setCellValue( "A{$row_idx}", 'tt_functional_roles' );
            $sheet->setCellValue( "B{$row_idx}", (int) $r->id );
            $sheet->setCellValue( "C{$row_idx}", (string) $r->role_key );
            $sheet->setCellValue( "D{$row_idx}", (string) $r->label );
            self::writeTranslationCells(
                $sheet, $repo, TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE, (int) $r->id, $row_idx, $base_count + 1
            );
            $trailing_col = $base_count + $tx_count + 1;
            $sheet->setCellValueByColumnAndRow( $trailing_col,     $row_idx, (int) ( $r->sort_order ?? 0 ) );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 1, $row_idx, (int) ( $r->is_active ?? 1 ) ? 'yes' : 'no' );
            $sheet->setCellValueByColumnAndRow( $trailing_col + 2, $row_idx, '' );
            $row_idx++;
        }
        self::freezeAndAutosize( $sheet, count( $headers ) );
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
