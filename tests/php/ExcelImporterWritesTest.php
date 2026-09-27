<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Import\ImportService;

/**
 * What the Excel import writes besides the rows it counts.
 *
 *   - #4130: a staff row with a team lands in `tt_team_people` with its
 *     functional role, and an assignment that cannot be written is named in
 *     the report rather than dropped.
 *   - #4121: every activity the import writes as completed fires
 *     `tt_activity_marked_completed` once, not once per attendance row.
 *
 * The workbook is built here with literal keys: the template's formulas are
 * `ExcelImporterTemplateTest`'s subject, not this one's.
 */
final class ExcelImporterWritesTest extends WP_UnitTestCase {

    /** @var list<string> */
    private array $tmp_files = [];

    /** @var list<int> */
    private array $completed = [];

    public function set_up(): void {
        parent::set_up();
        if ( ! class_exists( \PhpOffice\PhpSpreadsheet\IOFactory::class ) ) {
            $this->markTestSkipped( 'PhpSpreadsheet is not installed on this runner.' );
        }
        $this->ensureRole( 'head_coach', 'Head Coach' );
        $this->ensureRole( 'other', 'Other' );
    }

    public function tear_down(): void {
        foreach ( $this->tmp_files as $f ) {
            if ( is_file( $f ) ) unlink( $f );
        }
        remove_action( 'tt_activity_marked_completed', [ $this, 'recordCompleted' ] );
        parent::tear_down();
    }

    public function recordCompleted( $activity_id ): void {
        $this->completed[] = (int) $activity_id;
    }

    // ── #4130 ──────────────────────────────────────────────────────────

    public function test_a_staff_row_lands_on_its_team_with_its_role(): void {
        global $wpdb;
        $result = ( new ImportService() )->import( $this->book( [
            'Teams'  => [ [ 'auto_key', 'Name' ], [ 'T1', 'Import Staff JO13-1' ] ],
            'People' => [
                [ 'auto_key', 'First name', 'Last name', 'Role', 'Team key' ],
                [ 'P1', 'Sanne', 'Importstaff', 'Head coach', 'T1' ],
            ],
        ] ), 'staff.xlsx' );
        $this->assertNotEmpty( $result['ok'] ?? null, implode( '; ', (array) ( $result['blockers'] ?? [] ) ) );

        $row = $wpdb->get_row(
            "SELECT tp.role_in_team, tp.is_head_coach, tp.functional_role_id
               FROM {$wpdb->prefix}tt_team_people tp
               JOIN {$wpdb->prefix}tt_people pe ON pe.id = tp.person_id
               JOIN {$wpdb->prefix}tt_teams t ON t.id = tp.team_id
              WHERE pe.last_name = 'Importstaff' AND t.name = 'Import Staff JO13-1'"
        );
        $this->assertNotNull( $row, 'the assignment was written' );
        $this->assertSame( 'head_coach', (string) $row->role_in_team );
        $this->assertSame( 1, (int) $row->is_head_coach );
        $this->assertGreaterThan( 0, (int) $row->functional_role_id );
        $this->assertSame( [], $this->assignmentWarnings( $result ) );
    }

    public function test_an_assignment_that_cannot_be_written_is_reported(): void {
        global $wpdb;
        // No role to fall back on: the typed role matches nothing and
        // `other` is gone, so nothing can be written.
        $wpdb->delete( "{$wpdb->prefix}tt_functional_roles", [ 'role_key' => 'other', 'club_id' => CurrentClub::id() ] );

        $result = ( new ImportService() )->import( $this->book( [
            'Teams'  => [ [ 'auto_key', 'Name' ], [ 'T1', 'Import Staff JO14-1' ] ],
            'People' => [
                [ 'auto_key', 'First name', 'Last name', 'Role', 'Team key' ],
                [ 'P1', 'Kees', 'Unassignable', 'Kit wizard', 'T1' ],
            ],
        ] ), 'staff.xlsx' );

        $this->assertNotEmpty( $result['ok'] ?? null, 'the person still imports' );
        $this->assertCount( 1, $this->assignmentWarnings( $result ), 'and the failed assignment is named' );
    }

    // ── #4121 ──────────────────────────────────────────────────────────

    public function test_each_imported_activity_fires_the_completed_hook_once(): void {
        add_action( 'tt_activity_marked_completed', [ $this, 'recordCompleted' ] );
        $yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

        ( new ImportService() )->import( $this->book( [
            'Teams'      => [ [ 'auto_key', 'Name' ], [ 'T1', 'Import Match JO15-1' ] ],
            'Players'    => [
                [ 'auto_key', 'First name', 'Last name', 'Team key' ],
                [ 'PL1', 'Import', 'One', 'T1' ],
                [ 'PL2', 'Import', 'Two', 'T1' ],
            ],
            'Activities' => [
                [ 'auto_key', 'Team key', 'Date', 'Title', 'Activity type' ],
                [ 'A1', 'T1', $yesterday, 'Imported match', 'game' ],
            ],
            'Session_Attendance' => [
                [ 'Session key', 'Player key', 'Status' ],
                [ 'A1', 'PL1', 'Present' ],
                [ 'A1', 'PL2', 'Present' ],
            ],
        ] ), 'matches.xlsx' );

        global $wpdb;
        $id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_activities WHERE title = 'Imported match' ORDER BY id DESC LIMIT 1"
        );
        $this->assertGreaterThan( 0, $id, 'the match was imported' );
        $this->assertSame( [ $id ], $this->completed, 'once for the match, not once per attendance row' );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $result @return list<string> */
    private function assignmentWarnings( array $result ): array {
        return array_values( array_filter(
            array_map( 'strval', (array) ( $result['warnings'] ?? [] ) ),
            static fn( string $w ): bool => strpos( $w, 'People sheet' ) !== false
        ) );
    }

    private function ensureRole( string $key, string $label ): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_functional_roles";
        $has   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE role_key = %s AND club_id = %d",
            $key, CurrentClub::id()
        ) );
        if ( $has === 0 ) {
            $wpdb->insert( $table, [ 'club_id' => CurrentClub::id(), 'role_key' => $key, 'label' => $label ] );
        }
    }

    /**
     * A workbook with one sheet per entry, the first row the headers.
     *
     * @param array<string, list<list<string>>> $sheets
     */
    private function book( array $sheets ): string {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex( 0 );
        foreach ( $sheets as $name => $rows ) {
            $sheet = $book->createSheet();
            $sheet->setTitle( $name );
            foreach ( $rows as $r => $cells ) {
                foreach ( $cells as $c => $value ) {
                    $sheet->setCellValue(
                        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c + 1 ) . ( $r + 1 ),
                        $value
                    );
                }
            }
        }
        $path = tempnam( sys_get_temp_dir(), 'tt-book' ) . '.xlsx';
        $this->tmp_files[] = $path;
        ( new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $book ) )->save( $path );
        return $path;
    }
}
