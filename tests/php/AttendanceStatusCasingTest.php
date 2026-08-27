<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2909 — `tt_attendance.status` now has a canonical casing, and
 * AttendanceStatus is the authority for it.
 *
 * The bug this locks down: `$row->status === AttendanceStatus::PRESENT` used
 * to be silently false on most real rows, because the constants said
 * 'present' while the seed and both writers said 'Present'. It survived for
 * years because WordPress creates these tables with a `_ci` collation, so
 * every SQL comparison kept working while every strict PHP comparison failed.
 */
final class AttendanceStatusCasingTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_canonical_members_are_title_case(): void {
        $this->assertSame( 'Present', AttendanceStatus::PRESENT );
        $this->assertSame( 'Absent',  AttendanceStatus::ABSENT );
        $this->assertSame( 'Late',    AttendanceStatus::LATE );
        $this->assertSame( 'Excused', AttendanceStatus::EXCUSED );
        $this->assertSame( 'Injured', AttendanceStatus::INJURED );
    }

    public function test_normalise_folds_any_casing_to_the_canonical_member(): void {
        $this->assertSame( 'Present', AttendanceStatus::normalise( 'present' ) );
        $this->assertSame( 'Present', AttendanceStatus::normalise( 'PRESENT' ) );
        $this->assertSame( 'Present', AttendanceStatus::normalise( '  Present  ' ) );
        $this->assertSame( 'Excused', AttendanceStatus::normalise( 'eXcUsEd' ) );
    }

    /**
     * An academy that added its own status through the lookups admin keeps it.
     * The migration and every write path pass unknown values through
     * untouched — their vocabulary is their data.
     */
    public function test_normalise_returns_null_for_a_custom_status(): void {
        $this->assertNull( AttendanceStatus::normalise( 'Op vakantie' ) );
        $this->assertNull( AttendanceStatus::normalise( '' ) );
    }

    /**
     * The migration is the part that touches stored data, so it gets a real
     * round trip: lowercase rows in, canonical rows out, and a re-run that
     * changes nothing.
     */
    public function test_migration_normalises_stored_rows_and_is_idempotent(): void {
        global $wpdb;

        $team_id     = $this->insertTeam();
        $player_id   = $this->insertPlayer( $team_id );
        $activity_id = $this->insertActivity( $team_id );

        $lower  = $this->insertAttendance( $activity_id, $player_id, 'present' );
        $upper  = $this->insertAttendance( $activity_id, $player_id, 'ABSENT' );
        $good   = $this->insertAttendance( $activity_id, $player_id, 'Late' );
        $custom = $this->insertAttendance( $activity_id, $player_id, 'Op vakantie' );

        $this->runMigration();

        $this->assertSame( 'Present', $this->statusOf( $lower ),
            'a lowercase row must be folded to the canonical member' );
        $this->assertSame( 'Absent', $this->statusOf( $upper ),
            'an uppercase row must be folded too' );
        $this->assertSame( 'Late', $this->statusOf( $good ),
            'an already-canonical row is left alone' );
        $this->assertSame( 'Op vakantie', $this->statusOf( $custom ),
            "an academy's own status is not ours to rewrite" );

        // Re-running must be a no-op, not a second rewrite.
        $this->runMigration();
        $this->assertSame( 'Present', $this->statusOf( $lower ) );
        $this->assertSame( 'Op vakantie', $this->statusOf( $custom ) );
    }

    /**
     * The point of the whole exercise: strict comparison works on stored data.
     */
    public function test_strict_comparison_holds_after_the_migration(): void {
        global $wpdb;
        $team_id     = $this->insertTeam();
        $player_id   = $this->insertPlayer( $team_id );
        $activity_id = $this->insertActivity( $team_id );
        $id          = $this->insertAttendance( $activity_id, $player_id, 'present' );

        $this->runMigration();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status FROM {$this->p}tt_attendance WHERE id = %d", $id
        ) );

        $this->assertTrue( $row->status === AttendanceStatus::PRESENT,
            'this identity is the bug #2909 exists to fix' );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function runMigration(): void {
        $path = dirname( __DIR__, 2 ) . '/database/migrations/0241_attendance_status_casing.php';
        $this->assertFileExists( $path );
        $migration = require $path;
        $migration->up();
    }

    private function statusOf( int $id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$this->p}tt_attendance WHERE id = %d", $id
        ) );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 casing' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Cas',
            'last_name'  => 'Ing',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training casing',
            'session_date'        => '2020-09-01',
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
        return (int) $wpdb->insert_id;
    }
}
