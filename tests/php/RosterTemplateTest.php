<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Import\Excel\SheetSchemas;
use TT\Modules\Import\Excel\TemplateBuilder;

/**
 * #2957 — the three-sheet roster workbook a club fills on day one.
 *
 * The point of the slice is that this is a *selector*, not a second
 * format: the roster sheets are the same schemas the full template emits,
 * so a roster workbook imports through exactly the same code path. The
 * tests below pin that, and pin the sheet ORDER, which is the part that
 * matters to someone filling it in — team keys have to exist before the
 * tabs that reference them.
 */
final class RosterTemplateTest extends WP_UnitTestCase {

    public function test_roster_subset_is_teams_then_players_then_people(): void {
        $keys = array_keys( SheetSchemas::rosterSubset() );

        $this->assertSame( [ 'teams', 'players', 'people' ], $keys );
    }

    public function test_roster_schemas_are_the_same_objects_the_full_set_uses(): void {
        $all    = SheetSchemas::all();
        $roster = SheetSchemas::rosterSubset();

        foreach ( $roster as $key => $schema ) {
            $this->assertSame( $all[ $key ], $schema, "roster schema '$key' diverged from the full set" );
        }
    }

    public function test_every_roster_sheet_is_one_the_importer_processes(): void {
        // A template sheet the importer ignores would silently drop the
        // club's squad on the floor.
        foreach ( SheetSchemas::ROSTER_SHEETS as $key ) {
            $this->assertContains( $key, SheetSchemas::IMPORTABLE_SHEETS, "'$key' is not importable" );
        }
    }

    public function test_the_staff_sheet_is_still_named_people(): void {
        // Renaming it would break every workbook already in the wild —
        // the importer matches sheets by name.
        $roster = SheetSchemas::rosterSubset();

        $this->assertSame( 'People', $roster['people']['sheet'] );
        $this->assertSame( 'Teams', $roster['teams']['sheet'] );
        $this->assertSame( 'Players', $roster['players']['sheet'] );
    }

    public function test_staff_rows_can_carry_a_team_key(): void {
        // Staff arrive attached to a team, which is the whole reason the
        // People sheet is in the roster subset rather than Teams alone.
        $people = SheetSchemas::rosterSubset()['people'];

        $this->assertArrayHasKey( 'team_key', $people['columns'] );
        $this->assertSame( 'teams.auto_key', $people['columns']['team_key']['fk'] );
        $this->assertArrayHasKey( 'role', $people['columns'] );
    }

    public function test_built_roster_workbook_has_exactly_the_four_expected_tabs(): void {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            $this->markTestSkipped( 'PhpSpreadsheet not installed' );
        }

        $book = TemplateBuilder::build( SheetSchemas::rosterSubset() );
        $this->assertNotNull( $book );

        $this->assertSame(
            [ '_README', 'Teams', 'Players', 'People' ],
            $book->getSheetNames()
        );
    }

    public function test_full_template_still_emits_every_sheet(): void {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            $this->markTestSkipped( 'PhpSpreadsheet not installed' );
        }

        $book  = TemplateBuilder::build();
        $names = $book->getSheetNames();

        $this->assertSame( '_README', $names[0] );
        $this->assertCount( count( SheetSchemas::all() ) + 1, $names );
    }

    public function test_roster_header_rows_carry_the_schema_labels(): void {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            $this->markTestSkipped( 'PhpSpreadsheet not installed' );
        }

        $book  = TemplateBuilder::build( SheetSchemas::rosterSubset() );
        $teams = $book->getSheetByName( 'Teams' );

        $this->assertNotNull( $teams );
        $this->assertSame( 'auto_key', $teams->getCell( 'A1' )->getValue() );
        $this->assertSame( 'Name', $teams->getCell( 'B1' )->getValue() );
    }

    public function test_readme_headings_are_bold_and_body_text_is_not(): void {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            $this->markTestSkipped( 'PhpSpreadsheet not installed' );
        }

        $book   = TemplateBuilder::build();
        $readme = $book->getSheetByName( '_README' );
        $this->assertNotNull( $readme );

        // Row 10 is a section heading ("Foreign-key columns…"), row 11 the
        // paragraph under it. Before #2957 the hard-coded indices had these
        // the wrong way round.
        $this->assertTrue( $readme->getStyle( 'A10' )->getFont()->getBold(), 'heading row is not bold' );
        $this->assertFalse( (bool) $readme->getStyle( 'A11' )->getFont()->getBold(), 'body row is bold' );
    }
}
