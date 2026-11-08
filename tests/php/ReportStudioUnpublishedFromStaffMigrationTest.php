<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4903 — migration 0318: publications to the generic staff persona are
 * removed; a report also published to other personas stays published to
 * them; a second run changes nothing. Kept apart from the REST tests, whose
 * publishes commit.
 */
final class ReportStudioUnpublishedFromStaffMigrationTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0318_report_studio_unpublished_from_staff.php';

    private string $publications = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->publications = "{$wpdb->prefix}tt_report_publications";
    }

    public function test_it_removes_staff_publications_and_keeps_the_others_even_when_run_twice(): void {
        global $wpdb;
        // One report published to staff only, one to staff and the head coach,
        // one never published to staff.
        foreach ( [ [ 987601, 'staff' ], [ 987602, 'staff' ], [ 987602, 'head_coach' ], [ 987603, 'team_manager' ] ] as [ $report, $persona ] ) {
            $wpdb->insert( $this->publications, [ 'club_id' => 1, 'report_id' => $report, 'persona_key' => $persona, 'published_by' => 1 ] );
        }

        $this->migration()->up();
        $after_first = $this->rows();
        // Twice, because a re-run must change nothing.
        $this->migration()->up();

        $this->assertSame( $after_first, $this->rows(), 'a second run changes nothing' );
        $this->assertSame(
            [ '987602|head_coach', '987603|team_manager' ],
            $after_first,
            'every staff publication is gone; the other personas keep theirs'
        );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->publications} WHERE persona_key = %s", 'staff' ) ) );
    }

    public function test_the_migration_carries_its_file_name(): void {
        $this->assertSame( '0318_report_studio_unpublished_from_staff', $this->migration()->getName() );
    }

    /** @return list<string> report|persona, in order */
    private function rows(): array {
        global $wpdb;
        $rows = (array) $wpdb->get_col(
            "SELECT CONCAT( report_id, '|', persona_key ) FROM {$this->publications}
              WHERE report_id IN ( 987601, 987602, 987603 ) ORDER BY report_id, persona_key"
        );
        return array_values( array_map( 'strval', $rows ) );
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }
}
