<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Import\Excel\SheetSchemas;
use TT\Modules\Import\Excel\TemplateBuilder;
use TT\Modules\Import\ImportService;

/**
 * #3269 — the importer must accept the plugin's own template.
 *
 * It did not. `ExcelImporter` skips blank rows, but tested
 * `getCell()->getValue()`, which returns a formula's **text** rather than
 * what it evaluates to. The template's `auto_key` column is a formula, so
 * all 200 pre-formatted rows per sheet looked like rows somebody had
 * filled in, survived the blank-row skip, and each raised a "Name is
 * required" blocker. Five sheets, a thousand blockers, and the documented
 * flow — download the template, fill it in, upload it — could not succeed
 * on either surface.
 *
 * These tests drive the **real shipped template** rather than a fixture
 * built to suit them. The template and the importer are two halves of one
 * contract, and only a test that uses the real one catches this: a
 * hand-built workbook with a literal `auto_key` passes against the broken
 * code.
 */
final class ExcelImporterTemplateTest extends WP_UnitTestCase {

    /** @var list<string> */
    private array $tmp_files = [];

    public function set_up(): void {
        parent::set_up();

        if ( ! class_exists( \PhpOffice\PhpSpreadsheet\IOFactory::class ) ) {
            $this->markTestSkipped( 'PhpSpreadsheet is not installed on this runner.' );
        }
    }

    public function tear_down(): void {
        foreach ( $this->tmp_files as $f ) {
            if ( is_file( $f ) ) unlink( $f );
        }
        $this->tmp_files = [];
        parent::tear_down();
    }

    /**
     * The shipped template, written to a temp file.
     *
     * `build()` rather than `streamRosterDownload()`: the streaming variant
     * sends `Content-Type` and friends, and under PHPUnit the headers have
     * already gone out — a warning on a dev box, a fatal in wp-env. The
     * builder exists for exactly this, and it is the same workbook either
     * way, so nothing about the fixture's fidelity is given up.
     */
    private function template(): string {
        $book = TemplateBuilder::build( SheetSchemas::rosterSubset() );
        if ( $book === null ) {
            $this->markTestSkipped( 'The template could not be generated on this runner.' );
        }

        return $this->save( $book );
    }

    /**
     * Write rows under a sheet's header, leaving `auto_key` alone so its
     * formula is what the importer has to cope with — which is the point.
     *
     * @param array<string,mixed>              $schema
     * @param list<array<string,string>>       $rows
     */
    private function fill( \PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $schema, array $rows ): void {
        $sheet = $book->getSheetByName( $schema['sheet'] );
        $this->assertNotNull( $sheet, "The template has no {$schema['sheet']} sheet." );

        $labels = array_column( $schema['columns'], 'label' );
        $header = 0;
        for ( $r = 1; $r <= 12; $r++ ) {
            if ( (string) $sheet->getCell( 'A' . $r )->getValue() === (string) $labels[0] ) { $header = $r; break; }
        }
        $this->assertGreaterThan( 0, $header, "Could not find the header row on {$schema['sheet']}." );

        foreach ( $rows as $i => $row ) {
            $col = 1;
            foreach ( array_keys( $schema['columns'] ) as $key ) {
                if ( $key !== 'auto_key' ) {
                    $sheet->setCellValue(
                        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col ) . ( $header + 1 + $i ),
                        (string) ( $row[ $key ] ?? '' )
                    );
                }
                $col++;
            }
        }
    }

    private function save( \PhpOffice\PhpSpreadsheet\Spreadsheet $book ): string {
        $path = tempnam( sys_get_temp_dir(), 'tt-book' ) . '.xlsx';
        $this->tmp_files[] = $path;
        ( new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book ) )->save( $path );
        return $path;
    }

    private function load( string $path ): \PhpOffice\PhpSpreadsheet\Spreadsheet {
        return \PhpOffice\PhpSpreadsheet\IOFactory::createReader( 'Xlsx' )->load( $path );
    }

    // ── the bug ────────────────────────────────────────────────────────

    /**
     * A blank template is an empty workbook, not a thousand broken rows.
     * This is the assertion that fails against the old code, with exactly
     * the count the bug produced.
     */
    public function test_a_blank_template_reports_no_rows_and_no_blockers(): void {
        $result = ( new ImportService() )->preview( $this->template(), 'roster.xlsx' );

        $this->assertSame(
            [],
            array_values( (array) ( $result['blockers'] ?? [] ) ),
            'The plugin\'s own blank template must not be refused.'
        );
        $this->assertNotEmpty( $result['ok'] ?? null );
    }

    /** The documented flow: download, fill in a few rows, upload. */
    public function test_a_filled_template_previews_cleanly_and_writes_nothing(): void {
        global $wpdb;

        $schemas = SheetSchemas::all();
        $book    = $this->load( $this->template() );

        $this->fill( $book, $schemas['teams'], [
            [ 'name' => 'Importer Test U15', 'age_group' => 'JO15', 'level' => '', 'notes' => '' ],
        ] );
        $this->fill( $book, $schemas['players'], [
            [ 'first_name' => 'Import', 'last_name' => 'One', 'date_of_birth' => '2011-04-02' ],
            [ 'first_name' => 'Import', 'last_name' => 'Two', 'date_of_birth' => '2011-08-19' ],
        ] );

        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players" );

        $result = ( new ImportService() )->preview( $this->save( $book ), 'roster.xlsx' );

        $this->assertSame( [], array_values( (array) ( $result['blockers'] ?? [] ) ) );
        $this->assertSame( 1, (int) ( $result['imported']['teams'] ?? 0 ) );
        $this->assertSame( 2, (int) ( $result['imported']['players'] ?? 0 ) );
        $this->assertSame(
            $before,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players" ),
            'A preview reports; it does not write.'
        );
    }

    /** And committing that same workbook writes exactly those rows. */
    public function test_committing_writes_the_rows(): void {
        global $wpdb;

        $schemas = SheetSchemas::all();
        $book    = $this->load( $this->template() );

        $this->fill( $book, $schemas['teams'], [
            [ 'name' => 'Importer Test U15', 'age_group' => 'JO15', 'level' => '', 'notes' => '' ],
        ] );
        $this->fill( $book, $schemas['players'], [
            [ 'first_name' => 'Import', 'last_name' => 'One', 'date_of_birth' => '2011-04-02' ],
            [ 'first_name' => 'Import', 'last_name' => 'Two', 'date_of_birth' => '2011-08-19' ],
        ] );

        $before_players = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players" );
        $before_teams   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_teams" );

        $result = ( new ImportService() )->import( $this->save( $book ), 'roster.xlsx' );

        $this->assertNotEmpty( $result['ok'] ?? null );
        $this->assertSame(
            $before_players + 2,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players" )
        );
        $this->assertSame(
            $before_teams + 1,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_teams" )
        );
    }

    // ── and the thing the fix must not break ───────────────────────────

    /**
     * A row somebody genuinely started and left incomplete still blocks,
     * and still names the row. Skipping blank rows must not become
     * skipping rows with a missing required field.
     */
    public function test_a_row_with_a_missing_required_field_still_blocks(): void {
        $schemas = SheetSchemas::all();
        $book    = $this->load( $this->template() );

        // Age group typed, Name left empty — a real half-filled row.
        $this->fill( $book, $schemas['teams'], [
            [ 'name' => '', 'age_group' => 'JO15', 'level' => '', 'notes' => '' ],
        ] );

        $result = ( new ImportService() )->preview( $this->save( $book ), 'roster.xlsx' );

        $this->assertEmpty( $result['ok'] ?? null, 'A missing required field is still a blocker.' );
        $this->assertNotEmpty( (array) ( $result['blockers'] ?? [] ) );
    }

    /**
     * The second half of the same bug. `auto_key` is what cross-sheet
     * references point at, so reading its formula text rather than its
     * value meant a player's `team_key` could never match a team — the
     * import would have "succeeded" with every player unassigned.
     */
    public function test_a_cross_sheet_key_resolves_to_the_imported_team(): void {
        global $wpdb;

        $schemas = SheetSchemas::all();
        $book    = $this->load( $this->template() );

        $this->fill( $book, $schemas['teams'], [
            [ 'name' => 'Key Resolution U15', 'age_group' => 'JO15', 'level' => '', 'notes' => '' ],
        ] );

        // Read back what the template's formula computed for that row, and
        // point the player at it — exactly as a user filling the sheet in
        // Excel would see and copy.
        $teams_sheet = $book->getSheetByName( $schemas['teams']['sheet'] );
        $auto_key    = '';
        for ( $r = 2; $r <= 12; $r++ ) {
            $v = (string) $teams_sheet->getCell( 'A' . $r )->getCalculatedValue();
            if ( $v !== '' ) { $auto_key = $v; break; }
        }
        $this->assertNotSame( '', $auto_key, 'The template should compute an auto_key for a named team.' );

        $this->fill( $book, $schemas['players'], [
            [ 'first_name' => 'Keyed', 'last_name' => 'Player', 'team_key' => $auto_key, 'date_of_birth' => '2011-04-02' ],
        ] );

        ( new ImportService() )->import( $this->save( $book ), 'roster.xlsx' );

        $landed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players pl
               JOIN {$wpdb->prefix}tt_teams t ON t.id = pl.team_id
              WHERE pl.last_name = 'Player' AND t.name = 'Key Resolution U15'"
        );

        $this->assertSame( 1, $landed, 'A player whose team_key matches a team must land on that team.' );
    }
}
