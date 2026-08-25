<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;

/**
 * #2433 — the team minutes report's match count must never contradict the
 * squad beside it.
 *
 * The old inline count in `FrontendStandardReportsView` carried none of the
 * exclusions its sibling queries carry and had no upper date bound, so
 * archived, trashed, cancelled and not-yet-played fixtures all counted. A
 * team with an imported fixture list and no recorded minutes rendered as
 * "19 matches, 0 players" — two numbers from two different definitions of
 * "a match".
 *
 * `matchCountsForTeam()` returns both numbers the report needs:
 *   - `recorded` shares its predicate with `forTeam()`, so it can never
 *     exceed what the per-player rows account for.
 *   - `played` is the honest denominator: past-dated fixtures that were not
 *     deleted or cancelled.
 */
final class MinutesMatchCountsTest extends WP_UnitTestCase {

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
     * The reported "19 matches, 0 players" shape: fixtures on the calendar,
     * no minutes recorded anywhere. `recorded` must be 0 — never the
     * fixture count — while `played` still surfaces the 3 that were played.
     */
    public function test_fixtures_without_minutes_report_zero_recorded(): void {
        $team_id = $this->insertTeam( 'U14 fixtures only' );
        $this->insertMatch( $team_id, '2026-02-01' );
        $this->insertMatch( $team_id, '2026-02-08' );
        $this->insertMatch( $team_id, '2026-02-15' );

        $counts = ( new MinutesQuery() )->matchCountsForTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 0, $counts['recorded'], 'no recorded minutes must count 0 matches, not the fixture count' );
        $this->assertSame( 3, $counts['played'], 'the fixtures themselves are still the denominator' );
    }

    /**
     * Archived, trashed and cancelled fixtures are excluded from BOTH
     * counts — the old query counted all three.
     */
    public function test_deleted_and_cancelled_matches_are_excluded(): void {
        $team_id   = $this->insertTeam( 'U15 deleted' );
        $player_id = $this->insertPlayer( $team_id, 'Ex', 'Cluded' );

        $live = $this->insertMatch( $team_id, '2026-03-01' );
        $this->insertAttendance( $live, $player_id, 'actual', 0, 60 );

        foreach ( [ 'archived_at' => '2026-03-05 00:00:00', 'trashed_at' => '2026-03-05 00:00:00' ] as $col => $val ) {
            $id = $this->insertMatch( $team_id, '2026-03-02' );
            $this->insertAttendance( $id, $player_id, 'actual', 0, 90 );
            $this->setActivity( $id, [ $col => $val ] );
        }
        $cancelled_plan = $this->insertMatch( $team_id, '2026-03-03' );
        $this->insertAttendance( $cancelled_plan, $player_id, 'actual', 0, 90 );
        $this->setActivity( $cancelled_plan, [ 'plan_state' => 'cancelled' ] );

        $cancelled_status = $this->insertMatch( $team_id, '2026-03-04' );
        $this->insertAttendance( $cancelled_status, $player_id, 'actual', 0, 90 );
        $this->setActivity( $cancelled_status, [ 'activity_status_key' => 'cancelled' ] );

        $counts = ( new MinutesQuery() )->matchCountsForTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 1, $counts['recorded'], 'only the live match counts as recorded' );
        $this->assertSame( 1, $counts['played'], 'archived / trashed / cancelled fixtures are not "played"' );
    }

    /**
     * A fixture dated in the future has not been played, so it cannot be
     * part of the denominator. The old query had no upper bound at all.
     */
    public function test_future_fixtures_are_not_counted_as_played(): void {
        $team_id = $this->insertTeam( 'U16 future' );
        $future  = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
        $past    = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $this->insertMatch( $team_id, $future );
        $this->insertMatch( $team_id, $past );

        $counts = ( new MinutesQuery() )->matchCountsForTeam(
            $team_id,
            gmdate( 'Y-m-d', strtotime( '-12 months' ) ),
            gmdate( 'Y-m-d', strtotime( '+12 months' ) )
        );

        $this->assertSame( 1, $counts['played'], 'a fixture in the future has not been played' );
    }

    /**
     * The window is respected on both ends — a match outside it counts for
     * neither number.
     */
    public function test_matches_outside_the_window_are_excluded(): void {
        $team_id   = $this->insertTeam( 'U17 window' );
        $player_id = $this->insertPlayer( $team_id, 'Out', 'Window' );

        $inside  = $this->insertMatch( $team_id, '2026-06-01' );
        $outside = $this->insertMatch( $team_id, '2025-06-01' );
        $this->insertAttendance( $inside, $player_id, 'actual', 0, 90 );
        $this->insertAttendance( $outside, $player_id, 'actual', 0, 90 );

        $counts = ( new MinutesQuery() )->matchCountsForTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 1, $counts['recorded'] );
        $this->assertSame( 1, $counts['played'] );
    }

    /**
     * Guest rows, `expected` rows and zero-minute rows are not "recorded
     * minutes" — the same guards `forTeam()` applies.
     */
    public function test_guest_expected_and_zero_minute_rows_are_not_recorded(): void {
        $team_id   = $this->insertTeam( 'U18 guards' );
        $player_id = $this->insertPlayer( $team_id, 'Guard', 'Ed' );

        $guest = $this->insertMatch( $team_id, '2026-04-01' );
        $this->insertAttendance( $guest, $player_id, 'actual', 1, 90 );

        $expected = $this->insertMatch( $team_id, '2026-04-08' );
        $this->insertAttendance( $expected, $player_id, 'expected', 0, 90 );

        $zero = $this->insertMatch( $team_id, '2026-04-15' );
        $this->insertAttendance( $zero, $player_id, 'actual', 0, 0 );

        $counts = ( new MinutesQuery() )->matchCountsForTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 0, $counts['recorded'], 'guest / expected / zero-minute rows are not recorded minutes' );
        $this->assertSame( 3, $counts['played'], 'the fixtures were still played' );
    }

    /**
     * The reconciliation contract: `recorded` can never claim more matches
     * than the per-player breakdowns account for. A duplicate attendance
     * row must not inflate it either (the #2158 fan-out shape).
     */
    public function test_recorded_reconciles_with_the_player_breakdowns(): void {
        $team_id = $this->insertTeam( 'U19 reconcile' );
        $a       = $this->insertPlayer( $team_id, 'Play', 'One' );
        $b       = $this->insertPlayer( $team_id, 'Play', 'Two' );

        $m1 = $this->insertMatch( $team_id, '2026-05-01' );
        $m2 = $this->insertMatch( $team_id, '2026-05-08' );
        $this->insertAttendance( $m1, $a, 'actual', 0, 60 );
        $this->insertAttendance( $m1, $a, 'actual', 0, 30 ); // duplicate row
        $this->insertAttendance( $m1, $b, 'actual', 0, 90 );
        $this->insertAttendance( $m2, $a, 'actual', 0, 45 );

        $query  = new MinutesQuery();
        $counts = $query->matchCountsForTeam( $team_id, self::FROM, self::TO );

        $distinct = [];
        foreach ( [ $a, $b ] as $pid ) {
            foreach ( $query->matchBreakdownForPlayer( $team_id, $pid, self::FROM, self::TO ) as $row ) {
                $distinct[ (int) $row['activity_id'] ] = true;
            }
        }

        $this->assertSame( 2, $counts['recorded'], 'two matches produced minutes' );
        $this->assertSame(
            count( $distinct ),
            $counts['recorded'],
            'the match count must equal the distinct matches the breakdowns show'
        );
    }

    /**
     * #2833 — the reported shape: a fixture dated TODAY, kick-off still to
     * come, status `planned`. The old bound was `session_date <= CURDATE()`,
     * so it counted, and the report read "1 van 2 gespeelde wedstrijden
     * vastgelegd" beside a single played match — with an amber warning about
     * a match nobody had kicked off.
     */
    public function test_todays_planned_fixture_is_not_played_yet(): void {
        $team_id = $this->insertTeam( 'U14 tonight' );
        $today   = gmdate( 'Y-m-d' );

        $played = $this->insertMatch( $team_id, gmdate( 'Y-m-d', strtotime( '-3 days' ) ) );
        $this->setActivity( $played, [ 'activity_status_key' => 'completed' ] );

        $tonight = $this->insertMatch( $team_id, $today );
        $this->setActivity( $tonight, [ 'activity_status_key' => 'planned' ] );

        $counts = ( new MinutesQuery() )->matchCountsForTeam(
            $team_id,
            gmdate( 'Y-m-d', strtotime( '-1 month' ) ),
            gmdate( 'Y-m-d', strtotime( '+1 month' ) )
        );

        $this->assertSame( 1, $counts['played'], "tonight's fixture is not played until it is completed" );
    }

    /**
     * #2833 — the other half of the contradiction. Minutes recorded against
     * a player who has since been archived were counted by `recorded` and
     * dropped by the squad query beside it, which is how the report showed
     * "1 match recorded" above "0 players in selection" and an empty state
     * saying no minutes had been recorded at all.
     *
     * Archived players count in NEITHER number: the report is about the squad
     * as it stands, and a count the rows beneath it cannot explain is worse
     * than a smaller one.
     */
    public function test_minutes_from_an_archived_player_are_not_recorded(): void {
        $team_id  = $this->insertTeam( 'U14 archived' );
        $gone     = $this->insertPlayer( $team_id, 'Left', 'Academy' );
        $match    = $this->insertMatch( $team_id, '2026-05-20' );
        $this->insertAttendance( $match, $gone, 'actual', 0, 90 );
        $this->archivePlayer( $gone );

        $counts = ( new MinutesQuery() )->matchCountsForTeam( $team_id, self::FROM, self::TO );

        $this->assertSame(
            0,
            $counts['recorded'],
            'minutes belonging to an archived player cannot be counted by a report whose squad excludes them'
        );
        $this->assertSame( 1, $counts['played'], 'the match itself was still played' );
    }

    /**
     * #2833 / #2407 — completion is an explicit act, so a grid bulk-save
     * writes minutes without flipping the status. Those matches were played,
     * whatever the status field says, and must stay in the denominator:
     * otherwise `recorded` would exceed `played` on the same screen.
     */
    public function test_minutes_make_a_match_played_even_while_it_says_planned(): void {
        $team_id   = $this->insertTeam( 'U14 wizard off' );
        $player_id = $this->insertPlayer( $team_id, 'Grid', 'Entry' );

        $match = $this->insertMatch( $team_id, gmdate( 'Y-m-d' ) );
        $this->setActivity( $match, [ 'activity_status_key' => 'planned' ] );
        $this->insertAttendance( $match, $player_id, 'actual', 0, 70 );

        $counts = ( new MinutesQuery() )->matchCountsForTeam(
            $team_id,
            gmdate( 'Y-m-d', strtotime( '-1 month' ) ),
            gmdate( 'Y-m-d', strtotime( '+1 month' ) )
        );

        $this->assertSame( 1, $counts['recorded'] );
        $this->assertSame( 1, $counts['played'], 'recorded minutes are evidence the match happened' );
        $this->assertGreaterThanOrEqual(
            $counts['recorded'],
            $counts['played'],
            'the report reads "N of M recorded" — recorded can never exceed played'
        );
    }

    public function test_unknown_team_returns_zeroes(): void {
        $counts = ( new MinutesQuery() )->matchCountsForTeam( 0, self::FROM, self::TO );
        $this->assertSame( [ 'recorded' => 0, 'played' => 0 ], $counts );
    }

    // ── seed helpers ────────────────────────────────────────────────

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

    private function archivePlayer( int $player_id ): void {
        global $wpdb;
        $wpdb->update(
            "{$this->p}tt_players",
            [ 'archived_at' => '2026-06-30 00:00:00' ],
            [ 'id' => $player_id ]
        );
    }

    /** @param array<string,string> $data */
    private function setActivity( int $activity_id, array $data ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_activities", $data, [ 'id' => $activity_id ] );
    }
}
