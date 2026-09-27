<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\PlayerStatus\PlayerAttendanceCalculator;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\KpiSnapshotXlsxExporter;
use TT\Modules\Pdp\EvidencePacket;
use TT\Modules\PersonaDashboard\Repositories\TeamOverviewRepository;

/**
 * #4041 — one player, one window, one percentage, whichever surface asks.
 *
 * A coach comparing a player's attendance on the player report, the
 * leaderboard, the team report and an export used to get up to five
 * different numbers, none of which said which rule it used. The rule now
 * lives in AttendanceFlagService: attended = present + late, missed =
 * absent + excused + injured, over the player's own team's activities.
 *
 * The fixture is chosen so every wrong rule gives a different answer:
 * present-only reads 33.3, present / (present + absent) reads 66.7, and a
 * counted guest appearance reads 57.1. Only the rule reads 50.
 *
 * The status light is the one named exception, and is pinned here too.
 *
 * Runs on its own club id so rows other tests leave behind cannot reach the
 * club-wide export figure.
 */
final class AttendanceOneRuleTest extends WP_UnitTestCase {

    private const CLUB = 4041;
    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $team   = 0;
    private int $other  = 0;
    private int $player = 0;

    /** @var callable */
    private $club_filter;

    public function set_up(): void {
        parent::set_up();
        $this->club_filter = static fn(): int => self::CLUB;
        add_filter( 'tt_current_club_id', $this->club_filter );

        $this->team   = $this->insertTeam( 'One rule U13' );
        $this->other  = $this->insertTeam( 'One rule U14' );
        $this->player = $this->insertPlayer( $this->team );

        // Six of the player's own team's trainings: two present, one late,
        // one absent, one excused, one injured. Statuses in mixed case, as
        // the register stores them.
        $this->attend( $this->activity( $this->team, '2020-03-02' ), 'Present' );
        $this->attend( $this->activity( $this->team, '2020-03-04' ), 'present' );
        $this->attend( $this->activity( $this->team, '2020-03-09' ), 'Late' );
        $this->attend( $this->activity( $this->team, '2020-03-11' ), 'Absent' );
        $this->attend( $this->activity( $this->team, '2020-03-16' ), 'excused' );
        $this->attend( $this->activity( $this->team, '2020-03-18' ), 'Injured' );

        // And a guest appearance with the older team, which must count
        // toward nobody's percentage.
        $this->attend( $this->activity( $this->other, '2020-03-21' ), 'Present', true );
    }

    public function tear_down(): void {
        remove_filter( 'tt_current_club_id', $this->club_filter );
        parent::tear_down();
    }

    public function test_every_surface_gives_the_same_percentage(): void {
        $expected = 50.0;

        // Player attendance report.
        $rows = ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $this->team );
        $this->assertCount( 1, $rows );
        $this->assertSame( $expected, $rows[0]['present_pct'], 'player report' );
        $this->assertSame( 3, $rows[0]['missed'], 'missed is absent + excused + injured' );

        // Leaderboard.
        $board = ( new AttendanceRankingQuery() )->leaderboard( self::FROM, self::TO, 0, $this->team );
        $this->assertSame( $expected, $board['bottom'][0]['present_pct'], 'leaderboard' );

        // Team attendance report: one player, so the team is the player.
        $teams = ( new AttendanceRankingQuery() )->teamRows( self::FROM, self::TO );
        $this->assertSame( [ $this->team ], array_column( $teams, 'team_id' ), 'the guest appearance gives the other team no row' );
        $this->assertSame( $expected, $teams[0]['present_pct'], 'team report' );

        // Export: the KPI snapshot's club-wide figure.
        $this->assertSame( $expected, $this->snapshotPct(), 'KPI snapshot export' );

        // Team monthly report, team overview and the evidence packet.
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'attendance' ] );
        $this->assertSame( $expected, $monthly['data']['attendance']['rows'][0]['present_pct'], 'team monthly report' );

        $overview = ( new TeamOverviewRepository() )->teamPlayerBreakdown( $this->team, self::daysSince( self::FROM ) );
        $this->assertSame( $expected, $overview[0]['attendance_pct'], 'team overview' );

        $packet = EvidencePacket::attendanceFor( $this->player, self::FROM, self::TO );
        $this->assertSame( $expected, (float) $packet['rate'], 'evidence packet and talking points' );
        $this->assertSame( 3, $packet['missed'] );
    }

    /**
     * The one exception: excused and injured leave the status score's
     * denominator, late still counts as attended, and a guest appearance
     * does not raise it. 3 attended of 4 countable = 75.
     */
    public function test_the_status_light_is_the_one_named_exception(): void {
        $score = ( new PlayerAttendanceCalculator() )->scoreFor( $this->player, self::FROM, self::TO );

        $this->assertSame( 75.0, $score['score'] );
        $this->assertSame( 6, $score['sessions'], 'the guest appearance is not one of them' );
        $this->assertSame(
            AttendanceFlagService::presentPctForStatus( 3, 6, 1, 1 ),
            $score['score'],
            'computed by the named method, not a second formula'
        );
    }

    public function test_an_injury_or_an_excused_absence_does_not_lower_the_status_score(): void {
        $before = ( new PlayerAttendanceCalculator() )->scoreFor( $this->player, self::FROM, self::TO )['score'];

        $this->attend( $this->activity( $this->team, '2020-03-23' ), 'Injured' );
        $this->attend( $this->activity( $this->team, '2020-03-25' ), 'Excused' );

        $after = ( new PlayerAttendanceCalculator() )->scoreFor( $this->player, self::FROM, self::TO )['score'];
        $this->assertSame( $before, $after );
    }

    public function test_the_rule_reads_status_in_any_case(): void {
        $this->assertTrue( AttendanceFlagService::isAttended( 'Late' ) );
        $this->assertTrue( AttendanceFlagService::isAttended( 'present' ) );
        $this->assertFalse( AttendanceFlagService::isAttended( 'Excused' ) );
        $this->assertTrue( AttendanceFlagService::isMissed( 'INJURED' ) );
        $this->assertFalse( AttendanceFlagService::isMissed( 'late' ) );
    }

    // ---- fixtures -------------------------------------------------------

    private function snapshotPct() {
        $payload = ( new KpiSnapshotXlsxExporter() )->collect( new ExportRequest(
            'kpi_snapshot', 'xlsx', self::CLUB, 0, null,
            [ 'date_from' => self::FROM, 'date_to' => self::TO ]
        ) );
        $sheet = (array) ( ( (array) $payload['sheets'] )[ __( 'KPI snapshot', 'talenttrack' ) ] ?? [] );
        foreach ( (array) ( $sheet[1] ?? [] ) as $row ) {
            if ( (string) $row[0] === __( 'Attendance present %', 'talenttrack' ) ) return $row[1];
        }
        return null;
    }

    private static function daysSince( string $date ): int {
        return (int) ceil( ( time() - (int) strtotime( $date ) ) / DAY_IN_SECONDS ) + 1;
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => self::CLUB, 'name' => $name, 'age_group' => 'U13' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => self::CLUB, 'team_id' => $team_id, 'first_name' => 'One', 'last_name' => 'Rule',
            'status' => 'active', 'wp_user_id' => null,
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

    private function attend( int $activity_id, string $status, bool $guest = false ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => self::CLUB,
            'activity_id' => $activity_id,
            'player_id'   => $this->player,
            'status'      => $status,
            'record_type' => 'actual',
            'is_guest'    => $guest ? 1 : 0,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'the fixture wrote' );
    }
}
