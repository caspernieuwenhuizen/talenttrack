<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Wizards\Evaluation\AttendanceStep;
use TT\Modules\Wizards\Evaluation\RateConfirmStep;

/**
 * #3443 — a planned roster is not a recorded register.
 *
 * `tt_attendance` holds both, separated only by `record_type` (migration
 * 0121), and the planned rows carry the register's own vocabulary:
 * `plannedStatusMap()` stores Expected as `Present`, Not coming as
 * `Absent`, Maybe as `Excused`. So four of the evaluation wizard's reads,
 * which never filtered the column, could not tell a squad somebody
 * intends to field from a squad somebody watched.
 *
 * The failure was silence, not an error — the step skipped itself, the
 * next screen opened with "Attendance is saved.", and the activity
 * completed with zero actual rows. Every assertion below therefore
 * pins BOTH directions: planned-only must read as nothing recorded, and
 * the same activity with an actual row must read as recorded.
 */
final class EvaluationWizardRecordTypeTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team;
    private int $player;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U16 Wizard' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Wizard',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    /* ---- S1: the step skipping itself ---------------------------------- */

    public function test_a_planned_roster_does_not_read_as_a_recorded_register(): void {
        $a = $this->insertActivity();
        // What the activity plan writes for "Expected".
        $this->insertAttendance( $a, 'Present', 'expected' );

        $this->assertFalse(
            AttendanceStep::hasRecordedAttendance( $a ),
            'a roster nobody has taken is not attendance'
        );
    }

    public function test_a_recorded_register_reads_as_recorded(): void {
        $a = $this->insertActivity();
        $this->insertAttendance( $a, 'Present', 'actual' );

        $this->assertTrue( AttendanceStep::hasRecordedAttendance( $a ) );
    }

    public function test_the_step_renders_over_a_planned_roster_and_skips_over_a_register(): void {
        $planned  = $this->insertActivity();
        $recorded = $this->insertActivity();
        $this->insertAttendance( $planned, 'Present', 'expected' );
        $this->insertAttendance( $recorded, 'Present', 'actual' );

        $step = new AttendanceStep();

        $this->assertFalse(
            $step->notApplicableFor( [ '_path' => 'activity-first', 'activity_id' => $planned ] ),
            'the coach still has to take the register'
        );
        $this->assertTrue(
            $step->notApplicableFor( [ '_path' => 'activity-first', 'activity_id' => $recorded ] ),
            'the register exists; do not ask for it twice'
        );
    }

    /* ---- the pre-fill --------------------------------------------------- */

    public function test_a_planned_maybe_does_not_preselect_as_a_recorded_excused(): void {
        $a = $this->insertActivity();
        // `plannedStatusMap()` maps the plan key "Maybe" onto `Excused`.
        $this->insertAttendance( $a, 'Excused', 'expected' );

        $this->assertSame(
            [],
            AttendanceStep::recordedStatusesFor( $a ),
            'the roster must open on its own default, not on the plan'
        );
    }

    public function test_the_prefill_reads_the_recorded_register(): void {
        $a = $this->insertActivity();
        $this->insertAttendance( $a, 'Late', 'actual' );

        $this->assertSame(
            [ $this->player => 'Late' ],
            AttendanceStep::recordedStatusesFor( $a )
        );
    }

    /* ---- S2: the write path -------------------------------------------- */

    public function test_saving_inserts_an_actual_row_and_leaves_the_plan_untouched(): void {
        $a = $this->insertActivity();
        $planned_id = $this->insertAttendance( $a, 'Excused', 'expected' );

        ( new AttendanceStep() )->validate(
            [ 'attendance' => [ (string) $this->player => 'present' ] ],
            [ '_path' => 'activity-first', 'activity_id' => $a ]
        );

        $plan = $this->row( $planned_id );
        $this->assertNotNull( $plan );
        $this->assertSame( 'expected', (string) $plan->record_type, 'the plan must survive the register' );
        $this->assertSame( 'Excused', (string) $plan->status );

        $actual = $this->actualRows( $a );
        $this->assertCount( 1, $actual );
        $this->assertSame( $this->player, (int) $actual[0]->player_id );
        $this->assertSame( 'present', strtolower( (string) $actual[0]->status ) );
    }

    public function test_saving_twice_updates_the_actual_row_rather_than_stacking_rows(): void {
        $a = $this->insertActivity();
        $step = new AttendanceStep();
        $state = [ '_path' => 'activity-first', 'activity_id' => $a ];

        $step->validate( [ 'attendance' => [ (string) $this->player => 'present' ] ], $state );
        $step->validate( [ 'attendance' => [ (string) $this->player => 'absent' ] ], $state );

        $actual = $this->actualRows( $a );
        $this->assertCount( 1, $actual );
        $this->assertSame( 'absent', strtolower( (string) $actual[0]->status ) );
    }

    /* ---- S1b: the "N present" count on the next screen ------------------ */

    public function test_planned_rows_are_not_players_to_rate(): void {
        $a = $this->insertActivity();
        $this->insertAttendance( $a, 'Present', 'expected' );
        $this->insertAttendance( $a, 'Present', 'expected', $this->insertPlayer( 'Second', 'Planned' ) );

        $this->assertSame( 0, RateConfirmStep::countRatable( $a ) );
    }

    public function test_recorded_present_and_late_are_players_to_rate(): void {
        $a = $this->insertActivity();
        $this->insertAttendance( $a, 'Present', 'actual' );
        $this->insertAttendance( $a, 'Late', 'actual', $this->insertPlayer( 'Second', 'Recorded' ) );
        $this->insertAttendance( $a, 'Absent', 'actual', $this->insertPlayer( 'Third', 'Recorded' ) );

        $this->assertSame( 2, RateConfirmStep::countRatable( $a ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function insertActivity(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Tuesday training',
            'session_date'        => '2026-09-15',
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, string $status, string $record_type, ?int $player_id = null ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id ?? $this->player,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => $record_type,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function row( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->p}tt_attendance WHERE id = %d", $id ) );
        return $row ?: null;
    }

    /** @return array<int,object> */
    private function actualRows( int $activity_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_attendance
              WHERE activity_id = %d AND record_type = 'actual'
              ORDER BY id",
            $activity_id
        ) );
    }
}
