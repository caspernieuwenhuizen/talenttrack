<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesShareQuery;

/**
 * #2835 — what share of the available minutes each player actually got.
 *
 * The reviewer's worked example is the first test, verbatim: ten completed
 * matches of seventy minutes make 700 available, and a player with 350
 * recorded minutes is on 50%. The rest pin the edges that make the number
 * mean something — where the denominator comes from, what a played match is,
 * and what happens when there is nothing to share out.
 */
final class MinutesShareTest extends WP_UnitTestCase {

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
     * Put the target back.
     *
     * `ConfigService` caches each key on the instance, and the instance
     * outlives a test: the DB rollback between tests undoes the row but not
     * the cache, so a clamped `-5` written here leaked into every later test
     * in the process — including the REST smoke suite, which read the target
     * as 0. Writing the default back through the same setter repairs both.
     */
    public function tear_down(): void {
        $this->setConfig( MinutesShareQuery::TARGET_CONFIG_KEY, (string) MinutesShareQuery::DEFAULT_TARGET_PCT );
        parent::tear_down();
    }

    /** The reviewer's example: 10 × 70 = 700 available, 350 played → 50%. */
    public function test_the_worked_example(): void {
        $team_id = $this->insertTeam( 'U14 example' );
        $player  = $this->insertPlayer( $team_id, 'Half', 'Season' );

        for ( $i = 1; $i <= 10; $i++ ) {
            $match = $this->insertMatch( $team_id, sprintf( '2026-03-%02d', $i ) );
            $this->setPrepHalfLength( $match, 35 );          // 2 × 35 = 70
            $this->insertAttendance( $match, $player, 35 );  // half of each match
        }

        $data = ( new MinutesShareQuery() )->forTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 10, $data['matches'] );
        $this->assertSame( 700, $data['available_minutes'] );
        $this->assertCount( 1, $data['players'] );
        $this->assertSame( 350, $data['players'][0]['minutes'] );
        $this->assertEqualsWithDelta( 50.0, $data['players'][0]['share_pct'], 0.001 );
        $this->assertFalse( $data['players'][0]['below_target'], '50% clears the 30% default' );
    }

    /**
     * The denominator is the match's own length, not a flat 70. A team on
     * 30-minute halves has 600 available over ten matches, and the same
     * recorded minutes therefore read as a bigger share.
     */
    public function test_available_minutes_follow_the_match_length(): void {
        $team_id = $this->insertTeam( 'U9 short halves' );
        $player  = $this->insertPlayer( $team_id, 'Short', 'Halves' );

        for ( $i = 1; $i <= 10; $i++ ) {
            $match = $this->insertMatch( $team_id, sprintf( '2026-04-%02d', $i ) );
            $this->setPrepHalfLength( $match, 30 );
            $this->insertAttendance( $match, $player, 30 );
        }

        $data = ( new MinutesShareQuery() )->forTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 600, $data['available_minutes'], '10 × (2 × 30)' );
        $this->assertEqualsWithDelta( 50.0, $data['players'][0]['share_pct'], 0.001 );
    }

    /**
     * A player who was in the squad and never got on reads 0%, and is
     * flagged. That is the case the report exists to surface.
     */
    public function test_a_player_who_never_played_is_flagged(): void {
        $team_id = $this->insertTeam( 'U14 bench' );
        $starter = $this->insertPlayer( $team_id, 'Every', 'Minute' );
        $benched = $this->insertPlayer( $team_id, 'Never', 'On' );

        $match = $this->insertMatch( $team_id, '2026-05-02' );
        $this->setPrepHalfLength( $match, 35 );
        $this->insertAttendance( $match, $starter, 70 );
        $this->insertAttendance( $match, $benched, 0 );

        $data = ( new MinutesShareQuery() )->forTeam( $team_id, self::FROM, self::TO );

        // Lowest share first — the point of the ordering.
        $this->assertSame( $benched, $data['players'][0]['player_id'] );
        $this->assertEqualsWithDelta( 0.0, $data['players'][0]['share_pct'], 0.001 );
        $this->assertTrue( $data['players'][0]['below_target'] );
        $this->assertFalse( $data['players'][1]['below_target'] );
    }

    /**
     * A fixture kicking off tonight is not part of the denominator — the
     * same rule the two minutes reports apply (#2833). Counting it would
     * make every player's share drop the morning of a match.
     */
    public function test_tonights_fixture_is_not_available_minutes_yet(): void {
        $team_id = $this->insertTeam( 'U14 tonight' );
        $player  = $this->insertPlayer( $team_id, 'Plays', 'Later' );

        $played = $this->insertMatch( $team_id, gmdate( 'Y-m-d', strtotime( '-3 days' ) ) );
        $this->setPrepHalfLength( $played, 35 );
        $this->insertAttendance( $played, $player, 70 );

        $tonight = $this->insertMatch( $team_id, gmdate( 'Y-m-d' ) );
        $this->setPrepHalfLength( $tonight, 35 );

        $data = ( new MinutesShareQuery() )->forTeam(
            $team_id,
            gmdate( 'Y-m-d', strtotime( '-1 month' ) ),
            gmdate( 'Y-m-d', strtotime( '+1 month' ) )
        );

        $this->assertSame( 1, $data['matches'] );
        $this->assertSame( 70, $data['available_minutes'] );
        $this->assertEqualsWithDelta( 100.0, $data['players'][0]['share_pct'], 0.001 );
    }

    /**
     * No played matches means no denominator. The share is null rather than
     * 0%, because 0% would flag a whole squad for a season that has not
     * started.
     */
    public function test_no_played_matches_leaves_the_share_undefined(): void {
        $team_id = $this->insertTeam( 'U14 preseason' );
        $this->insertPlayer( $team_id, 'Not', 'Started' );

        $data = ( new MinutesShareQuery() )->forTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 0, $data['available_minutes'] );
        $this->assertSame( [], $data['players'], 'no attendance rows, so no squad' );
    }

    /** The target is academy-configurable, and the flag follows it. */
    public function test_the_target_is_configurable(): void {
        $this->assertSame( MinutesShareQuery::DEFAULT_TARGET_PCT, MinutesShareQuery::targetPct() );

        $this->setConfig( MinutesShareQuery::TARGET_CONFIG_KEY, '60' );
        $this->assertSame( 60, MinutesShareQuery::targetPct() );

        $team_id = $this->insertTeam( 'U14 strict' );
        $player  = $this->insertPlayer( $team_id, 'Just', 'Under' );
        $match   = $this->insertMatch( $team_id, '2026-06-06' );
        $this->setPrepHalfLength( $match, 35 );
        $this->insertAttendance( $match, $player, 35 ); // 50%

        $data = ( new MinutesShareQuery() )->forTeam( $team_id, self::FROM, self::TO );

        $this->assertSame( 60, $data['target_pct'] );
        $this->assertTrue( $data['players'][0]['below_target'], '50% is below a 60% target' );
    }

    /** A stored target outside 0-100 is clamped rather than obeyed. */
    public function test_an_absurd_target_is_clamped(): void {
        $this->setConfig( MinutesShareQuery::TARGET_CONFIG_KEY, '250' );
        $this->assertSame( 100, MinutesShareQuery::targetPct() );

        $this->setConfig( MinutesShareQuery::TARGET_CONFIG_KEY, '-5' );
        $this->assertSame( 0, MinutesShareQuery::targetPct() );
    }

    /** The median is the median, not the mean — one ever-present player
     *  must not drag the squad figure above the line. */
    public function test_median_share_ignores_the_outlier(): void {
        $players = [
            [ 'share_pct' => 10.0 ],
            [ 'share_pct' => 20.0 ],
            [ 'share_pct' => 100.0 ],
        ];
        $this->assertSame( 20.0, MinutesShareQuery::medianShare( $players ) );
        $this->assertNull( MinutesShareQuery::medianShare( [] ) );
    }

    /** One player's row is the same answer the squad view gives. */
    public function test_for_player_matches_the_team_row(): void {
        $team_id = $this->insertTeam( 'U14 one player' );
        $player  = $this->insertPlayer( $team_id, 'Single', 'Row' );
        $match   = $this->insertMatch( $team_id, '2026-07-04' );
        $this->setPrepHalfLength( $match, 35 );
        $this->insertAttendance( $match, $player, 35 );

        $query = new MinutesShareQuery();
        $team  = $query->forTeam( $team_id, self::FROM, self::TO );
        $row   = $query->forPlayer( $team_id, $player, self::FROM, self::TO );

        $this->assertNotNull( $row );
        $this->assertSame( $team['players'][0]['minutes'], $row['minutes'] );
        $this->assertSame( $team['available_minutes'], $row['available_minutes'] );
        $this->assertNull(
            $query->forPlayer( $team_id, 99999999, self::FROM, self::TO ),
            'a player outside the squad has no row'
        );
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
        ] );
        return (int) $wpdb->insert_id;
    }

    private function setPrepHalfLength( int $activity_id, int $half ): void {
        global $wpdb;
        // `uuid` is CHAR(36) NOT NULL UNIQUE with no default, so every prep
        // row needs its own — inserting without one collides on the empty
        // string from the second row onwards.
        $wpdb->insert( "{$this->p}tt_match_prep", [
            'club_id'             => $this->club,
            'activity_id'         => $activity_id,
            'half_length_minutes' => $half,
            'uuid'                => wp_generate_uuid4(),
        ] );
    }

    private function insertAttendance( int $activity_id, int $player_id, ?int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }

    private function setConfig( string $key, string $value ): void {
        // Through the repository, not a raw insert: it owns the column names
        // and the per-request cache the reader goes through.
        \TT\Infrastructure\Query\QueryHelpers::set_config( $key, $value );
    }
}
