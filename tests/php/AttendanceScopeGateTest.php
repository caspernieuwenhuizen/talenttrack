<?php
namespace TT\Tests\Php;

use PHPUnit\Framework\TestCase;

/**
 * #3451 — the gate that catches the eleventh instance.
 *
 * `tools/check-attendance-scope.php` fails a PR whose query against
 * `tt_attendance` does not say whether it means the planned squad or the
 * recorded register. A gate is only worth having if it fails on the thing it
 * exists to catch AND passes on the thing it must not block, so both
 * directions are here — the same reason every reader fix in this issue is
 * asserted twice.
 *
 * Snippets rather than the repository: what the gate says about the code as
 * it stands today is the gate's job to report, not this test's to freeze.
 */
final class AttendanceScopeGateTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname( __DIR__, 2 ) . '/tools/lib/attendance-scope.php';
    }

    public function test_an_unscoped_query_is_reported(): void {
        $unit = $this->only( '
            function wasPlayerPresent( $wpdb, $activity_id, $player_id ) {
                return $wpdb->get_var( $wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}tt_attendance
                      WHERE activity_id = %d AND player_id = %d",
                    $activity_id, $player_id
                ) );
            }
        ' );

        $this->assertFalse( $unit['scoped'] );
        $this->assertFalse( $unit['marked'] );
        $this->assertSame( 'wasPlayerPresent()', $unit['name'] );
    }

    public function test_a_scoped_query_passes(): void {
        $unit = $this->only( '
            function wasPlayerPresent( $wpdb, $activity_id, $player_id ) {
                return $wpdb->get_var( $wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}tt_attendance
                      WHERE activity_id = %d AND player_id = %d
                        AND record_type = \'actual\'",
                    $activity_id, $player_id
                ) );
            }
        ' );

        $this->assertTrue( $unit['scoped'] );
    }

    public function test_a_marked_query_passes(): void {
        $unit = $this->only( '
            function everythingHeldAbout( $wpdb, $player_id ) {
                /* both-kinds-ok */
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}tt_attendance WHERE player_id = %d",
                    $player_id
                ) );
            }
        ' );

        $this->assertFalse( $unit['scoped'], 'it is not scoped...' );
        $this->assertTrue( $unit['marked'], '...it is marked, which is the other way to pass' );
    }

    /**
     * An INSERT naming the column in its map counts. The writer's own
     * methods are shaped like this, and a gate that only understood WHERE
     * clauses would have flagged every one of them.
     */
    public function test_naming_the_column_in_a_write_counts(): void {
        $unit = $this->only( '
            function record( $wpdb, $activity_id, $player_id ) {
                $wpdb->insert( $wpdb->prefix . "tt_attendance", [
                    "activity_id" => $activity_id,
                    "player_id"   => $player_id,
                    "record_type" => "actual",
                ] );
            }
        ' );

        $this->assertTrue( $unit['scoped'] );
    }

    /**
     * A query assembled in pieces is scoped by the `$record_type` it binds,
     * and the unit is the function precisely so this is not reported. The
     * alternative was a statement-level check that called
     * `lineupProjectionFor()` a violation and pushed somebody towards a
     * marker that would have been a lie.
     */
    public function test_a_query_scoped_by_a_parameter_passes(): void {
        $unit = $this->only( '
            function projection( $wpdb, $activity_id, $record_type ) {
                $sql  = "SELECT id FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d";
                $args = [ $activity_id ];
                if ( $record_type !== null ) {
                    $sql   .= " AND record_type = %s";
                    $args[] = $record_type;
                }
                return $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
            }
        ' );

        $this->assertTrue( $unit['scoped'] );
    }

    /**
     * A docblock that talks about the distinction is not a query that
     * honours it. Comments are stripped before the source is read, or every
     * file explaining the split would have exempted itself.
     */
    public function test_a_docblock_mentioning_the_column_does_not_count(): void {
        $unit = $this->only( '
            /**
             * Reads attendance. The record_type column separates the planned
             * squad from the recorded register.
             */
            function loose( $wpdb, $activity_id ) {
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d",
                    $activity_id
                ) );
            }
        ' );

        $this->assertFalse( $unit['scoped'], 'describing the rule is not following it' );
        $this->assertFalse( $unit['marked'] );
    }

    /**
     * A closure is judged on its own. Inheriting the enclosing function's
     * scoping would let one careful query exempt a careless one beside it.
     */
    public function test_a_closure_does_not_inherit_its_parents_scoping(): void {
        $units = $this->units( '
            function outer( $wpdb, $activity_id ) {
                $scoped = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance
                      WHERE activity_id = %d AND record_type = \'actual\'",
                    $activity_id
                ) );
                $loose = function () use ( $wpdb, $activity_id ) {
                    return $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d",
                        $activity_id
                    ) );
                };
                return [ $scoped, $loose ];
            }
        ' );

        $by_name = [];
        foreach ( $units as $unit ) {
            $by_name[ $unit['name'] ] = $unit;
        }

        $this->assertArrayHasKey( 'closure', $by_name, 'the closure is its own unit' );
        $this->assertFalse( $by_name['closure']['scoped'] );
    }

    /** A file that never mentions the table produces nothing to judge. */
    public function test_an_unrelated_file_is_not_reported(): void {
        $this->assertSame( [], $this->units( '
            function elsewhere( $wpdb ) {
                return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tt_players" );
            }
        ' ) );
    }

    /**
     * @return array{name:string, line:int, scoped:bool, marked:bool}
     */
    private function only( string $body ): array {
        $units = $this->units( $body );
        $this->assertCount( 1, $units, 'the snippet holds exactly one unit to judge' );
        return $units[0];
    }

    /**
     * @return list<array{name:string, line:int, scoped:bool, marked:bool}>
     */
    private function units( string $body ): array {
        return tt_attendance_units( "<?php\n" . $body );
    }
}
