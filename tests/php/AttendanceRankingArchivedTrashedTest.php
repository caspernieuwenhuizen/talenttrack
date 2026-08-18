<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;

/**
 * #2257 — archived and trashed (recycle-bin) activities must contribute
 * NOTHING to the attendance ranking. Attendance on an archived or binned
 * activity is not part of the live record; counting it inflates the
 * activity count and skews present %. A clean (non-archived, non-trashed)
 * activity with identical attendance is unaffected.
 */
final class AttendanceRankingArchivedTrashedTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_archived_and_trashed_activities_are_excluded(): void {
        global $wpdb;
        $team_id   = $this->insertTeam( 'U17 attendance-archive' );
        $player_id = $this->insertPlayer( $team_id, 'Att', 'Endance' );

        // Three completed activities in the past, one attendance row each.
        $live     = $this->insertActivity( $team_id, '2020-02-01' );
        $archived = $this->insertActivity( $team_id, '2020-02-08' );
        $trashed  = $this->insertActivity( $team_id, '2020-02-15' );

        $this->insertAttendance( $live,     $player_id, 'present' );
        $this->insertAttendance( $archived, $player_id, 'absent' );
        $this->insertAttendance( $trashed,  $player_id, 'absent' );

        $wpdb->update( "{$this->p}tt_activities", [ 'archived_at' => '2020-02-09 10:00:00' ], [ 'id' => $archived ] );
        $wpdb->update( "{$this->p}tt_activities", [ 'trashed_at'  => '2020-02-16 10:00:00' ], [ 'id' => $trashed ] );

        $rows = ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team_id );

        $row = null;
        foreach ( $rows as $r ) {
            if ( (int) $r['player_id'] === $player_id ) { $row = $r; break; }
        }
        $this->assertNotNull( $row, 'the player must appear in the ranking' );
        $this->assertSame( 1, (int) $row['activities'], 'only the live activity counts; archived + trashed excluded' );
        $this->assertSame( 1, (int) $row['total'], 'only the live attendance row counts' );
        $this->assertSame( 1, (int) $row['present'], 'the live row is present' );
        $this->assertSame( 0, (int) $row['absent'], 'the two absent rows sit on archived/trashed activities and must not count' );
        $this->assertSame( 100.0, $row['present_pct'], 'present % reflects only the live activity' );
    }

    /**
     * #2259 — a completed activity that carries `activity_status_key =
     * 'cancelled'` (the completed-then-status-cancelled edge) must be
     * excluded from the attendance ranking. The existing `plan_state =
     * 'completed'` guard already drops `plan_state='cancelled'`, but not
     * this status-key marker. A clean completed activity is unaffected.
     */
    public function test_status_cancelled_activity_is_excluded(): void {
        global $wpdb;
        $team_id   = $this->insertTeam( 'U17 attendance-cancelled' );
        $player_id = $this->insertPlayer( $team_id, 'Can', 'Celled' );

        $live      = $this->insertActivity( $team_id, '2020-03-01' );
        $cancelled = $this->insertActivity( $team_id, '2020-03-08' );

        $this->insertAttendance( $live,      $player_id, 'present' );
        $this->insertAttendance( $cancelled, $player_id, 'absent' );

        // Completed but status-cancelled → must not count.
        $wpdb->update( "{$this->p}tt_activities", [ 'activity_status_key' => 'cancelled' ], [ 'id' => $cancelled ] );

        $rows = ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team_id );

        $row = null;
        foreach ( $rows as $r ) {
            if ( (int) $r['player_id'] === $player_id ) { $row = $r; break; }
        }
        $this->assertNotNull( $row, 'the player must appear in the ranking' );
        $this->assertSame( 1, (int) $row['activities'], 'only the live activity counts; status-cancelled excluded' );
        $this->assertSame( 1, (int) $row['total'], 'only the live attendance row counts' );
        $this->assertSame( 0, (int) $row['absent'], 'the absent row sits on a cancelled activity and must not count' );
        $this->assertSame( 100.0, $row['present_pct'], 'present % reflects only the live activity' );
    }

    /* ---- helpers -------------------------------------------------------- */

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
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * #2521 — a countable activity is one the coach marked completed.
     * `activity_status_key` defaults to 'planned', so the fixture has to
     * set it explicitly; `plan_state` rides along for the planner.
     */
    private function insertActivity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
    }
}
