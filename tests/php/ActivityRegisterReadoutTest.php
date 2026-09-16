<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityRegisterProgress;

/**
 * #3447 — the `N/N` completeness readout on the activity list.
 *
 * The load-bearing assertion is the first one: `tt_attendance` holds the
 * planned roster and the recorded register in the same table, and the
 * planned rows carry real statuses. A readout that counted them would
 * report a full register for exactly the activity whose register is
 * missing — the failure this epic exists to make visible, relocated into
 * the fix. Three separate defects this week came from that one missing
 * predicate (#3390, #3443, #3444), so it is tested in both directions.
 *
 * `ActivityRegisterProgressTest` covers the same service's single-activity
 * verdict, which is what the completion guard asks for (#3446). This file
 * covers the batched page projection the list card reads — the numbers
 * rather than the verdict, plus minutes, plus the query count.
 */
final class ActivityRegisterReadoutTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        // The batch caches are static and outlive a fixture.
        ActivityRegisterProgress::forget();
    }

    public function tear_down(): void {
        ActivityRegisterProgress::forget();
        parent::tear_down();
    }

    // ---- actual vs planned: both directions -------------------------

    public function test_planned_rows_alone_are_not_a_register(): void {
        $team = $this->insertTeam( 'U14 planned only' );
        $a    = $this->insertActivity( $team, 'training' );
        foreach ( range( 1, 3 ) as $n ) {
            // Planned rows carry a real status — Expected maps to Present —
            // which is precisely why counting them lies.
            $this->insertAttendance( $a, $this->insertPlayer( $team, 'Plan', 'Ned' . $n ), 'Present', 'expected' );
        }

        $out = ActivityRegisterProgress::forRow( $this->row( $a, $team, 'training' ) );

        $this->assertNotNull( $out );
        $this->assertSame( 0, $out['attendance']['recorded'], 'a planned roster is not a register' );
        $this->assertSame( 3, $out['attendance']['expected'], 'the plan IS the denominator' );
        $this->assertSame( ActivityRegisterProgress::NONE, $out['attendance']['state'] );
    }

    public function test_actual_rows_are_the_register(): void {
        $team = $this->insertTeam( 'U14 recorded' );
        $a    = $this->insertActivity( $team, 'training' );
        foreach ( range( 1, 3 ) as $n ) {
            $pid = $this->insertPlayer( $team, 'Real', 'Row' . $n );
            $this->insertAttendance( $a, $pid, 'Present', 'expected' );
            $this->insertAttendance( $a, $pid, $n === 3 ? 'Absent' : 'Present', 'actual' );
        }

        $out = ActivityRegisterProgress::forRow( $this->row( $a, $team, 'training' ) );

        $this->assertNotNull( $out );
        $this->assertSame( 3, $out['attendance']['recorded'] );
        $this->assertSame( 3, $out['attendance']['expected'] );
        $this->assertSame( ActivityRegisterProgress::COMPLETE, $out['attendance']['state'] );
    }

    public function test_a_half_recorded_register_reads_partial(): void {
        $team = $this->insertTeam( 'U14 half' );
        $a    = $this->insertActivity( $team, 'training' );
        foreach ( range( 1, 4 ) as $n ) {
            $pid = $this->insertPlayer( $team, 'Half', 'Way' . $n );
            if ( $n <= 2 ) $this->insertAttendance( $a, $pid, 'Present', 'actual' );
        }

        $out = ActivityRegisterProgress::forRow( $this->row( $a, $team, 'training' ) );

        $this->assertNotNull( $out );
        $this->assertSame( 2, $out['attendance']['recorded'] );
        $this->assertSame( 4, $out['attendance']['expected'], 'no plan captured, so the roster is the denominator' );
        $this->assertSame( ActivityRegisterProgress::PARTIAL, $out['attendance']['state'] );
    }

    /**
     * The denominator is the plan where one exists, so a September
     * training keeps reading 14/14 after a player leaves in March instead
     * of drifting to 13/14 on its own.
     */
    public function test_the_plan_outranks_a_roster_that_has_since_changed(): void {
        $team = $this->insertTeam( 'U14 drifted' );
        $a    = $this->insertActivity( $team, 'training' );
        foreach ( range( 1, 3 ) as $n ) {
            $pid = $this->insertPlayer( $team, 'Was', 'There' . $n );
            $this->insertAttendance( $a, $pid, 'Present', 'expected' );
            $this->insertAttendance( $a, $pid, 'Present', 'actual' );
        }
        // One of them has since left the academy, so the fallback roster
        // would now answer 2 where the plan still answers 3.
        global $wpdb;
        $wpdb->query( "UPDATE {$this->p}tt_players SET archived_at = '2026-03-01 00:00:00' WHERE last_name = 'There3'" );

        $out = ActivityRegisterProgress::forRow( $this->row( $a, $team, 'training' ) );

        $this->assertNotNull( $out );
        $this->assertSame( 3, $out['attendance']['expected'], 'the plan, not the roster as it stands today' );
        $this->assertSame( ActivityRegisterProgress::COMPLETE, $out['attendance']['state'] );
    }

    public function test_guests_are_excluded_from_both_sides(): void {
        $team = $this->insertTeam( 'U14 guests' );
        $a    = $this->insertActivity( $team, 'training' );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Own', 'Player' ), 'Present', 'actual' );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Guest', 'Player' ), 'Present', 'actual', 1 );

        $out = ActivityRegisterProgress::forRow( $this->row( $a, $team, 'training' ) );

        $this->assertNotNull( $out );
        $this->assertSame( 1, $out['attendance']['recorded'], 'the guest row is not part of the register' );
    }

    // ---- minutes ----------------------------------------------------

    public function test_minutes_are_owed_only_by_the_players_who_played(): void {
        $team = $this->insertTeam( 'U14 match' );
        $a    = $this->insertActivity( $team, 'game' );
        $p1   = $this->insertPlayer( $team, 'On', 'Pitch' );
        $p2   = $this->insertPlayer( $team, 'Came', 'Late' );
        $p3   = $this->insertPlayer( $team, 'Stayed', 'Home' );
        $this->insertAttendance( $a, $p1, 'Present', 'actual', 0, 60 );
        $this->insertAttendance( $a, $p2, 'Late', 'actual' );
        $this->insertAttendance( $a, $p3, 'Absent', 'actual' );

        $out = ActivityRegisterProgress::forRow( $this->row( $a, $team, 'game' ) );

        $this->assertNotNull( $out );
        $this->assertNotNull( $out['minutes'] );
        $this->assertSame( 1, $out['minutes']['recorded'] );
        $this->assertSame( 2, $out['minutes']['expected'], 'an absent player is not missing minutes' );
        $this->assertSame( ActivityRegisterProgress::PARTIAL, $out['minutes']['state'] );
    }

    public function test_a_training_is_never_asked_for_minutes(): void {
        $team = $this->insertTeam( 'U14 no minutes' );
        $a    = $this->insertActivity( $team, 'training' );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Just', 'Training' ), 'Present', 'actual' );

        $out = ActivityRegisterProgress::forRow( $this->row( $a, $team, 'training' ) );

        $this->assertNotNull( $out );
        $this->assertNull( $out['minutes'] );
    }

    // ---- when the readout renders nothing at all --------------------

    public function test_nothing_renders_before_the_activity_happened(): void {
        $team = $this->insertTeam( 'U14 upcoming' );
        $a    = $this->insertActivity( $team, 'training', 'planned' );
        $this->insertPlayer( $team, 'Not', 'Yet' );

        $this->assertNull(
            ActivityRegisterProgress::forRow( $this->row( $a, $team, 'training', 'planned' ) ),
            'nothing is late on an activity that has not happened'
        );
    }

    public function test_a_meeting_has_no_register_to_be_missing(): void {
        $team = $this->insertTeam( 'U14 meeting' );
        $a    = $this->insertActivity( $team, 'meeting' );
        $this->insertPlayer( $team, 'No', 'Register' );

        $this->assertNull( ActivityRegisterProgress::forRow( $this->row( $a, $team, 'meeting' ) ) );
    }

    public function test_no_denominator_renders_nothing_rather_than_a_division_by_zero(): void {
        $a = $this->insertActivity( 0, 'training' );

        $this->assertNull(
            ActivityRegisterProgress::forRow( $this->row( $a, 0, 'training' ) ),
            'a club-wide activity with no plan has nothing to divide by'
        );
    }

    // ---- batching ---------------------------------------------------

    /**
     * `renderActivityCard()` runs inside the bucket loop, so a per-card
     * read would be an N+1 across the whole page. Asserted by query count
     * rather than by reading the code, which is what the acceptance
     * criterion asks for.
     */
    public function test_a_whole_page_costs_two_queries(): void {
        global $wpdb;
        $team = $this->insertTeam( 'U14 page' );
        $rows = [];
        foreach ( range( 1, 6 ) as $n ) {
            $a      = $this->insertActivity( $team, 'training' );
            $rows[] = $this->row( $a, $team, 'training' );
            $this->insertAttendance( $a, $this->insertPlayer( $team, 'Page', 'Player' . $n ), 'Present', 'actual' );
        }

        $before = $wpdb->num_queries;
        ActivityRegisterProgress::prime( $rows );
        $primed = $wpdb->num_queries - $before;
        $this->assertSame( 2, $primed, 'one GROUP BY for the counts, one for the roster sizes' );

        $after_prime = $wpdb->num_queries;
        foreach ( $rows as $row ) {
            $this->assertNotNull( ActivityRegisterProgress::forRow( $row ) );
        }
        $this->assertSame( $after_prime, $wpdb->num_queries, 'rendering the cards reads nothing more' );
    }

    // ---- fixtures ---------------------------------------------------

    private function row( int $id, int $team_id, string $type, string $status = 'completed' ): object {
        return (object) [
            'id'                  => $id,
            'team_id'             => $team_id,
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
        ];
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $type, string $status = 'completed' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Register fixture',
            'session_date'        => '2026-09-11',
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance(
        int $activity_id,
        int $player_id,
        string $status,
        string $record_type,
        int $is_guest = 0,
        ?int $minutes = null
    ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => $status,
            'is_guest'       => $is_guest,
            'record_type'    => $record_type,
            'minutes_played' => $minutes,
        ] );
    }
}
