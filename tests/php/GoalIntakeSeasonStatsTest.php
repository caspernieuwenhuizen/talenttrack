<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;

/**
 * #2864 — the printed goal-intake sheet's "Wedstr." and "Minuten" must
 * describe the same season the minutes report describes.
 *
 * The sheet used to run its own SQL: no activity-type filter, so trainings
 * and meetings counted as matches; no archived / trashed / cancelled guard
 * and no upper date bound, so deleted fixtures and next month's calendar
 * counted too; and no `record_type` filter on the minutes, so planned-roster
 * rows were summed alongside real ones. Two printed sheets read 36 matches /
 * 140 minutes and 35 matches / 300 minutes — neither pair is possible, and a
 * coach opening a season-goals conversation could not tell which number to
 * trust.
 *
 * `MinutesQuery::seasonTotalsForPlayer()` is now the single answer, sharing
 * its predicate with `matchCountsForTeam()`'s `recorded` branch. These tests
 * assert against that shared method, and the last one compares the two code
 * paths directly rather than two hard-coded numbers — a future edit that
 * moves one without the other fails here.
 */
final class GoalIntakeSeasonStatsTest extends WP_UnitTestCase {

    private const FROM = '2026-01-01';
    private const TO   = '2026-12-31';

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    /**
     * The reported shape: a pile of attended trainings inflating the match
     * count, with the minutes figure summing every activity type on top.
     */
    public function test_trainings_do_not_count_as_matches_or_minutes(): void {
        $team_id   = $this->insertTeam( 'U14 mixed' );
        $player_id = $this->insertPlayer( $team_id, 'Joeri', 'Veenhof' );

        // One real match: 60 minutes.
        $match = $this->insertActivity( $team_id, '2026-03-01', 'match' );
        $this->insertAttendance( $match, $player_id, 'actual', 60 );

        // Ten trainings the player attended, each carrying minutes.
        for ( $i = 1; $i <= 10; $i++ ) {
            $training = $this->insertActivity( $team_id, '2026-03-' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ), 'training' );
            $this->insertAttendance( $training, $player_id, 'actual', 90 );
        }

        $totals = ( new MinutesQuery() )->seasonTotalsForPlayer( $player_id, self::FROM, self::TO );

        $this->assertSame( 1, $totals['apps'], 'only the match is an appearance' );
        $this->assertSame( 60, $totals['minutes'], 'training minutes are not match minutes' );
    }

    /**
     * Archived, trashed and cancelled fixtures, and a fixture that has not
     * been played yet, count toward neither figure.
     */
    public function test_deleted_cancelled_and_future_matches_are_excluded(): void {
        $team_id   = $this->insertTeam( 'U15 excluded' );
        $player_id = $this->insertPlayer( $team_id, 'Ex', 'Cluded' );

        $live = $this->insertActivity( $team_id, '2026-04-01', 'match' );
        $this->insertAttendance( $live, $player_id, 'actual', 90 );

        foreach ( [ 'archived_at', 'trashed_at' ] as $col ) {
            $id = $this->insertActivity( $team_id, '2026-04-02', 'match' );
            $this->insertAttendance( $id, $player_id, 'actual', 90 );
            $this->setActivity( $id, [ $col => '2026-04-05 00:00:00' ] );
        }

        $cancelled = $this->insertActivity( $team_id, '2026-04-03', 'match' );
        $this->insertAttendance( $cancelled, $player_id, 'actual', 90 );
        $this->setActivity( $cancelled, [ 'plan_state' => 'cancelled' ] );

        $totals = ( new MinutesQuery() )->seasonTotalsForPlayer( $player_id, self::FROM, self::TO );

        $this->assertSame( 1, $totals['apps'] );
        $this->assertSame( 90, $totals['minutes'] );
    }

    /**
     * The window is bounded at both ends. A fixture outside it is not part
     * of this season's sheet — the old query had no upper bound at all, so
     * next month's calendar printed as matches already played.
     */
    public function test_the_window_is_bounded_at_both_ends(): void {
        $team_id   = $this->insertTeam( 'U16 window' );
        $player_id = $this->insertPlayer( $team_id, 'Win', 'Dow' );

        $inside = $this->insertActivity( $team_id, '2026-06-01', 'match' );
        $this->insertAttendance( $inside, $player_id, 'actual', 45 );

        $before = $this->insertActivity( $team_id, '2025-06-01', 'match' );
        $this->insertAttendance( $before, $player_id, 'actual', 90 );

        $after = $this->insertActivity( $team_id, '2027-06-01', 'match' );
        $this->insertAttendance( $after, $player_id, 'actual', 90 );

        $totals = ( new MinutesQuery() )->seasonTotalsForPlayer( $player_id, self::FROM, self::TO );

        $this->assertSame( 1, $totals['apps'] );
        $this->assertSame( 45, $totals['minutes'] );
    }

    /**
     * Planned-roster rows are not minutes played. This is the defect that
     * made the minutes half of the sheet wrong even where the match count
     * happened to look plausible.
     */
    public function test_planned_rows_do_not_contribute(): void {
        $team_id   = $this->insertTeam( 'U17 planned' );
        $player_id = $this->insertPlayer( $team_id, 'Plan', 'Ned' );

        $match = $this->insertActivity( $team_id, '2026-05-01', 'match' );
        $this->insertAttendance( $match, $player_id, 'planned', 90 );

        $totals = ( new MinutesQuery() )->seasonTotalsForPlayer( $player_id, self::FROM, self::TO );

        $this->assertSame( 0, $totals['apps'], 'a planned row is not an appearance' );
        $this->assertSame( 0, $totals['minutes'], 'a planned row is not minutes played' );
    }

    /**
     * The point of the whole change: the sheet and the team minutes report
     * must not describe different seasons. For a single-team player the
     * per-player total and the team's `recorded` count are the same answer,
     * so compare the two code paths rather than two literals.
     */
    public function test_player_totals_agree_with_the_team_minutes_report(): void {
        $team_id   = $this->insertTeam( 'U14 agreement' );
        $player_id = $this->insertPlayer( $team_id, 'Agree', 'Ment' );

        foreach ( [ '2026-07-01', '2026-07-08', '2026-07-15' ] as $date ) {
            $match = $this->insertActivity( $team_id, $date, 'match' );
            $this->insertAttendance( $match, $player_id, 'actual', 70 );
        }
        // Noise the sheet used to count and the report never did.
        $training = $this->insertActivity( $team_id, '2026-07-02', 'training' );
        $this->insertAttendance( $training, $player_id, 'actual', 90 );

        $query  = new MinutesQuery();
        $totals = $query->seasonTotalsForPlayer( $player_id, self::FROM, self::TO );
        $counts = $query->matchCountsForTeam( $team_id, self::FROM, self::TO );

        $this->assertSame(
            $counts['recorded'],
            $totals['apps'],
            'the intake sheet and the team minutes report must count the same matches'
        );
        $this->assertSame( 210, $totals['minutes'] );
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

    private function insertActivity( int $team_id, string $date, string $type ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $team_id,
            'title'             => ucfirst( $type ) . ' ' . $date,
            'session_date'      => $date,
            'activity_type_key' => $type,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $record_type, ?int $minutes ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => $record_type,
            'minutes_played' => $minutes,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,string> $data */
    private function setActivity( int $activity_id, array $data ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_activities", $data, [ 'id' => $activity_id ] );
    }
}
