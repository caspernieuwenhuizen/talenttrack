<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #3390 — a planned squad is not an attendance.
 *
 * Since #2248 the activity plan writes `record_type = 'expected'` rows, and
 * `plannedStatusMap()` stores the plan key `expected` as the status
 * `Present` — reusing the actual-attendance vocabulary so no new lookup seed
 * was needed. Match prep's lineup upsert goes further and inserts an expected
 * row with no status at all, which the column defaults to `present`.
 *
 * The `your_attendance_status` subquery had no `record_type` filter, so the
 * moment a coach planned a squad the player's own screen told them they had
 * been present at a fixture two weeks away.
 *
 * This is the filter migration 0121's reporting sweep added to every read
 * surface that counts actuals. The subquery was written after that sweep and
 * never audited against it, which is exactly the failure mode the migration's
 * docblock warned about.
 */
final class MyActivitiesPlannedAttendanceTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team;
    private int $player;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 Planned' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Planned',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function test_a_planned_squad_does_not_read_as_attendance(): void {
        $activity = $this->insertActivity( '2099-01-15' );
        // What the activity plan writes: expected, stored as `Present`.
        $this->insertAttendance( $activity, 'Present', 'expected' );

        $row = $this->rowFor( $activity );

        $this->assertNotNull( $row );
        $this->assertNull(
            $row->your_attendance_status,
            'a fixture that has not been played cannot have been attended'
        );
    }

    public function test_a_match_prep_lineup_row_does_not_read_as_attendance(): void {
        // MatchPrepRestController::upsertAttendanceLineup() inserts with no
        // status at all; the column defaults to `present`.
        $activity = $this->insertActivity( '2099-02-20' );
        $this->insertAttendanceWithoutStatus( $activity );

        $row = $this->rowFor( $activity );

        $this->assertNotNull( $row );
        $this->assertNull( $row->your_attendance_status );
    }

    public function test_recorded_attendance_still_shows(): void {
        // The other direction: the fix must not blank a real attendance.
        $activity = $this->insertActivity( '2020-03-01' );
        $this->insertAttendance( $activity, 'Present', 'actual' );

        $row = $this->rowFor( $activity );

        $this->assertNotNull( $row );
        $this->assertSame( 'Present', $row->your_attendance_status );
    }

    public function test_an_absence_still_shows(): void {
        $activity = $this->insertActivity( '2020-03-08' );
        $this->insertAttendance( $activity, 'Absent', 'actual' );

        $row = $this->rowFor( $activity );

        $this->assertNotNull( $row );
        $this->assertSame( 'Absent', $row->your_attendance_status );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function rowFor( int $activity_id ): ?object {
        // `searchForRest()` is what `ActivitiesRestController::list_sessions`
        // calls, and `your_status_pid` is the argument that adds the column
        // this test is about.
        $result = ( new ActivitiesRepository() )->searchForRest( [
            'your_status_pid' => $this->player,
            'per_page'        => 100,
        ] );

        foreach ( $result['rows'] as $row ) {
            if ( (int) ( $row->id ?? 0 ) === $activity_id ) return $row;
        }
        return null;
    }

    private function insertActivity( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Fixture ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
            'plan_state'          => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, string $status, string $record_type ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $this->player,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => $record_type,
        ] );
    }

    private function insertAttendanceWithoutStatus( int $activity_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $this->player,
            'is_guest'    => 0,
            'record_type' => 'expected',
        ] );
    }
}
