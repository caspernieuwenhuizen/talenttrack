<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\GoalContributionDrilldown;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\MatchExecution\Domain\ContributionsCeiling;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #4994 — every match behind a player's goals and assists.
 *
 * The drill-down is only useful if its rows add up to the number a coach
 * tapped, so the sums are tested against the Statistics tab and the counting
 * readers on the same fixture. A fixture with more assists than goals is what
 * the flags are for: 70 assists for one player was the jg4it report.
 */
final class GoalContributionDrilldownTest extends WP_UnitTestCase {

    private const FROM = '2026-01-01';
    private const TO   = '2026-06-30';

    private string $p = '';
    private int $team = 0;
    private int $other_team = 0;
    private int $striker = 0;
    private int $playmaker = 0;
    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->p = $wpdb->prefix;

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O14-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O15-1' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->striker   = $this->player( 'Sem', 'Spits', $this->team );
        $this->playmaker = $this->player( 'Morris', 'Middenveld', $this->team );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // ---------------------------------------------------------------
    // Sums
    // ---------------------------------------------------------------

    public function test_the_drilldown_rows_add_up_to_the_tab_and_the_counting_readers(): void {
        $a = $this->match( '2026-02-01', 3, 1 );
        $this->goal( $a, $this->striker, $this->playmaker );
        $this->goal( $a, $this->striker );
        $this->goal( $a, $this->playmaker, $this->striker );
        $this->goal( $a, 0, null, 'away' );

        $b = $this->match( '2026-03-01', 1, 0 );
        $this->goal( $b, $this->playmaker, $this->striker );

        $window = [ 'from' => self::FROM, 'to' => self::TO ];
        $team   = ( new GoalContributionQuery() )->forTeam( $this->team, $window );
        $stats  = ( new TeamMatchStatsQuery() )->forTeam( $this->team, $window );

        foreach ( [ $this->striker, $this->playmaker ] as $pid ) {
            $drill = ( new GoalContributionQuery() )->eventsFor( $this->team, $pid, $window );

            $this->assertSame( $team[ $pid ]['goals'], $drill['goals'] );
            $this->assertSame( $team[ $pid ]['assists'], $drill['assists'] );
            $this->assertSame( $team[ $pid ]['goals'], array_sum( array_column( $drill['matches'], 'goals' ) ), 'the matches add up to the total' );
            $this->assertSame( $team[ $pid ]['assists'], array_sum( array_column( $drill['matches'], 'assists' ) ) );

            $this->assertSame( $team[ $pid ]['goals'], $this->leaderboardCount( $stats['scorers'], $pid, 'goals' ), 'equal to the Statistics tab' );
            $this->assertSame( $team[ $pid ]['assists'], $this->leaderboardCount( $stats['assists'], $pid, 'assists' ) );

            $player = ( new GoalContributionQuery() )->forPlayer( $pid, $window );
            $this->assertSame( $player['goals'], $drill['goals'], 'equal to forPlayer, which the Studio block reads' );
            $this->assertSame( $player['assists'], $drill['assists'] );
        }

        $this->assertSame( 2, ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, $window )['goals'] );
        $this->assertSame( 2, ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, $window )['assists'] );
    }

    public function test_the_rest_route_answers_the_same_totals_with_plain_urls(): void {
        $a = $this->match( '2026-02-01', 2, 0 );
        $this->goal( $a, $this->striker, $this->playmaker );
        $this->goal( $a, $this->striker );

        $res  = $this->get( $this->team, $this->striker, [ 'from' => self::FROM, 'to' => self::TO ] );
        $this->assertSame( 200, $res->get_status() );
        $data = (array) $res->get_data()['data'];

        $this->assertSame( 2, $data['goals'] );
        $this->assertCount( 1, $data['matches'] );
        $this->assertStringNotContainsString( 'tt_back', (string) $data['matches'][0]['match_url'] );
        $this->assertSame( 'Sem Spits', $data['player_name'] );
    }

    public function test_the_statement_count_does_not_grow_with_the_matches(): void {
        $exec = $this->match( '2026-02-01', 1, 0 );
        $this->goal( $exec, $this->striker );
        $one = $this->statementsFor( $this->striker );

        for ( $i = 2; $i <= 6; $i++ ) {
            $exec = $this->match( sprintf( '2026-02-%02d', $i ), 1, 0 );
            $this->goal( $exec, $this->striker );
        }
        $six = $this->statementsFor( $this->striker );

        $this->assertSame( $one, $six, 'never one statement per match' );
        $this->assertLessThanOrEqual( 2, $six, 'one events read, at most one more' );
    }

    private function statementsFor( int $player_id ): int {
        global $wpdb;
        $before = (int) $wpdb->num_queries;
        ( new GoalContributionQuery() )->eventsFor( $this->team, $player_id, [ 'from' => self::FROM, 'to' => self::TO ] );
        return (int) $wpdb->num_queries - $before;
    }

    // ---------------------------------------------------------------
    // Rows
    // ---------------------------------------------------------------

    public function test_a_row_carries_the_match_and_each_goal(): void {
        $a = $this->match( '2026-02-01', 2, 1, 'Oss', 'away' );
        $this->goal( $a, $this->striker, $this->playmaker );

        $m = ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, [ 'from' => self::FROM, 'to' => self::TO ] )['matches'][0];

        $this->assertSame( '2026-02-01', $m['date'] );
        $this->assertSame( 'Oss', $m['opponent'] );
        $this->assertSame( 'away', $m['home_away'] );
        $this->assertSame( 2, $m['team_score'] );
        $this->assertSame( 1, $m['opp_score'] );

        $e = $m['events'][0];
        $this->assertSame( 10, $e['minute'] );
        $this->assertSame( 'Sem Spits', $e['scorer'] );
        $this->assertSame( 'Morris Middenveld', $e['assister'] );
        $this->assertSame( 'live_sheet', $e['source'] );
        $this->assertFalse( $e['source_derived'] );
        $this->assertSame( $this->admin, $e['created_by'] );
    }

    public function test_a_manual_goal_has_no_minute_and_reads_as_entered_after_the_match(): void {
        $a = $this->match( '2026-02-01', 1, 0 );
        ( new MatchExecutionRepository() )->setContributions( $this->activityOf( $a ), $this->striker, 1, 0 );

        $e = ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, [ 'from' => self::FROM, 'to' => self::TO ] )['matches'][0]['events'][0];

        $this->assertNull( $e['minute'], 'no invented minute' );
        $this->assertSame( 'match_form', $e['source'] );
        $this->assertSame( $this->admin, $e['created_by'] );
    }

    public function test_an_old_row_derives_its_source_and_invents_no_author(): void {
        global $wpdb;
        $a = $this->match( '2026-02-01', 1, 0 );
        $this->goal( $a, $this->striker );
        // What a row written before #4994 looks like.
        $wpdb->query( "UPDATE {$this->p}tt_match_execution_goal_events SET source = NULL, created_by = NULL" );

        $e = ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, [ 'from' => self::FROM, 'to' => self::TO ] )['matches'][0]['events'][0];

        $this->assertSame( 'live_sheet', $e['source'], 'derived from execution_id' );
        $this->assertTrue( $e['source_derived'] );
        $this->assertSame( 0, $e['created_by'] );
        $this->assertSame( '', $e['created_by_name'] );
    }

    // ---------------------------------------------------------------
    // Flags
    // ---------------------------------------------------------------

    public function test_a_match_with_more_assists_than_our_score_is_flagged(): void {
        $a = $this->match( '2026-02-01', 1, 0 );
        $this->goal( $a, $this->playmaker, $this->striker );
        // A second assist, hung on an unattributed goal the score does not have.
        $this->goal( $a, 0, $this->striker );

        $m = ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, [ 'from' => self::FROM, 'to' => self::TO ] )['matches'][0];
        $this->assertContains( 'assists_exceed_score', $m['flags'] );
        $this->assertContains( 'player_over_score', $m['flags'] );
    }

    public function test_a_player_over_the_ceiling_without_a_score_is_flagged(): void {
        $a = $this->match( '2026-02-01', null, null );
        for ( $i = 0; $i < ContributionsCeiling::CAP_WITHOUT_SCORE + 1; $i++ ) {
            $this->goal( $a, 0, $this->striker );
        }

        $m = ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, [ 'from' => self::FROM, 'to' => self::TO ] )['matches'][0];
        $this->assertContains( 'player_over_cap', $m['flags'] );
        $this->assertNotContains( 'assists_exceed_score', $m['flags'], 'no score, no match total' );
    }

    public function test_an_assist_on_an_opponent_goal_or_own_goal_is_flagged_and_still_counted(): void {
        $a = $this->match( '2026-02-01', 1, 2 );
        $this->goal( $a, 0, $this->striker, 'away' );
        $this->goal( $a, $this->playmaker, $this->striker, 'away', true );

        $drill = ( new GoalContributionQuery() )->eventsFor( $this->team, $this->striker, [ 'from' => self::FROM, 'to' => self::TO ] );
        $m     = $drill['matches'][0];

        $this->assertContains( 'assist_on_other_goal', $m['flags'] );
        foreach ( $m['events'] as $e ) {
            $this->assertContains( 'assist_on_other_goal', $e['flags'] );
        }
        $this->assertSame(
            ( new GoalContributionQuery() )->forTeam( $this->team, [ 'from' => self::FROM, 'to' => self::TO ] )[ $this->striker ]['assists'],
            $drill['assists'],
            'flagged, not dropped: the totals stay what every surface shows'
        );
    }

    public function test_the_ceiling_is_one_rule(): void {
        $this->assertSame( 3, ContributionsCeiling::limit( 3 ) );
        $this->assertSame( ContributionsCeiling::CAP_WITHOUT_SCORE, ContributionsCeiling::limit( null ) );
        $this->assertSame( ContributionsCeiling::CAP_WITHOUT_SCORE, MatchExecutionRepository::CONTRIBUTIONS_CAP_WITHOUT_SCORE );
        $this->assertTrue( ContributionsCeiling::playerOver( 2, 3 ) );
        $this->assertFalse( ContributionsCeiling::playerOver( 3, 3 ) );
        $this->assertFalse( ContributionsCeiling::matchOver( null, 40 ) );
        $this->assertTrue( ContributionsCeiling::matchOver( 1, 2 ) );
    }

    // ---------------------------------------------------------------
    // Provenance on every writer
    // ---------------------------------------------------------------

    public function test_every_writer_stamps_its_provenance(): void {
        $repo = new MatchExecutionRepository();
        $a    = $this->match( '2026-02-01', 3, 0 );
        $aid  = $this->activityOf( $a );

        $uuid = $this->goal( $a, $this->striker );
        $row  = $this->row( $uuid );
        $this->assertSame( 'live_sheet', $row['source'] );
        $this->assertSame( (string) $this->admin, (string) $row['created_by'] );
        $this->assertNull( $row['updated_at'] );

        $repo->updateGoalAttribution( $uuid, $this->striker, $this->playmaker, false );
        $row = $this->row( $uuid );
        $this->assertNotNull( $row['updated_at'], 'updateGoalAttribution' );
        $this->assertSame( (string) $this->admin, (string) $row['updated_by'] );

        global $wpdb;
        $wpdb->query( "UPDATE {$this->p}tt_match_execution_goal_events SET updated_at = NULL, updated_by = NULL" );
        $repo->updateGoalEventMinute( $uuid, 2, 30 );
        $this->assertNotNull( $this->row( $uuid )['updated_at'], 'updateGoalEventMinute' );

        // insertManualGoal, through setContributions.
        $repo->setContributions( $aid, $this->playmaker, 1, 0 );
        $manual = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_match_execution_goal_events WHERE activity_id = %d AND execution_id IS NULL AND player_id = %d",
            $aid, $this->playmaker
        ), ARRAY_A );
        $this->assertSame( 'match_form', $manual['source'] );
        $this->assertSame( (string) $this->admin, (string) $manual['created_by'] );

        // attachAssist hangs an assist on the manual goal; reconcileAssists clears it.
        $wpdb->query( "UPDATE {$this->p}tt_match_execution_goal_events SET updated_at = NULL, updated_by = NULL" );
        $repo->setContributions( $aid, $this->striker, 1, 1 );
        $assisted = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_match_execution_goal_events WHERE activity_id = %d AND assist_player_id = %d AND reversed_at IS NULL",
            $aid, $this->striker
        ), ARRAY_A );
        $this->assertNotNull( $assisted['updated_at'], 'attachAssist' );

        $wpdb->query( "UPDATE {$this->p}tt_match_execution_goal_events SET updated_at = NULL, updated_by = NULL" );
        $repo->setContributions( $aid, $this->striker, 1, 0 );
        $cleared = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_match_execution_goal_events WHERE event_uuid = %s",
            (string) $assisted['event_uuid']
        ), ARRAY_A );
        $this->assertNotNull( $cleared['updated_at'], 'reconcileAssists' );
        $this->assertSame( (string) $this->admin, (string) $cleared['updated_by'] );
    }

    public function test_a_write_with_nobody_signed_in_stores_no_author(): void {
        $a = $this->match( '2026-02-01', 1, 0 );
        wp_set_current_user( 0 );
        $uuid = $this->goal( $a, $this->striker );
        $this->assertNull( $this->row( $uuid )['created_by'] );
    }

    public function test_the_demo_generator_marks_its_goals(): void {
        $a   = $this->match( '2026-02-01', 1, 0 );
        $aid = $this->activityOf( $a );
        $uuid = $this->goal( $a, $this->striker );

        ( new MatchExecutionRepository() )->markGoalSourceForActivity( $aid, MatchExecutionRepository::SOURCE_DEMO );
        $this->assertSame( 'demo', $this->row( $uuid )['source'] );
    }

    // ---------------------------------------------------------------
    // Access and filters
    // ---------------------------------------------------------------

    public function test_an_out_of_scope_team_is_forbidden_team(): void {
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        wp_set_current_user( $coach );

        $res = $this->get( $this->other_team, $this->striker );
        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( 'forbidden_team', $this->errorCode( $res ) );
    }

    public function test_a_family_is_refused(): void {
        global $wpdb;
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [ 'club_id' => 1, 'player_id' => $this->striker, 'parent_user_id' => $parent, 'is_primary' => 1 ] );
        wp_set_current_user( $parent );

        foreach ( [ $this->striker, $this->playmaker ] as $pid ) {
            $this->assertSame( 403, $this->get( $this->team, $pid )->get_status(), 'a family never opens a team drill-down' );
        }
    }

    public function test_a_scout_does_not_see_other_players(): void {
        $scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $scout );

        $this->assertSame( 403, $this->get( $this->team, $this->playmaker )->get_status() );
    }

    public function test_the_refusal_names_the_player_after_the_team(): void {
        $this->assertNull( GoalContributionDrilldown::refusal( $this->admin, $this->team, $this->striker ) );
    }

    public function test_an_unknown_season_is_bad_filter_and_a_bad_date_is_bad_date(): void {
        $season = $this->get( $this->team, $this->striker, [ 'season' => '987654' ] );
        $this->assertSame( 400, $season->get_status() );
        $this->assertSame( 'bad_filter', $this->errorCode( $season ) );

        $date = $this->get( $this->team, $this->striker, [ 'from' => '01-02-2026', 'to' => self::TO ] );
        $this->assertSame( 400, $date->get_status() );
        $this->assertSame( 'bad_date', $this->errorCode( $date ) );

        $backwards = $this->get( $this->team, $this->striker, [ 'from' => self::TO, 'to' => self::FROM ] );
        $this->assertSame( 'bad_date', $this->errorCode( $backwards ) );
    }

    public function test_the_report_page_says_so_when_a_season_has_nothing(): void {
        $_GET = [ 'tt_view' => 'goals-assists', 'team_id' => (string) $this->team, 'from' => self::FROM, 'to' => self::TO ];
        ob_start();
        \TT\Modules\Analytics\Frontend\FrontendGoalsAssistsReportView::render( $this->admin, true );
        $html = (string) ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString( 'No goals or assists recorded in this season.', $html );
    }

    public function test_the_report_page_lists_the_counts_and_the_drilldown(): void {
        $a = $this->match( '2026-02-01', 1, 0 );
        $this->goal( $a, $this->striker, $this->playmaker );

        $_GET = [ 'tt_view' => 'goals-assists', 'team_id' => (string) $this->team, 'from' => self::FROM, 'to' => self::TO ];
        ob_start();
        \TT\Modules\Analytics\Frontend\FrontendGoalsAssistsReportView::render( $this->admin, true );
        $table = (string) ob_get_clean();

        $_GET['player_id'] = (string) $this->striker;
        ob_start();
        \TT\Modules\Analytics\Frontend\FrontendGoalsAssistsReportView::render( $this->admin, true );
        $drill = (string) ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString( 'Sem Spits', $table );
        $this->assertStringContainsString( 'player_id=' . $this->striker, $table );
        $this->assertStringContainsString( 'tt-ga-match', $drill );
        $this->assertStringContainsString( 'Morris Middenveld', $drill );
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function player( string $first, string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => 1, 'first_name' => $first, 'last_name' => $last, 'team_id' => $team_id, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A match activity with an execution; returns the execution id. */
    private function match( string $date, ?int $ours, ?int $theirs, string $opponent = 'VV Opponent', string $venue = 'home' ): int {
        global $wpdb;
        $row = [
            'club_id'           => 1,
            'team_id'           => $this->team,
            'title'             => 'Match ' . $date,
            'session_date'      => $date,
            'activity_type_key' => 'game',
            'opponent'          => $opponent,
            'home_away'         => $venue,
        ];
        if ( $ours !== null )   $row['home_score'] = $ours;
        if ( $theirs !== null ) $row['away_score'] = $theirs;
        $wpdb->insert( "{$this->p}tt_activities", $row );
        $activity_id = (int) $wpdb->insert_id;

        $prep_id = ( new MatchPrepRepository() )->ensureForActivity( $activity_id, 35 );
        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( $activity_id, $prep_id );
        $repo->update( $exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
        return $exec_id;
    }

    private function activityOf( int $exec_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT activity_id FROM {$this->p}tt_match_execution WHERE id = %d", $exec_id ) );
    }

    private function goal( int $exec_id, int $scorer, ?int $assist = null, string $team = 'home', bool $own = false ): string {
        $uuid = wp_generate_uuid4();
        ( new MatchExecutionRepository() )->logGoalEvent( $exec_id, $uuid, $scorer, 1, 10, $team, $assist, $own );
        return $uuid;
    }

    /** @return array<string, mixed> */
    private function row( string $uuid ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_match_execution_goal_events WHERE event_uuid = %s",
            $uuid
        ), ARRAY_A );
    }

    /** @param array<string, string> $query */
    private function get( int $team_id, int $player_id, array $query = [] ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', "/talenttrack/v1/teams/{$team_id}/players/{$player_id}/goal-contributions" );
        foreach ( $query as $k => $v ) $req->set_param( $k, $v );
        return rest_get_server()->dispatch( $req );
    }

    private function errorCode( \WP_REST_Response $res ): string {
        $data = (array) $res->get_data();
        return (string) ( $data['errors'][0]['code'] ?? $data['code'] ?? '' );
    }

    /** @param array<int, array<string, mixed>> $board */
    private function leaderboardCount( array $board, int $pid, string $key ): int {
        foreach ( $board as $row ) {
            if ( (int) $row['player_id'] === $pid ) return (int) $row[ $key ];
        }
        return 0;
    }
}
