<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;

/**
 * #4101 — a player marked unavailable is not a gap in the minutes audit.
 *
 * Match prep writes an attendance row for every player in its availability
 * set, available or not, and the audit read any attendance row as "on the
 * squad". An injured player's zero then held a fully recorded match at
 * Incomplete for ever.
 */
final class MinutesAuditUnavailableTest extends WP_UnitTestCase {

    private const FROM = '2026-01-01';
    private const TO   = '2026-12-31';

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    // ── the rule ───────────────────────────────────────────────────────

    public function test_anything_but_present_in_match_prep_is_unavailable(): void {
        $this->assertFalse( MinutesAuditQuery::isMarkedUnavailable( 'Present', [ 'Present' ] ) );
        $this->assertTrue( MinutesAuditQuery::isMarkedUnavailable( 'Absent', [ 'Present' ] ) );
        $this->assertTrue( MinutesAuditQuery::isMarkedUnavailable( 'Injured', [ 'Present' ] ) );
        $this->assertTrue(
            MinutesAuditQuery::isMarkedUnavailable( 'Some future status', [ 'Present' ] ),
            'the rule is "not Present", not a list'
        );
        $this->assertFalse( MinutesAuditQuery::isMarkedUnavailable( null, [ 'Present' ] ), 'no availability row is no mark' );
    }

    public function test_anything_but_a_turn_up_on_the_register_is_unavailable(): void {
        $this->assertTrue( MinutesAuditQuery::isMarkedUnavailable( null, [ 'Absent' ] ) );
        $this->assertTrue( MinutesAuditQuery::isMarkedUnavailable( null, [ 'Excused' ] ) );
        $this->assertTrue( MinutesAuditQuery::isMarkedUnavailable( null, [ 'injured' ] ), 'any casing' );
        $this->assertFalse( MinutesAuditQuery::isMarkedUnavailable( null, [ 'Late' ] ), 'late is a turn-up' );
        $this->assertFalse( MinutesAuditQuery::isMarkedUnavailable( null, [ 'present' ] ) );
        $this->assertFalse( MinutesAuditQuery::isMarkedUnavailable( null, [ '' ] ), 'an empty status is the column default' );
        $this->assertFalse( MinutesAuditQuery::isMarkedUnavailable( null, [] ) );
    }

    // ── the matrix ─────────────────────────────────────────────────────

    public function test_a_fully_recorded_match_with_two_unavailable_players_is_complete(): void {
        $team    = $this->insertTeam();
        $players = [];
        for ( $i = 1; $i <= 14; $i++ ) {
            $players[] = $this->insertPlayer( $team, $i );
        }
        $match = $this->insertMatch( $team, '2026-03-01', 60 );
        $prep  = $this->insertPrep( $match );

        foreach ( $players as $i => $pid ) {
            $this->insertPlannedRow( $match, $pid );
            $status = $i === 12 ? 'Absent' : ( $i === 13 ? 'Injured' : 'Present' );
            $this->insertAvailability( $prep, $pid, $status );
        }
        // Eight a side over sixty minutes is 480; twelve players at 40 each.
        foreach ( array_slice( $players, 0, 12 ) as $pid ) {
            $this->insertMinutes( $match, $pid, 40 );
        }

        $row = $this->matrixRow( $team, $match );

        $this->assertSame( 480, (int) $row['available_minutes'] );
        $this->assertSame( 'complete', $row['status'] );
        $this->assertSame( 12, (int) $row['squad_count'], 'the unavailable players are not on the squad' );

        foreach ( [ $players[12], $players[13] ] as $pid ) {
            $this->assertFalse( $row['on_squad'][ $pid ], 'not a squad player' );
            $this->assertTrue( $row['unavailable'][ $pid ], 'rendered as unavailable, not as a gap' );
        }
        $this->assertFalse( $row['unavailable'][ $players[0] ] );
    }

    public function test_recorded_minutes_win_over_an_unavailable_mark(): void {
        $team  = $this->insertTeam();
        $pid   = $this->insertPlayer( $team, 1 );
        $match = $this->insertMatch( $team, '2026-03-08', 60 );
        $prep  = $this->insertPrep( $match );

        $this->insertPlannedRow( $match, $pid );
        $this->insertAvailability( $prep, $pid, 'Absent' );
        $this->insertMinutes( $match, $pid, 25 );

        $matrix = ( new MinutesAuditQuery() )->matrix( $team, self::FROM, self::TO );
        $row    = $matrix['games'][0];

        $this->assertTrue( $row['on_squad'][ $pid ] );
        $this->assertFalse( $row['unavailable'][ $pid ] );
        $this->assertSame( 25, (int) $row['minutes'][ $pid ] );
        $this->assertSame( 1, (int) $row['squad_count'] );
        $this->assertSame( 25, (int) $matrix['column_totals'][ $pid ], 'their minutes count' );
    }

    public function test_an_absence_on_the_register_is_unavailable_without_a_match_prep(): void {
        $team    = $this->insertTeam();
        $played  = $this->insertPlayer( $team, 1 );
        $excused = $this->insertPlayer( $team, 2 );
        $match   = $this->insertMatch( $team, '2026-03-15', 60 );

        $this->insertMinutes( $match, $played, 60 );
        $this->insertRecordedRow( $match, $excused, 'Excused' );

        $row = $this->matrixRow( $team, $match );

        $this->assertTrue( $row['unavailable'][ $excused ] );
        $this->assertSame( 1, (int) $row['squad_count'] );
        $this->assertNotSame( 'players_missing', $row['status_reason'] );
    }

    public function test_a_player_selected_but_not_recorded_is_still_a_gap(): void {
        $team    = $this->insertTeam();
        $played  = $this->insertPlayer( $team, 1 );
        $missing = $this->insertPlayer( $team, 2 );
        $match   = $this->insertMatch( $team, '2026-03-22', 60 );

        $this->insertMinutes( $match, $played, 60 );
        $this->insertPlannedRow( $match, $missing );

        $row = $this->matrixRow( $team, $match );

        $this->assertTrue( $row['on_squad'][ $missing ] );
        $this->assertFalse( $row['unavailable'][ $missing ] );
        $this->assertSame( 'players_missing', $row['status_reason'] );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function matrixRow( int $team, int $match ): array {
        $matrix = ( new MinutesAuditQuery() )->matrix( $team, self::FROM, self::TO );
        foreach ( $matrix['games'] as $g ) {
            if ( (int) $g['activity_id'] === $match ) return $g;
        }
        $this->fail( 'the match is in the matrix' );
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Audit unavailable', 'football_form' => '8v8' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, int $n ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'first_name'    => 'Player',
            'last_name'     => 'No ' . $n,
            'jersey_number' => $n,
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date, int $length ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'              => $this->club,
            'team_id'              => $team_id,
            'title'                => 'Match ' . $date,
            'session_date'         => $date,
            'activity_type_key'    => 'match',
            'game_subtype_key'     => 'League',
            'activity_status_key'  => 'completed',
            'plan_state'           => 'completed',
            'match_length_minutes' => $length,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPrep( int $activity_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_prep", [
            'uuid'                => wp_generate_uuid4(),
            'club_id'             => $this->club,
            'activity_id'         => $activity_id,
            'half_length_minutes' => 30,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAvailability( int $prep_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_prep_availability", [
            'club_id'       => $this->club,
            'match_prep_id' => $prep_id,
            'player_id'     => $player_id,
            'status'        => $status,
        ] );
    }

    private function insertPlannedRow( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => 'Present',
            'is_guest'    => 0,
            'record_type' => 'expected',
        ] );
    }

    private function insertRecordedRow( int $activity_id, int $player_id, string $status ): void {
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

    private function insertMinutes( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'Present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }
}
