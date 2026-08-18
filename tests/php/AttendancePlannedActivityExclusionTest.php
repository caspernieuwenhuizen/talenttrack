<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;

/**
 * #2521 / #2522 / #2523 — the lifecycle + record-type contract behind the
 * attendance numbers.
 *
 * `tt_activities` carries two lifecycle columns. `plan_state` was added
 * with `DEFAULT 'completed'` and only the team planner sets it, so an
 * activity that reads "Planned" on screen arrives in the database as
 * `plan_state='completed'` — which is why every report that gated on it
 * counted sessions that had not happened. Reporting reads
 * `activity_status_key` instead.
 *
 * `tt_attendance` likewise holds two record types: the plan
 * (`record_type='expected'`, statuses mapped Expected→present,
 * Not coming→absent, Maybe→excused) and the register (`'actual'`).
 * Counting both reported more players present than the roster holds.
 */
final class AttendancePlannedActivityExclusionTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    /**
     * #2521 — the live shape: a Spond-imported activity carrying
     * `activity_status_key='planned'` and the `plan_state='completed'`
     * column default. It must not reach the ranking, even though it is
     * past-dated and has a full register.
     */
    public function test_planned_activity_with_default_plan_state_is_excluded(): void {
        $team_id   = $this->insertTeam( 'U14 lifecycle' );
        $player_id = $this->insertPlayer( $team_id, 'Plan', 'Ned' );

        $completed = $this->insertActivity( $team_id, '2020-04-01', 'completed' );
        // No activity_status_key → column default 'planned'; no plan_state
        // → column default 'completed'. Exactly what Spond sync writes.
        $planned   = $this->insertActivity( $team_id, '2020-04-08', null, null );

        $this->insertAttendance( $completed, $player_id, 'present' );
        $this->insertAttendance( $planned,   $player_id, 'present' );

        $row = $this->rankingRowFor( $team_id, $player_id );

        $this->assertNotNull( $row, 'the player must appear in the ranking' );
        $this->assertSame( 1, (int) $row['activities'], 'only the completed activity counts' );
        $this->assertSame( 1, (int) $row['total'], 'the planned activity contributes no attendance row' );
    }

    /**
     * #2521 — the player-file Activities badge reads the same gate, so the
     * badge and the reports cannot disagree about what happened.
     */
    public function test_player_file_badge_ignores_planned_activities(): void {
        $team_id   = $this->insertTeam( 'U14 badge' );
        $player_id = $this->insertPlayer( $team_id, 'Bad', 'Ge' );

        $completed = $this->insertActivity( $team_id, '2020-05-01', 'completed' );
        $planned   = $this->insertActivity( $team_id, '2020-05-08', null, null );

        $this->insertAttendance( $completed, $player_id, 'present' );
        $this->insertAttendance( $planned,   $player_id, 'present' );
        // The plan row on the completed activity must not double the badge.
        $this->insertAttendance( $completed, $player_id, 'present', 'expected' );

        $counts = PlayerFileCounts::for( $player_id );
        $this->assertSame( 1, (int) $counts['activities'], 'one completed activity, counted once' );
    }

    /**
     * #2522 — the reported symptom: a plan of 15 plus a register of 15
     * reported 28 present against a roster of 15. The breakdown counts the
     * register only.
     */
    public function test_breakdown_counts_recorded_rows_only(): void {
        $team_id = $this->insertTeam( 'U14 breakdown' );
        $a       = $this->insertActivity( $team_id, '2020-06-01', 'completed' );

        $players = [];
        for ( $i = 1; $i <= 5; $i++ ) {
            $players[] = $this->insertPlayer( $team_id, 'P' . $i, 'Breakdown' );
        }

        // The whole squad is on the plan (Expected → stored as 'present').
        foreach ( $players as $pid ) {
            $this->insertAttendance( $a, $pid, 'present', 'expected' );
        }
        // The register: four present, one reported off.
        foreach ( array_slice( $players, 0, 4 ) as $pid ) {
            $this->insertAttendance( $a, $pid, 'present' );
        }
        $this->insertAttendance( $a, $players[4], 'excused' );

        $bd = ( new ActivitiesRepository() )->attendanceBreakdownForActivity( $a, $team_id );

        $this->assertSame( 5, (int) $bd->roster_size );
        $this->assertSame( 5, (int) $bd->total, 'the plan rows must not inflate the total' );
        $this->assertSame( 4, (int) $bd->present, 'four recorded present, not nine' );
        $this->assertSame( 1, (int) ( $bd->by_status['excused'] ?? 0 ) );
        $this->assertLessThanOrEqual( (int) $bd->roster_size, (int) $bd->present );
    }

    /**
     * #2523 — the stat strip states "N / M present" as fact, so it stays
     * empty until the coach has marked the activity completed. Attendance
     * entered ahead of that is kept; it is simply not asserted yet.
     */
    public function test_stat_strip_hides_present_until_completed(): void {
        $team_id = $this->insertTeam( 'U14 strip' );
        $pid     = $this->insertPlayer( $team_id, 'Str', 'Ip' );
        $planned = $this->insertActivity( $team_id, '2020-07-01', 'planned', null );
        $this->insertAttendance( $planned, $pid, 'present' );

        $repo = new ActivitiesRepository();

        $strip = $repo->statStripForActivity( $planned, $team_id, false, '18:30', '20:00', 0, 'planned' );
        $this->assertNull( $strip->present, 'a planned activity states no attendance' );
        $this->assertNull( $strip->roster_size );
        $this->assertSame( 90, (int) $strip->duration_minutes, 'the duration cell is unaffected' );

        $strip = $repo->statStripForActivity( $planned, $team_id, false, '18:30', '20:00', 0, 'completed' );
        $this->assertSame( 1, (int) $strip->present, 'once completed the register is stated' );
    }

    /**
     * #2521 — recording attendance on a past-dated, still-planned activity
     * completes it, so grid entry reaches the reports instead of being
     * silently dropped by the new gate. A future-dated session is left
     * alone: pre-recording an absence is not an assertion it happened.
     */
    public function test_recording_attendance_completes_past_activity_only(): void {
        global $wpdb;
        $team_id = $this->insertTeam( 'U14 transition' );
        $past    = $this->insertActivity( $team_id, '2020-08-01', 'planned', 'scheduled' );
        $future  = $this->insertActivity( $team_id, gmdate( 'Y-m-d', strtotime( '+30 days' ) ), 'planned', 'scheduled' );
        $done    = $this->insertActivity( $team_id, '2020-08-08', 'cancelled', 'cancelled' );

        $repo = new ActivitiesRepository();

        $this->assertTrue( $repo->completeIfNotTerminal( $past ), 'a past planned activity completes' );
        $this->assertFalse( $repo->completeIfNotTerminal( $future ), 'a future activity is left planned' );
        $this->assertFalse( $repo->completeIfNotTerminal( $done ), 'an explicit cancellation is never overridden' );

        $status = static function ( int $id ) use ( $wpdb ) {
            return (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT activity_status_key FROM {$wpdb->prefix}tt_activities WHERE id = %d", $id
            ) );
        };
        $this->assertSame( 'completed', $status( $past ) );
        $this->assertSame( 'planned',   $status( $future ) );
        $this->assertSame( 'cancelled', $status( $done ) );

        // Both lifecycle columns move together.
        $this->assertSame( 'completed', (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT plan_state FROM {$wpdb->prefix}tt_activities WHERE id = %d", $past
        ) ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,mixed>|null */
    private function rankingRowFor( int $team_id, int $player_id ): ?array {
        foreach ( ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team_id ) as $r ) {
            if ( (int) $r['player_id'] === $player_id ) return $r;
        }
        return null;
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

    /**
     * A null status / plan state leaves the column DEFAULT in place —
     * 'planned' and 'completed' respectively, the combination every
     * non-planner create path produces.
     */
    private function insertActivity( int $team_id, string $date, ?string $status = 'completed', ?string $plan_state = 'completed' ): int {
        global $wpdb;
        $row = [
            'club_id'           => $this->club,
            'team_id'           => $team_id,
            'title'             => 'Training ' . $date,
            'session_date'      => $date,
            'activity_type_key' => 'training',
        ];
        if ( $status !== null )     $row['activity_status_key'] = $status;
        if ( $plan_state !== null ) $row['plan_state']          = $plan_state;
        $wpdb->insert( "{$this->p}tt_activities", $row );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status, string $record_type = 'actual' ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => $record_type,
        ] );
    }
}
