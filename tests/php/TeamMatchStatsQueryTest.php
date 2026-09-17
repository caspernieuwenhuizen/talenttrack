<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * #3520 (epic #3519) — a team's match output as one answer.
 *
 * The substance is the two scopes. A tournament is a multi-game day, so it
 * cannot appear in a record built of scorelines but its goals are still the
 * scorer's goals. Reusing one window for both is the bug these tests exist to
 * catch, so the tournament cases assert the two halves *disagree* on purpose.
 *
 * The rest is the arithmetic that is wrong in the obvious implementation: a
 * match nobody typed a result into is not a nil-nil, and next week's fixture
 * is not a missing result.
 */
final class TeamMatchStatsQueryTest extends WP_UnitTestCase {

    private const TEAM_ID       = 881;
    private const OTHER_TEAM_ID = 882;
    private const HALF_LENGTH   = 35;

    private const STRIKER   = 21;
    private const PLAYMAKER = 22;

    private const WINDOW = [ 'from' => '2026-01-01', 'to' => '2026-06-30' ];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->seedPlayer( self::STRIKER, 'Sam', 'Striker', 9 );
        $this->seedPlayer( self::PLAYMAKER, 'Pia', 'Playmaker', 10 );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // ---------------------------------------------------------------
    // Seeding
    // ---------------------------------------------------------------

    private function seedPlayer( int $id, string $first, string $last, ?int $jersey ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => 1,
            'id'            => $id,
            'team_id'       => self::TEAM_ID,
            'first_name'    => $first,
            'last_name'     => $last,
            'jersey_number' => $jersey,
            'status'        => 'active',
        ] );
    }

    /**
     * A fixture with, optionally, a recorded scoreline. `$home_score` left
     * null is the common real case: the match was played, nobody typed it in.
     */
    private function seedFixture(
        int $activity_id,
        string $date,
        ?int $home_score = null,
        ?int $away_score = null,
        string $home_away = 'home',
        string $type = 'game',
        int $team_id = self::TEAM_ID
    ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => $activity_id,
            'team_id'           => $team_id,
            'title'             => 'Fixture ' . $activity_id,
            'session_date'      => $date,
            'activity_type_key' => $type,
            'opponent'          => 'Opponent ' . $activity_id,
            'home_away'         => $home_away,
            'home_score'        => $home_score,
            'away_score'        => $away_score,
        ] );
    }

    /** A goal on an activity, through the execution log the contribution query reads. */
    private function goal( int $activity_id, int $scorer, ?int $assist = null ): void {
        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( $activity_id, self::HALF_LENGTH );

        $exec_repo = new MatchExecutionRepository();
        $exec_id   = $exec_repo->ensureForActivity( $activity_id, $prep_id );
        $exec_repo->update( $exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );

        $exec_repo->logGoalEvent( $exec_id, wp_generate_uuid4(), $scorer, 1, 10, 'home', $assist, false );
    }

    private function stats(): array {
        return ( new TeamMatchStatsQuery() )->forTeam( self::TEAM_ID, self::WINDOW );
    }

    // ---------------------------------------------------------------
    // The record
    // ---------------------------------------------------------------

    public function test_the_record_counts_wins_draws_and_losses_from_our_side(): void {
        $this->seedFixture( 8101, '2026-02-01', 3, 1 );            // home win
        $this->seedFixture( 8102, '2026-02-08', 2, 2 );            // home draw
        $this->seedFixture( 8103, '2026-02-15', 4, 0, 'away' );    // away loss, 0-4

        $record = $this->stats()['record'];

        $this->assertSame( 3, $record['played'] );
        $this->assertSame( 1, $record['won'] );
        $this->assertSame( 1, $record['drawn'] );
        $this->assertSame( 1, $record['lost'] );
        $this->assertSame( 5, $record['goals_for'], '3 + 2 + 0 from the academy side' );
        $this->assertSame( 7, $record['goals_against'], '1 + 2 + 4' );
        $this->assertSame( -2, $record['goal_difference'] );
    }

    public function test_an_away_row_is_read_from_the_academy_side(): void {
        // Stored home 1 – away 5 with the academy away: a 5-1 win for us.
        $this->seedFixture( 8110, '2026-03-01', 1, 5, 'away' );

        $form = $this->stats()['form'][0];

        $this->assertSame( 5, $form['team_score'] );
        $this->assertSame( 1, $form['opp_score'] );
        $this->assertSame( 'W', $form['outcome'] );
        $this->assertSame( 'away', $form['home_away'] );
    }

    public function test_a_match_with_no_score_is_not_a_nil_nil(): void {
        $this->seedFixture( 8120, '2026-02-01', 2, 0 );
        $this->seedFixture( 8121, '2026-02-08' ); // played, never typed in

        $record = $this->stats()['record'];

        $this->assertSame( 1, $record['played'], 'only the fixture with a result counts as played' );
        $this->assertSame( 1, $record['without_a_score'] );
        $this->assertSame( 0, $record['drawn'], 'a missing result is not a draw' );
        $this->assertSame( 1, $record['won'] );
    }

    public function test_a_fixture_still_to_come_is_not_a_missing_result(): void {
        $future = gmdate( 'Y-m-d', strtotime( '+30 days' ) ?: time() );
        $this->seedFixture( 8130, $future );

        $record = ( new TeamMatchStatsQuery() )->forTeam( self::TEAM_ID, [
            'from' => '2026-01-01',
            'to'   => '2099-12-31',
        ] )['record'];

        $this->assertSame( 0, $record['without_a_score'], 'next week\'s game is not a match missing its result' );
        $this->assertSame( 0, $record['played'] );
    }

    public function test_clean_sheets_count_scoreless_opponents(): void {
        $this->seedFixture( 8140, '2026-02-01', 3, 0 );
        $this->seedFixture( 8141, '2026-02-08', 0, 0 );
        $this->seedFixture( 8142, '2026-02-15', 1, 2 );

        $this->assertSame( 2, $this->stats()['record']['clean_sheets'] );
    }

    public function test_another_teams_fixtures_are_not_ours(): void {
        $this->seedFixture( 8150, '2026-02-01', 1, 0 );
        $this->seedFixture( 8151, '2026-02-02', 9, 0, 'home', 'game', self::OTHER_TEAM_ID );

        $this->assertSame( 1, $this->stats()['record']['played'] );
    }

    // ---------------------------------------------------------------
    // The two scopes — the substance
    // ---------------------------------------------------------------

    public function test_a_tournament_is_outside_the_record_but_inside_the_leaderboards(): void {
        $this->seedFixture( 8201, '2026-02-01', 2, 1 );                                  // league game
        $this->seedFixture( 8202, '2026-02-08', 1, 0, 'home', 'tournament' );            // multi-game day
        $this->goal( 8201, self::STRIKER );
        $this->goal( 8202, self::STRIKER );
        $this->goal( 8202, self::PLAYMAKER );

        $stats = $this->stats();

        $this->assertSame( 1, $stats['record']['played'], 'one scoreline cannot describe a tournament day' );
        $this->assertSame( 1, $stats['record']['tournaments_excluded'], 'and the caller is told, not left to wonder' );

        $goals = [];
        foreach ( $stats['scorers'] as $row ) {
            $goals[ $row['player_id'] ] = $row['goals'];
        }
        $this->assertSame( 2, $goals[ self::STRIKER ] ?? 0, 'a goal at a tournament is still their goal' );
        $this->assertSame( 1, $goals[ self::PLAYMAKER ] ?? 0 );
    }

    public function test_a_tournament_alone_leaves_the_record_empty_and_says_why(): void {
        $this->seedFixture( 8210, '2026-02-08', 3, 2, 'home', 'tournament' );

        $record = $this->stats()['record'];

        $this->assertSame( 0, $record['played'] );
        $this->assertSame( 0, $record['without_a_score'], 'a tournament is excluded, not missing a result' );
        $this->assertSame( 1, $record['tournaments_excluded'] );
    }

    // ---------------------------------------------------------------
    // Leaderboards — composed, never recomputed
    // ---------------------------------------------------------------

    public function test_scorers_and_assists_equal_the_contribution_query(): void {
        $this->seedFixture( 8301, '2026-02-01', 3, 0 );
        $this->goal( 8301, self::STRIKER, self::PLAYMAKER );
        $this->goal( 8301, self::STRIKER );
        $this->goal( 8301, self::PLAYMAKER, self::STRIKER );

        $stats        = $this->stats();
        $contributions = ( new GoalContributionQuery() )->forTeam( self::TEAM_ID, self::WINDOW );

        $goals = [];
        foreach ( $stats['scorers'] as $row ) $goals[ $row['player_id'] ] = $row['goals'];
        $assists = [];
        foreach ( $stats['assists'] as $row ) $assists[ $row['player_id'] ] = $row['assists'];

        foreach ( $contributions as $player_id => $row ) {
            if ( $row['goals'] > 0 ) {
                $this->assertSame( $row['goals'], $goals[ $player_id ] ?? 0, 'the tab and the minutes report must agree on goals' );
            }
            if ( $row['assists'] > 0 ) {
                $this->assertSame( $row['assists'], $assists[ $player_id ] ?? 0 );
            }
        }
    }

    public function test_the_leaderboards_list_contributors_only_and_rank_them(): void {
        $this->seedFixture( 8310, '2026-02-01', 3, 0 );
        $this->goal( 8310, self::PLAYMAKER );
        $this->goal( 8310, self::STRIKER );
        $this->goal( 8310, self::STRIKER );

        $scorers = $this->stats()['scorers'];

        $this->assertCount( 2, $scorers, 'a player who has not scored is absent, not a zero row' );
        $this->assertSame( self::STRIKER, $scorers[0]['player_id'], 'ranked by goals' );
        $this->assertSame( 2, $scorers[0]['goals'] );
        $this->assertSame( 'Sam Striker', $scorers[0]['name'] );
        $this->assertSame( 9, $scorers[0]['jersey_number'] );
    }

    // ---------------------------------------------------------------
    // The window
    // ---------------------------------------------------------------

    public function test_the_window_defaults_to_the_current_season(): void {
        $seasons = new SeasonsRepository();
        $id = $seasons->create( [ 'name' => '2025/26', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30' ] );
        $seasons->setCurrent( $id );

        $window = ( new TeamMatchStatsQuery() )->forTeam( self::TEAM_ID )['window'];

        $this->assertSame( 'season', $window['source'] );
        $this->assertSame( '2025-08-01', $window['from'] );
        $this->assertSame( '2026-06-30', $window['to'] );
        $this->assertSame( '2025/26', $window['season'] );
    }

    public function test_no_current_season_falls_back_to_all_time_and_says_so(): void {
        $window = ( new TeamMatchStatsQuery() )->forTeam( self::TEAM_ID )['window'];

        $this->assertSame( 'all_time', $window['source'], 'an empty screen the coach cannot explain is the wrong answer' );
    }

    public function test_an_explicit_window_is_reported_as_custom(): void {
        $window = ( new TeamMatchStatsQuery() )->forTeam( self::TEAM_ID, self::WINDOW )['window'];

        $this->assertSame( 'custom', $window['source'] );
        $this->assertSame( '2026-01-01', $window['from'] );
    }

    // ---------------------------------------------------------------
    // REST
    // ---------------------------------------------------------------

    public function test_rest_returns_the_same_numbers_as_the_query(): void {
        $this->seedFixture( 8401, '2026-02-01', 2, 1 );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . self::TEAM_ID . '/stats' );
        $req->set_param( 'from', self::WINDOW['from'] );
        $req->set_param( 'to', self::WINDOW['to'] );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data();
        $payload = $data['data'] ?? $data;

        $this->assertSame( 1, $payload['record']['played'] );
        $this->assertSame( 1, $payload['record']['won'] );
    }

    public function test_rest_refuses_a_half_window(): void {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . self::TEAM_ID . '/stats' );
        $req->set_param( 'from', '2026-01-01' );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 400, $res->get_status(), 'half a window would answer a different question than the caller asked' );
    }

    /**
     * #3152, restated: `tt_view_teams` is club-wide on `tt_coach`, so the cap
     * alone hands a head coach every squad in the academy. The route must ask
     * the per-record question too.
     */
    public function test_a_coach_cannot_read_a_team_they_do_not_coach(): void {
        $this->seedFixture( 8410, '2026-02-01', 2, 1 );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/teams/' . self::TEAM_ID . '/stats' );
        $res = rest_get_server()->dispatch( $req );

        $this->assertSame( 403, $res->get_status() );
    }
}
