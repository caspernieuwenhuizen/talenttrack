<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;

/**
 * #2158 / #2160 — minutes aggregation hardening + audit reconciliation.
 *
 * Runs against the real schema (wp-env tests-cli). Asserts the
 * player-centric data-quality contract for minutes:
 *
 *   - Fan-out: a player with MORE THAN ONE actual attendance row for the
 *     same match is counted ONCE (summed), not double-counted by a JOIN
 *     fan-out (#2158 cause 1).
 *   - No fabrication: a match with no persisted minutes, no execution and
 *     no lineup contributes 0 / nothing — never a "credit each starter a
 *     half" estimate (#2158 cause 2).
 *   - Guests and `expected` rows never count toward minutes.
 *   - The per-match breakdown sums EXACTLY to the team-report total
 *     (#2160 reconciliation).
 */
final class MinutesQueryHardeningTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    /**
     * Two `actual` attendance rows for the same (player, match) — e.g. a
     * legacy duplicate — must sum to one match row, not fan out / double.
     */
    public function test_duplicate_attendance_row_is_not_double_counted(): void {
        $team_id   = $this->insertTeam( 'U17 fanout' );
        $player_id = $this->insertPlayer( $team_id, 'Fan', 'Out' );
        $activity_id = $this->insertMatch( $team_id, '2026-02-01' );

        // Duplicate actual rows: 60' and 30' for the same match.
        $this->insertAttendance( $activity_id, $player_id, 'actual', 0, 60 );
        $this->insertAttendance( $activity_id, $player_id, 'actual', 0, 30 );

        $from = '2026-01-01';
        $to   = '2026-12-31';

        $breakdown = ( new MinutesQuery() )->matchBreakdownForPlayer( $team_id, $player_id, $from, $to );

        $this->assertCount( 1, $breakdown, 'a duplicate attendance row must collapse to ONE match row' );
        $this->assertSame( 90, (int) $breakdown[0]['minutes'], 'the two rows must SUM (60+30), not fan out to 180' );

        // The team report total must equal the breakdown sum (reconciliation).
        $rows  = ( new MinutesQuery() )->forTeam( $team_id, $from, $to );
        $total = $this->totalFor( $rows, $player_id );
        $sum   = array_sum( array_column( $breakdown, 'minutes' ) );
        $this->assertSame( $sum, $total, 'team-report total must reconcile with the per-match breakdown' );
        $this->assertSame( 90, $total );
    }

    /**
     * A match with no persisted minutes, no execution and no lineup must
     * contribute NOTHING — the old "credit each starter a half" estimate
     * is gone.
     */
    public function test_match_without_real_data_is_not_fabricated(): void {
        $team_id   = $this->insertTeam( 'U18 nodata' );
        $player_id = $this->insertPlayer( $team_id, 'No', 'Data' );
        // A match exists, the player even has an attendance row, but with
        // NULL minutes and no execution/lineup → no real minutes data.
        $activity_id = $this->insertMatch( $team_id, '2026-03-01' );
        $this->insertAttendance( $activity_id, $player_id, 'actual', 0, null );

        $breakdown = ( new MinutesQuery() )->matchBreakdownForPlayer( $team_id, $player_id, '2026-01-01', '2026-12-31' );
        $this->assertSame( [], $breakdown, 'no persisted/real minutes must produce an empty breakdown, never an estimate' );

        $rows  = ( new MinutesQuery() )->forTeam( $team_id, '2026-01-01', '2026-12-31' );
        $this->assertSame( 0, $this->totalFor( $rows, $player_id ), 'a no-data match contributes 0, not a fabricated estimate' );
    }

    /**
     * Guest rows and `expected` (planned) rows never count toward minutes.
     */
    public function test_guest_and_expected_rows_are_excluded(): void {
        $team_id   = $this->insertTeam( 'U19 guards' );
        $player_id = $this->insertPlayer( $team_id, 'Real', 'Player' );
        $activity_id = $this->insertMatch( $team_id, '2026-04-01' );

        $this->insertAttendance( $activity_id, $player_id, 'actual', 0, 70 );  // counts
        $this->insertAttendance( $activity_id, $player_id, 'actual', 1, 45 );  // guest → excluded
        $this->insertAttendance( $activity_id, $player_id, 'expected', 0, 90 ); // planned → excluded

        $breakdown = ( new MinutesQuery() )->matchBreakdownForPlayer( $team_id, $player_id, '2026-01-01', '2026-12-31' );
        $this->assertCount( 1, $breakdown );
        $this->assertSame( 70, (int) $breakdown[0]['minutes'], 'only the actual / non-guest row counts' );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @param list<array<string,mixed>> $rows */
    private function totalFor( array $rows, int $player_id ): int {
        foreach ( $rows as $r ) {
            if ( (int) $r['player_id'] === $player_id ) return (int) $r['total_minutes'];
        }
        return 0;
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

    private function insertMatch( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $team_id,
            'title'             => 'Match ' . $date,
            'session_date'      => $date,
            'activity_type_key' => 'match',
            'game_subtype_key'  => 'League',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $record_type, int $is_guest, ?int $minutes ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => $is_guest,
            'record_type'    => $record_type,
            'minutes_played' => $minutes,
        ] );
        return (int) $wpdb->insert_id;
    }
}
