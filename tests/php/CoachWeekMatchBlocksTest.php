<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\CoachWeek\Blocks\MinutesStatsAction;
use TT\Modules\Activities\CoachWeek\Blocks\RatePlayersAction;
use TT\Modules\Activities\CoachWeek\Blocks\WriteAnalysisAction;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;

/**
 * #4494 — the match blocks of the "This week" panel: game plan, result,
 * minutes check, ratings and analysis, and the three buttons that follow
 * from them. Uses the week of Monday 2 to Sunday 8 November 2026, with the
 * owner's example match on Saturday the 7th.
 */
final class CoachWeekMatchBlocksTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $coach  = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        ActivityRegisterProgress::forget();
        WeekBlockRegistry::reset();
        foreach ( [ CoachWeek::CONFIG_KEY, CoachWeek::SETTINGS_KEY, \TT\Shared\Dates\TTDate::WEEK_START_KEY ] as $key ) {
            $wpdb->delete( $wpdb->prefix . 'tt_config', [ 'club_id' => 1, 'config_key' => $key ] );
        }
        $prop = new \ReflectionProperty( \TT\Shared\Dates\TTDate::class, 'week_monday_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'O14-1', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => $this->team, 'first_name' => 'Week', 'last_name' => 'Speler', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coach );
        wp_set_current_user( $this->coach );

        // Every block on for every persona, so the test does not depend on
        // which coaching persona the fixture resolves to.
        $settings = CoachWeek::settings();
        foreach ( $settings as $persona => $s ) {
            $settings[ $persona ]['on']      = true;
            $settings[ $persona ]['teams']   = 'all';
            $settings[ $persona ]['desktop'] = WeekBlockRegistry::keys();
        }
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, CoachWeek::normaliseSettingsStored( (string) wp_json_encode( $settings ) ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_owners_match_shows_won_the_minutes_over_and_the_minutes_button(): void {
        $match     = $this->completedMatch( '2026-11-07', 2, 1, 'away' );
        $available = $this->availableMinutes( $match );
        $this->assertGreaterThan( 0, $available, 'precondition: the audit knows what this match holds' );

        $this->minutes( $match, $available + 25 );
        $row = $this->row( $match, '2026-11-07' );

        $this->assertSame( [ 'our_score' => 2, 'their_score' => 1, 'outcome' => 'W' ], $row['result'], 'ours first, whatever the venue' );
        $this->assertSame( 'partial', $row['minutes_check']['status'] );
        $this->assertSame( 'minutes_over', $row['minutes_check']['status_reason'] );
        $this->assertSame( $available + 25, $row['minutes_check']['total_minutes'] );
        $this->assertStringContainsString( 'Won 2–1', $this->panelHtml( '2026-11-07' ) );
        if ( ( new MinutesStatsAction() )->available() ) {
            $this->assertContains( CoachWeek::ACTION_MINUTES_STATS, $row['actions'] );
        }

        $this->minutes( $match, $available );
        $row = $this->row( $match, '2026-11-07' );
        $this->assertSame( 'complete', $row['minutes_check']['status'], 'fixing the minutes turns the check complete' );
        $this->assertNotContains( CoachWeek::ACTION_MINUTES_STATS, $row['actions'], 'and drops the button' );
    }

    public function test_the_block_the_grid_and_the_audit_give_the_same_minutes_status(): void {
        $match = $this->completedMatch( '2026-11-07', 1, 1, 'home' );
        $this->minutes( $match, 30 );

        $block = $this->row( $match, '2026-11-07' )['minutes_check'];

        $audit = null;
        foreach ( ( new MinutesAuditQuery() )->matrix( $this->team, '2026-11-02', '2026-11-08' )['games'] as $game ) {
            if ( (int) $game['activity_id'] === $match ) $audit = $game;
        }
        $grid = null;
        foreach ( ( new MinutesGridQuery() )->matrix( $this->team, '2026-11-02', '2026-11-08' )['activities'] as $activity ) {
            if ( (int) $activity['activity_id'] === $match ) $grid = $activity['minutes_check'];
        }

        $this->assertNotNull( $audit );
        $this->assertNotNull( $grid );
        $this->assertSame( $audit['status'], $block['status'] );
        $this->assertSame( $grid['status'], $block['status'] );
        $this->assertSame( $audit['status_reason'], $block['status_reason'] );
        $this->assertSame( $grid['status_reason'], $block['status_reason'] );
    }

    public function test_no_score_stored_shows_no_result(): void {
        $match = $this->completedMatch( '2026-11-07', null, null, 'home' );
        $this->assertNull( $this->row( $match, '2026-11-07' )['result'], 'empty is not 0–0' );
    }

    public function test_ratings_and_analysis_on_a_completed_match(): void {
        $match = $this->completedMatch( '2026-11-07', 0, 3, 'home' );
        $this->minutes( $match, 70 );
        $row = $this->row( $match, '2026-11-07' );

        $this->assertSame( [ 'rated' => 0, 'played' => 1 ], $row['ratings'] );
        $this->assertFalse( $row['analysis_written'] );
        $this->assertSame( 'L', $row['result']['outcome'] );
        if ( ( new RatePlayersAction() )->available() ) {
            $this->assertContains( CoachWeek::ACTION_RATE_PLAYERS, $row['actions'] );
        }
        if ( ( new WriteAnalysisAction() )->available() ) {
            $this->assertContains( CoachWeek::ACTION_MATCH_ANALYSIS, $row['actions'] );
        }
        $this->assertLessThanOrEqual( 7, count( $row['actions'] ), 'actions are the candidates; the view shows two per device' );
    }

    public function test_a_user_without_the_edit_right_never_gets_rate_players(): void {
        $match = $this->completedMatch( '2026-11-07', 0, 3, 'home' );
        $this->minutes( $match, 70 );

        $observer = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        \TT\Infrastructure\Security\AuthorizationService::flushCache();
        $this->assertTrue( \TT\Modules\Activities\ActivityAccess::hasGlobalRead( $observer ), 'precondition: the observer reads every team' );

        $rows = [];
        foreach ( CoachWeek::forUser( $observer, '2026-11-07' )['rows'] as $r ) $rows[ (int) $r['activity_id'] ] = $r;
        $this->assertArrayHasKey( $match, $rows );
        $this->assertNotContains( CoachWeek::ACTION_RATE_PLAYERS, $rows[ $match ]['actions'] );
        $this->assertNotContains( CoachWeek::ACTION_MINUTES_STATS, $rows[ $match ]['actions'] );
        $this->assertNotContains( CoachWeek::ACTION_MATCH_ANALYSIS, $rows[ $match ]['actions'] );
    }

    public function test_the_game_plan_shows_before_the_match(): void {
        global $wpdb;
        $upcoming = $this->match( '2026-11-07', 'planned' );
        $repo     = new \TT\Modules\MatchPrep\Repositories\MatchPrepRepository();
        $prep     = $repo->ensureForActivity( $upcoming, 30 );

        $this->assertFalse( $this->row( $upcoming, '2026-11-04' )['game_plan'], 'a prep with nothing written' );

        $wpdb->update( $wpdb->prefix . 'tt_match_prep', [ 'goals_general' => 'Press high' ], [ 'id' => $prep ] );
        $this->assertTrue( $this->row( $upcoming, '2026-11-04' )['game_plan'] );
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_completed_matches(): void {
        // Week of 9 November: six completed matches. Week of 16 November: one.
        foreach ( [ '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14' ] as $date ) {
            $this->minutes( $this->completedMatch( $date, 1, 0, 'home' ), 60 );
        }
        $this->minutes( $this->completedMatch( '2026-11-16', 1, 0, 'home' ), 60 );

        $this->queriesFor( '2026-11-15' );
        $this->queriesFor( '2026-11-22' );
        $six = $this->queriesFor( '2026-11-15' );
        $one = $this->queriesFor( '2026-11-22' );

        $this->assertSame( 6, $six['checked'] );
        $this->assertSame( 1, $one['checked'] );
        $this->assertSame( count( $one['sql'] ), count( $six['sql'] ), "one query per source, whatever the number of matches. With six:\n" . implode( "\n", $six['sql'] ) );
    }

    public function test_the_defaults_follow_the_issue(): void {
        $d = CoachWeek::defaultSettings();
        foreach ( [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis', 'action_minutes_stats', 'action_rate_players', 'action_match_analysis' ] as $key ) {
            if ( WeekBlockRegistry::get( $key ) === null ) continue;
            $this->assertContains( $key, $d['head_coach']['desktop'], $key );
        }
        $this->assertSame( [ 'score', 'minutes', 'action_minutes_stats' ], array_values( array_intersect( $d['head_coach']['phone'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis', 'action_minutes_stats', 'action_rate_players', 'action_match_analysis' ] ) ) );
        $this->assertSame( [ 'ratings' ], array_values( array_intersect( $d['assistant_coach']['desktop'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
        $this->assertSame( [ 'score', 'minutes', 'ratings', 'analysis' ], array_values( array_intersect( $d['head_of_development']['desktop'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
        $this->assertSame( [ 'score' ], array_values( array_intersect( $d['head_of_development']['phone'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
        $this->assertSame( [ 'score', 'minutes' ], array_values( array_intersect( $d['academy_admin']['desktop'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
    }

    // Helpers

    /** @return array<string, mixed> */
    private function row( int $activity_id, string $today ): array {
        ActivityRegisterProgress::forget();
        foreach ( CoachWeek::forUser( $this->coach, $today )['rows'] as $r ) {
            if ( (int) $r['activity_id'] === $activity_id ) return $r;
        }
        $this->fail( 'the match is in the week' );
    }

    private function panelHtml( string $today ): string {
        $method = new \ReflectionMethod( \TT\Shared\Frontend\FrontendTileGrid::class, 'renderWeekPanel' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, CoachWeek::forUser( $this->coach, $today ) );
        return (string) ob_get_clean();
    }

    /** @return array{sql:list<string>, checked:int} */
    private function queriesFor( string $today ): array {
        ActivityRegisterProgress::forget();
        $sql     = [];
        $capture = static function ( $query ) use ( &$sql ) {
            $sql[] = preg_replace( '/\s+/', ' ', (string) $query );
            return $query;
        };
        add_filter( 'query', $capture );
        $week = CoachWeek::forUser( $this->coach, $today );
        remove_filter( 'query', $capture );
        $checked = count( array_filter( $week['rows'], static fn( $r ) => $r['minutes_check'] !== null && $r['result'] !== null ) );
        return [ 'sql' => $sql, 'checked' => $checked ];
    }

    private function availableMinutes( int $activity_id ): int {
        foreach ( ( new MinutesAuditQuery() )->matrix( $this->team, '2026-11-02', '2026-11-08' )['games'] as $game ) {
            if ( (int) $game['activity_id'] === $activity_id ) return (int) $game['available_minutes'];
        }
        return 0;
    }

    private function match( string $date, string $status ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'team_id' => $this->team, 'title' => 'Match', 'session_date' => $date,
            'start_time' => '10:15:00', 'activity_type_key' => 'game', 'opponent' => 'Baronie O14-1',
            'home_away' => 'away', 'activity_status_key' => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function completedMatch( string $date, ?int $ours, ?int $theirs, string $venue ): int {
        global $wpdb;
        $id = $this->match( $date, 'completed' );
        $wpdb->update( $wpdb->prefix . 'tt_activities', [ 'home_away' => $venue, 'home_score' => $ours, 'away_score' => $theirs ], [ 'id' => $id ] );
        return $id;
    }

    private function minutes( int $activity_id, int $minutes ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_attendance', [ 'activity_id' => $activity_id ] );
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id' => 1, 'activity_id' => $activity_id, 'player_id' => $this->player,
            'status' => 'Present', 'record_type' => 'actual', 'minutes_played' => $minutes,
        ] );
        ActivityRegisterProgress::forget();
    }

    /** A staff record and an active team-scope grant, what get_teams_for_coach() reads. */
    private function grantTeam( int $user_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id' => 1, 'first_name' => 'Week', 'last_name' => 'Coach', 'role_type' => 'head_coach', 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id' => (int) $wpdb->insert_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
    }
}
