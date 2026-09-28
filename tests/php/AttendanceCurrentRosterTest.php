<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\PlayerStatus\PlayerAttendanceCalculator;
use TT\Infrastructure\Teams\TeamKpisRepository;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\PersonaDashboard\Repositories\TeamOverviewRepository;

/**
 * #4172 — team attendance counts the players currently in the team.
 *
 * The fixture: a player who moved from U12 to U13 mid-month, with two
 * trainings at U12 (both present) and two at U13 (one present, one absent).
 * A player who stayed at U12 attended one of two U12 trainings. A released
 * player still pointing at U12 attended both.
 *
 * Every team-level surface must show the mover in U13 only, on U13's two
 * trainings (50%), and leave them and the released player out of U12
 * entirely (U12 reads 50%, the stayer's figure). The mover's own history
 * keeps all four trainings.
 */
final class AttendanceCurrentRosterTest extends WP_UnitTestCase {

    private const CLUB = 4172;
    private const FROM = '2020-05-01';
    private const TO   = '2020-05-31';

    private int $u12      = 0;
    private int $u13      = 0;
    private int $mover    = 0;
    private int $stayer   = 0;
    private int $released = 0;

    /** @var callable */
    private $club_filter;

    public function set_up(): void {
        parent::set_up();
        $this->club_filter = static fn(): int => self::CLUB;
        add_filter( 'tt_current_club_id', $this->club_filter );

        $this->u12 = $this->insertTeam( 'Roster U12' );
        $this->u13 = $this->insertTeam( 'Roster U13' );

        // The mover's record already says U13: that is the move.
        $this->mover    = $this->insertPlayer( $this->u13, 'Moved', 'Up' );
        $this->stayer   = $this->insertPlayer( $this->u12, 'Stayed', 'Put' );
        $this->released = $this->insertPlayer( $this->u12, 'Let', 'Go', 'released' );

        $u12_a = $this->activity( $this->u12, '2020-05-04' );
        $u12_b = $this->activity( $this->u12, '2020-05-06' );
        $u13_a = $this->activity( $this->u13, '2020-05-18' );
        $u13_b = $this->activity( $this->u13, '2020-05-20' );

        $this->attend( $u12_a, $this->mover, 'Present' );
        $this->attend( $u12_b, $this->mover, 'Present' );
        $this->attend( $u13_a, $this->mover, 'Present' );
        $this->attend( $u13_b, $this->mover, 'Absent' );

        $this->attend( $u12_a, $this->stayer, 'Present' );
        $this->attend( $u12_b, $this->stayer, 'Absent' );

        $this->attend( $u12_a, $this->released, 'Present' );
        $this->attend( $u12_b, $this->released, 'Present' );
    }

    public function tear_down(): void {
        remove_filter( 'tt_current_club_id', $this->club_filter );
        parent::tear_down();
    }

    public function test_rows_count_the_mover_for_the_new_team_only(): void {
        $query = new AttendanceRankingQuery();

        $u12 = $query->rows( self::FROM, self::TO, $this->u12 );
        $this->assertSame( [ $this->stayer ], array_column( $u12, 'player_id' ), 'U12 lists its current squad only' );

        $u13 = $query->rows( self::FROM, self::TO, $this->u13 );
        $this->assertSame( [ $this->mover ], array_column( $u13, 'player_id' ) );
        $this->assertSame( 2, $u13[0]['activities'], 'U13 activities only' );
        $this->assertSame( 50.0, $u13[0]['present_pct'] );
        $this->assertSame( 'Roster U13', $u13[0]['team_name'] );
    }

    public function test_the_academy_wide_leaderboard_shows_the_mover_once_on_the_new_team(): void {
        $board = ( new AttendanceRankingQuery() )->leaderboard( self::FROM, self::TO );
        $ids   = array_column( $board['bottom'], 'player_id' );

        $this->assertSame( 2, $board['total'], 'the released player is off every roster' );
        $this->assertSame( 1, count( array_keys( $ids, $this->mover, true ) ) );

        foreach ( $board['bottom'] as $row ) {
            if ( $row['player_id'] !== $this->mover ) continue;
            $this->assertSame( 2, $row['total'], 'measured on U13 activities' );
            $this->assertSame( 50.0, $row['present_pct'] );
        }
    }

    public function test_team_rows_total_the_current_squad(): void {
        $teams = [];
        foreach ( ( new AttendanceRankingQuery() )->teamRows( self::FROM, self::TO ) as $row ) {
            $teams[ $row['team_id'] ] = $row;
        }

        $this->assertSame( 2, $teams[ $this->u12 ]['total'], 'the mover and the released player leave U12' );
        $this->assertSame( 50.0, $teams[ $this->u12 ]['present_pct'] );
        $this->assertSame( 2, $teams[ $this->u13 ]['total'] );
        $this->assertSame( 50.0, $teams[ $this->u13 ]['present_pct'] );
    }

    public function test_the_other_team_surfaces_agree_with_the_report(): void {
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->u12, self::FROM, self::TO, [ 'attendance' ] );
        $this->assertSame(
            [ $this->stayer ],
            array_column( $monthly['data']['attendance']['rows'], 'player_id' ),
            'team monthly report'
        );

        $days = (int) ceil( ( time() - (int) strtotime( self::FROM ) ) / DAY_IN_SECONDS ) + 1;

        $overview = ( new TeamOverviewRepository() )->teamPlayerBreakdown( $this->u13, $days );
        $this->assertSame( [ $this->mover ], array_column( $overview, 'player_id' ) );
        $this->assertSame( 50.0, $overview[0]['attendance_pct'], 'team overview: U13 activities only' );

        $this->assertSame( [ $this->stayer ], array_column(
            ( new TeamOverviewRepository() )->teamPlayerBreakdown( $this->u12, $days ),
            'player_id'
        ), 'the released player is not in the team overview' );

        $this->assertSame( 50, ( new TeamKpisRepository() )->avgAttendance( $this->u12, $days ), 'team detail KPI' );
    }

    public function test_the_players_own_history_keeps_the_previous_team(): void {
        $score = ( new PlayerAttendanceCalculator() )->scoreFor( $this->mover, self::FROM, self::TO );
        $this->assertSame( 4, $score['sessions'], 'U12 and U13 both count toward the player' );
        $this->assertSame( 3, $score['present'] );
    }

    // ---- fixtures -------------------------------------------------------

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => self::CLUB, 'name' => $name, 'age_group' => 'U12' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last, string $status = 'active' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => self::CLUB, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => $last,
            'status' => $status, 'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function activity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => self::CLUB,
            'team_id'             => $team_id,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attend( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => self::CLUB,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'record_type' => 'actual',
            'is_guest'    => 0,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'the fixture wrote' );
    }
}
