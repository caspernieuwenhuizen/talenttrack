<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\EmptyRegisterConfirm;

/**
 * #3446 — how much of an activity's register exists.
 *
 * The predicate behind the "nobody is marked present" confirm. Every case
 * below is asserted in both directions, because the failure this guards
 * against is a wrong answer rather than an error: a planned roster that
 * reads as a register raises no dialog and loses a day of attendance, and
 * a register that reads as empty raises a dialog on work already done.
 */
final class ActivityRegisterProgressTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        ActivityRegisterProgress::forget();
    }

    public function tear_down(): void {
        ActivityRegisterProgress::forget();
        parent::tear_down();
    }

    /* ---- the record_type distinction ------------------------------------ */

    public function test_a_planned_roster_alone_is_an_empty_register(): void {
        $team = $this->insertTeam( 'U13 planned' );
        $a    = $this->insertActivity( $team );
        foreach ( [ 'One', 'Two', 'Three' ] as $name ) {
            // The plan stores Expected as `Present` — the register's own
            // vocabulary, which is exactly why the filter matters.
            $this->insertAttendance( $a, $this->insertPlayer( $team, $name ), 'Present', 'expected' );
        }

        $this->assertSame( ActivityRegisterProgress::NONE, ActivityRegisterProgress::state( $a ) );
        $this->assertTrue( ActivityRegisterProgress::isEmpty( $a ) );
        $this->assertSame( 0, ActivityRegisterProgress::recordedCount( $a ) );
        $this->assertSame( 3, ActivityRegisterProgress::expectedCount( $a ) );
    }

    public function test_the_same_activity_with_a_recorded_row_is_not_empty(): void {
        $team    = $this->insertTeam( 'U13 recorded' );
        $a       = $this->insertActivity( $team );
        $players = [ $this->insertPlayer( $team, 'One' ), $this->insertPlayer( $team, 'Two' ) ];
        foreach ( $players as $pid ) {
            $this->insertAttendance( $a, $pid, 'Present', 'expected' );
        }
        $this->insertAttendance( $a, $players[0], 'Present', 'actual' );

        $this->assertFalse( ActivityRegisterProgress::isEmpty( $a ) );
        $this->assertSame( ActivityRegisterProgress::PARTIAL, ActivityRegisterProgress::state( $a ) );
    }

    public function test_a_full_register_reads_as_complete(): void {
        $team = $this->insertTeam( 'U13 full' );
        $a    = $this->insertActivity( $team );
        foreach ( [ 'One', 'Two' ] as $name ) {
            $this->insertAttendance( $a, $this->insertPlayer( $team, $name ), 'Present', 'actual' );
        }

        $this->assertSame( ActivityRegisterProgress::COMPLETE, ActivityRegisterProgress::state( $a ) );
        $this->assertFalse( ActivityRegisterProgress::isEmpty( $a ) );
    }

    /**
     * A register of absences is still a register. "Nobody is marked
     * present" is the dialog's headline, but the fact it guards is
     * "nothing was recorded" — a squad that was all marked absent has been
     * observed and must not be interrupted.
     */
    public function test_a_register_of_absences_is_still_a_register(): void {
        $team = $this->insertTeam( 'U13 absent' );
        $a    = $this->insertActivity( $team );
        foreach ( [ 'One', 'Two' ] as $name ) {
            $this->insertAttendance( $a, $this->insertPlayer( $team, $name ), 'Absent', 'actual' );
        }

        $this->assertFalse( ActivityRegisterProgress::isEmpty( $a ) );
    }

    /* ---- what does not count as recorded -------------------------------- */

    public function test_a_guest_row_is_not_the_teams_register(): void {
        $team = $this->insertTeam( 'U13 guest' );
        $a    = $this->insertActivity( $team );
        $this->insertPlayer( $team, 'Roster' );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Visitor' ), 'Present', 'actual', 1 );

        $this->assertSame( 0, ActivityRegisterProgress::recordedCount( $a ) );
        $this->assertTrue( ActivityRegisterProgress::isEmpty( $a ) );
    }

    public function test_a_status_less_row_is_a_lineup_not_a_register(): void {
        // Match prep's lineup upsert writes a row with no status: it means
        // "in the squad", not "was here".
        $team = $this->insertTeam( 'U13 lineup' );
        $a    = $this->insertActivity( $team );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'Named' ), '', 'actual' );

        $this->assertSame( 0, ActivityRegisterProgress::recordedCount( $a ) );
        $this->assertTrue( ActivityRegisterProgress::isEmpty( $a ) );
    }

    /* ---- nothing to be missing ------------------------------------------ */

    public function test_a_meeting_has_no_register_to_miss(): void {
        $team = $this->insertTeam( 'U13 meeting' );
        $a    = $this->insertActivity( $team, 'meeting' );
        $this->insertPlayer( $team, 'Attendee' );

        $this->assertSame( ActivityRegisterProgress::NOT_APPLICABLE, ActivityRegisterProgress::state( $a ) );
        $this->assertFalse( ActivityRegisterProgress::isEmpty( $a ) );
    }

    public function test_an_other_type_has_no_register_to_miss(): void {
        $team = $this->insertTeam( 'U13 other' );
        $a    = $this->insertActivity( $team, 'other' );
        $this->insertPlayer( $team, 'Someone' );

        $this->assertSame( ActivityRegisterProgress::NOT_APPLICABLE, ActivityRegisterProgress::state( $a ) );
    }

    public function test_a_teamless_activity_has_no_roster_to_record(): void {
        $a = $this->insertActivity( 0 );

        $this->assertSame( ActivityRegisterProgress::NOT_APPLICABLE, ActivityRegisterProgress::state( $a ) );
        $this->assertFalse( ActivityRegisterProgress::isEmpty( $a ) );
    }

    public function test_a_team_with_nobody_on_it_has_no_register_to_miss(): void {
        $a = $this->insertActivity( $this->insertTeam( 'U13 empty squad' ) );

        $this->assertSame( ActivityRegisterProgress::NOT_APPLICABLE, ActivityRegisterProgress::state( $a ) );
    }

    public function test_an_unknown_activity_is_never_a_lookup(): void {
        $this->assertSame( ActivityRegisterProgress::NOT_APPLICABLE, ActivityRegisterProgress::state( 0 ) );
        $this->assertSame( ActivityRegisterProgress::NOT_APPLICABLE, ActivityRegisterProgress::state( 987654 ) );
    }

    /* ---- the denominator ------------------------------------------------ */

    public function test_the_plan_is_the_denominator_when_one_was_captured(): void {
        $team = $this->insertTeam( 'U13 denominator' );
        $a    = $this->insertActivity( $team );
        // Roster of four; only three were planned for this activity.
        $planned = [ $this->insertPlayer( $team, 'A' ), $this->insertPlayer( $team, 'B' ), $this->insertPlayer( $team, 'C' ) ];
        $this->insertPlayer( $team, 'D' );
        foreach ( $planned as $pid ) {
            $this->insertAttendance( $a, $pid, 'Present', 'expected' );
            $this->insertAttendance( $a, $pid, 'Present', 'actual' );
        }

        $this->assertSame( 3, ActivityRegisterProgress::expectedCount( $a ) );
        $this->assertSame(
            ActivityRegisterProgress::COMPLETE,
            ActivityRegisterProgress::state( $a ),
            'a squad of three that was fully recorded is not two-thirds done'
        );
    }

    public function test_the_current_roster_is_the_denominator_when_nothing_was_planned(): void {
        $team = $this->insertTeam( 'U13 fallback' );
        $a    = $this->insertActivity( $team );
        $this->insertPlayer( $team, 'A' );
        $this->insertPlayer( $team, 'B' );

        $this->assertSame( 2, ActivityRegisterProgress::expectedCount( $a ) );
    }

    /* ---- the confirm the predicate feeds -------------------------------- */

    public function test_the_guard_attributes_are_emitted_only_on_an_empty_register(): void {
        $team    = $this->insertTeam( 'U13 guard' );
        $empty   = $this->insertActivity( $team );
        $taken   = $this->insertActivity( $team );
        $players = [ $this->insertPlayer( $team, 'A' ), $this->insertPlayer( $team, 'B' ) ];
        foreach ( $players as $pid ) {
            $this->insertAttendance( $empty, $pid, 'Present', 'expected' );
            $this->insertAttendance( $taken, $pid, 'Present', 'actual' );
        }

        $this->assertTrue( EmptyRegisterConfirm::applies( $empty ) );
        $this->assertFalse( EmptyRegisterConfirm::applies( $taken ) );

        $attrs = EmptyRegisterConfirm::guardAttributes( $empty, 0 );
        $this->assertStringContainsString( 'data-tt-empty-register-guard="1"', $attrs );
        $this->assertStringContainsString( esc_attr( EmptyRegisterConfirm::title() ), $attrs );
        $this->assertStringContainsString( esc_attr( EmptyRegisterConfirm::confirmLabel() ), $attrs );
    }

    /**
     * A remedy button with nowhere to go would dead-click, so it is only
     * rendered when the grid is actually reachable. User 0 can reach
     * nothing.
     */
    public function test_the_remedy_button_is_dropped_when_the_grid_is_unreachable(): void {
        $team = $this->insertTeam( 'U13 remedy' );
        $a    = $this->insertActivity( $team );
        $this->insertAttendance( $a, $this->insertPlayer( $team, 'A' ), 'Present', 'expected' );

        $this->assertSame( '', EmptyRegisterConfirm::recordUrl( $a, 0 ) );
        $this->assertStringNotContainsString(
            'data-tt-empty-register-alt-href',
            EmptyRegisterConfirm::guardAttributes( $a, 0 )
        );
    }

    /**
     * The wizard's Skip branch is a commit point — either Skip button
     * flips the activity to completed — so both carry the guard when
     * there is no register, and neither carries it when there is.
     */
    public function test_the_wizard_skip_buttons_carry_the_guard_only_when_the_register_is_empty(): void {
        $team    = $this->insertTeam( 'U13 wizard skip' );
        $empty   = $this->insertActivity( $team );
        $taken   = $this->insertActivity( $team );
        $players = [ $this->insertPlayer( $team, 'A' ), $this->insertPlayer( $team, 'B' ) ];
        foreach ( $players as $pid ) {
            $this->insertAttendance( $empty, $pid, 'Present', 'expected' );
            $this->insertAttendance( $taken, $pid, 'Present', 'actual' );
        }

        $on_empty = $this->renderRateConfirm( $empty );
        $this->assertSame(
            2,
            substr_count( $on_empty, 'data-tt-empty-register-guard' ),
            'both Skip buttons complete the activity, so both are guarded'
        );
        $this->assertStringNotContainsString(
            'Attendance is saved.',
            $on_empty,
            'the step must not claim a register that does not exist'
        );

        $on_taken = $this->renderRateConfirm( $taken );
        $this->assertStringNotContainsString( 'data-tt-empty-register-guard', $on_taken );
        $this->assertStringContainsString( 'Attendance is saved.', $on_taken );
    }

    private function renderRateConfirm( int $activity_id ): string {
        ob_start();
        ( new \TT\Modules\Wizards\Evaluation\RateConfirmStep() )->render( [
            '_path'       => 'activity-first',
            'activity_id' => $activity_id,
        ] );
        return (string) ob_get_clean();
    }

    /* ---- fixtures ------------------------------------------------------- */

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $type = 'training' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Activity ' . $type,
            'session_date'        => '2026-09-16',
            'activity_type_key'   => $type,
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status, string $record_type, int $is_guest = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => $is_guest,
            'record_type' => $record_type,
        ] );
    }
}
