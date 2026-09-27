<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Frontend\FrontendMinutesGridView;
use TT\Modules\Activities\Reports\MatchResultQuery;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;

/**
 * #4066 — `tt_activities.home_score` is what we scored, whatever the venue.
 *
 * #3530 settled the convention and removed the venue swap from
 * `recentResultsForTeam()`. Three readers kept swapping on an away match, so
 * an away 3-1 win read as a 1-3 defeat in the monthly report and the team
 * statistics tab, and the minutes grid wrote "our goals" into `away_score`.
 * Every case below is an away fixture, because a home fixture reads the same
 * either way and would never have caught it.
 */
final class AwayScoreFramingTest extends WP_UnitTestCase {

    private const TEAM_ID = 4066;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    private function seedAway( int $id, string $date, ?int $home, ?int $away ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => $id,
            'team_id'           => self::TEAM_ID,
            'title'             => 'Fixture ' . $id,
            'session_date'      => $date,
            'activity_type_key' => 'game',
            'opponent'          => "Blauw Geel '38",
            'home_away'         => 'away',
            'home_score'        => $home,
            'away_score'        => $away,
        ] );
    }

    public function test_frame_reads_home_score_as_ours(): void {
        $this->assertSame(
            [ 'our_score' => 3, 'their_score' => 1, 'outcome' => 'W' ],
            MatchResultQuery::frame( '3', '1' )
        );
        $this->assertSame( 'L', MatchResultQuery::frame( 1, 3 )['outcome'] );
        $this->assertSame( 'D', MatchResultQuery::frame( 0, 0 )['outcome'] );
    }

    public function test_frame_leaves_a_half_recorded_score_empty(): void {
        $empty = [ 'our_score' => null, 'their_score' => null, 'outcome' => '' ];

        $this->assertSame( $empty, MatchResultQuery::frame( null, null ) );
        $this->assertSame( $empty, MatchResultQuery::frame( 2, null ) );
        $this->assertSame( $empty, MatchResultQuery::frame( '', '1' ) );
    }

    public function test_the_monthly_window_reads_an_away_win_as_a_win(): void {
        $this->seedAway( 40661, '2026-03-07', 3, 1 );

        $matches = ( new ActivitiesRepository() )->matchesInWindowForTeam( self::TEAM_ID, '2026-03-01', '2026-03-31' );

        $this->assertCount( 1, $matches );
        $this->assertSame( 3, $matches[0]['team_score'] );
        $this->assertSame( 1, $matches[0]['opp_score'] );
        $this->assertSame( 'W', $matches[0]['outcome'] );
        $this->assertSame( 'away', $matches[0]['home_away'] );
    }

    public function test_the_monthly_window_keeps_an_unscored_away_match_unscored(): void {
        $this->seedAway( 40662, '2026-03-14', null, null );

        $matches = ( new ActivitiesRepository() )->matchesInWindowForTeam( self::TEAM_ID, '2026-03-01', '2026-03-31' );

        $this->assertNull( $matches[0]['team_score'] );
        $this->assertNull( $matches[0]['opp_score'] );
        $this->assertSame( '', $matches[0]['outcome'] );
    }

    public function test_the_team_statistics_agree_with_the_monthly_window(): void {
        $this->seedAway( 40663, '2026-03-07', 3, 1 );
        $this->seedAway( 40664, '2026-03-21', 0, 2 );

        $stats   = ( new TeamMatchStatsQuery() )->forTeam( self::TEAM_ID, [ 'from' => '2026-03-01', 'to' => '2026-03-31' ] );
        $matches = ( new ActivitiesRepository() )->matchesInWindowForTeam( self::TEAM_ID, '2026-03-01', '2026-03-31' );

        $record = $stats['record'];
        $this->assertSame( 1, $record['won'] );
        $this->assertSame( 1, $record['lost'] );
        $this->assertSame( 3, $record['goals_for'] );
        $this->assertSame( 3, $record['goals_against'] );

        $goals_for = 0;
        foreach ( $matches as $m ) $goals_for += (int) $m['team_score'];
        $this->assertSame( $record['goals_for'], $goals_for, 'the fixture list and the record agree' );
    }

    public function test_the_grid_binds_our_goals_to_home_score_on_an_away_match(): void {
        $method = new ReflectionMethod( FrontendMinutesGridView::class, 'renderScoreCell' );
        $method->setAccessible( true );

        $activity = [
            'activity_id'  => 40665,
            'session_date' => '2026-03-07',
            'is_home'      => false,
            'home_score'   => 3,
            'away_score'   => 1,
        ];

        ob_start();
        $method->invoke( null, $activity, 'home_score' );
        $ours = (string) ob_get_clean();

        ob_start();
        $method->invoke( null, $activity, 'away_score' );
        $theirs = (string) ob_get_clean();

        $this->assertStringContainsString( 'data-field="home_score"', $ours );
        $this->assertStringContainsString( 'value="3"', $ours );
        $this->assertStringContainsString( 'data-field="away_score"', $theirs );
        $this->assertStringContainsString( 'value="1"', $theirs );
    }
}
