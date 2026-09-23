<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;

/**
 * #4013 — a player who arrived late was at the session.
 *
 * Late used to be in neither the present-% numerator nor the missed count,
 * so the player with the most late marks looked like the worst attender on
 * the team while never being flagged. The rule is now one rule, owned by
 * AttendanceFlagService: attended = present + late, missed = absent +
 * excused + injured, and lateness flags on its own threshold so chronic
 * lateness still surfaces — with its own reason.
 */
final class AttendanceLateCountsAsAttendedTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    /**
     * The reported case: 7 present, 4 late, 2 absent out of 13 is 84.6%,
     * and a teammate who actually missed five sessions is the worst
     * attender on the team.
     */
    public function test_late_counts_as_attended_and_no_longer_ranks_worst(): void {
        $team_id = $this->insertTeam( 'U17 late-counts' );
        $late    = $this->insertPlayer( $team_id, 'Laat', 'Komer' );
        $absent  = $this->insertPlayer( $team_id, 'Afwe', 'Zig' );

        $activities = [];
        for ( $i = 1; $i <= 13; $i++ ) {
            $activities[] = $this->insertActivity( $team_id, sprintf( '2020-04-%02d', $i ) );
        }

        // 7 present, 4 late, 2 absent.
        foreach ( $this->slice( $activities, 0, 7 )  as $a ) $this->insertAttendance( $a, $late, 'present' );
        foreach ( $this->slice( $activities, 7, 4 )  as $a ) $this->insertAttendance( $a, $late, 'late' );
        foreach ( $this->slice( $activities, 11, 2 ) as $a ) $this->insertAttendance( $a, $late, 'absent' );

        // 8 present, 5 absent — genuinely the worst attender.
        foreach ( $this->slice( $activities, 0, 8 )  as $a ) $this->insertAttendance( $a, $absent, 'present' );
        foreach ( $this->slice( $activities, 8, 5 )  as $a ) $this->insertAttendance( $a, $absent, 'absent' );

        $rows = ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team_id );
        $row  = $this->rowFor( $rows, $late );

        $this->assertSame( 13, (int) $row['total'] );
        $this->assertSame( 4, (int) $row['late'] );
        $this->assertSame( 2, (int) $row['missed'], 'missed stays absent + excused + injured' );
        $this->assertSame( 84.6, $row['present_pct'], 'present % counts the four late marks as attended' );

        $this->assertSame(
            $absent,
            (int) $rows[0]['player_id'],
            'worst-first order puts the player who actually missed five sessions first'
        );
    }

    /** A chronically late player is flagged, and the reason says lateness. */
    public function test_chronic_lateness_flags_with_its_own_reason(): void {
        $team_id = $this->insertTeam( 'U15 lateness-reason' );
        $player  = $this->insertPlayer( $team_id, 'Net', 'Telaat' );

        $activities = [];
        for ( $i = 1; $i <= 13; $i++ ) {
            $activities[] = $this->insertActivity( $team_id, sprintf( '2020-05-%02d', $i ) );
        }
        foreach ( $this->slice( $activities, 0, 9 )  as $a ) $this->insertAttendance( $a, $player, 'present' );
        foreach ( $this->slice( $activities, 9, 4 )  as $a ) $this->insertAttendance( $a, $player, 'late' );

        $row = $this->rowFor( ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team_id ), $player );

        $this->assertSame( 0, (int) $row['missed'], 'never absent' );
        $this->assertSame( 100.0, $row['present_pct'], 'at every session, so 100%' );
        $this->assertTrue( (bool) $row['flagged'], 'four late marks reach the lateness threshold' );
        $this->assertSame( [ AttendanceFlagService::REASON_LATENESS ], $row['flag_reasons'] );

        $at_risk = ( new AttendanceRankingQuery() )->atRisk( '2020-01-01', '2020-12-31', $team_id );
        $this->assertSame( $player, (int) $at_risk[0]['player_id'], 'the late player is on the at-risk list' );
        $this->assertSame( [ AttendanceFlagService::REASON_LATENESS ], $at_risk[0]['flag_reasons'] );
    }

    /** An absent player keeps the absence reason, and only that one. */
    public function test_absences_flag_with_the_absence_reason(): void {
        $team_id = $this->insertTeam( 'U14 absence-reason' );
        $player  = $this->insertPlayer( $team_id, 'Wel', 'Afwezig' );

        $activities = [];
        for ( $i = 1; $i <= 8; $i++ ) {
            $activities[] = $this->insertActivity( $team_id, sprintf( '2020-06-%02d', $i ) );
        }
        foreach ( $this->slice( $activities, 0, 5 ) as $a ) $this->insertAttendance( $a, $player, 'present' );
        foreach ( $this->slice( $activities, 5, 3 ) as $a ) $this->insertAttendance( $a, $player, 'absent' );

        $row = $this->rowFor( ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team_id ), $player );

        $this->assertSame( 3, (int) $row['missed'] );
        $this->assertTrue( (bool) $row['flagged'] );
        $this->assertSame( [ AttendanceFlagService::REASON_ABSENCE ], $row['flag_reasons'] );
        $this->assertSame( 62.5, $row['present_pct'] );
    }

    /** Excused and injured are still misses, not attendance. */
    public function test_excused_and_injured_are_not_attended(): void {
        $team_id = $this->insertTeam( 'U13 excused-injured' );
        $player  = $this->insertPlayer( $team_id, 'Ge', 'Blesseerd' );

        $activities = [];
        for ( $i = 1; $i <= 4; $i++ ) {
            $activities[] = $this->insertActivity( $team_id, sprintf( '2020-07-%02d', $i ) );
        }
        $this->insertAttendance( $activities[0], $player, 'present' );
        $this->insertAttendance( $activities[1], $player, 'late' );
        $this->insertAttendance( $activities[2], $player, 'excused' );
        $this->insertAttendance( $activities[3], $player, 'injured' );

        $row = $this->rowFor( ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team_id ), $player );

        $this->assertSame( 2, (int) $row['missed'] );
        $this->assertSame( 50.0, $row['present_pct'], 'one present + one late of four' );
    }

    /** The service's definitions, without a database. */
    public function test_service_owns_the_status_sets_and_the_sql_clause(): void {
        $this->assertSame( [ 'present', 'late' ], AttendanceFlagService::ATTENDED_STATUSES );
        $this->assertSame( [ 'absent', 'excused', 'injured' ], AttendanceFlagService::MISSED_STATUSES );

        $row = (object) [ 'present' => 7, 'late' => 4, 'absent' => 2, 'excused' => 0, 'injured' => 0 ];
        $this->assertSame( 11, AttendanceFlagService::attended( $row ) );
        $this->assertSame( 2, AttendanceFlagService::missed( $row ) );
        $this->assertSame( 84.6, AttendanceFlagService::presentPct( 11, 13 ) );
        $this->assertNull( AttendanceFlagService::presentPct( 0, 0 ), 'no rows is no data, not 0%' );

        $clause = AttendanceFlagService::missedStatusClause( 'att.status' );
        foreach ( AttendanceFlagService::MISSED_STATUSES as $status ) {
            $this->assertStringContainsString( "'{$status}'", $clause );
        }
        $this->assertStringNotContainsString( 'late', $clause, 'late is not a miss' );
        $this->assertStringContainsString( 'LOWER(att.status)', $clause );
    }

    /** Lateness falls back to the absence threshold when unconfigured. */
    public function test_late_threshold_defaults_to_the_absence_threshold(): void {
        $this->assertSame( AttendanceFlagService::threshold(), AttendanceFlagService::lateThreshold() );
    }

    /* ---- helpers -------------------------------------------------------- */

    /**
     * @param list<int> $activities
     * @return list<int>
     */
    private function slice( array $activities, int $offset, int $length ): array {
        return array_slice( $activities, $offset, $length );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function rowFor( array $rows, int $player_id ): array {
        foreach ( $rows as $r ) {
            if ( (int) $r['player_id'] === $player_id ) return $r;
        }
        $this->fail( 'the player must appear in the attendance ranking' );
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
        ] );
        return (int) $wpdb->insert_id;
    }

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
