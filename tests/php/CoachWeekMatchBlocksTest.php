<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;

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
        \TT\Modules\Activities\Services\ActivityGridLink::forget();
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

        // The three buttons are Pro features: pin the tier so the tests do
        // not depend on how the install happens to answer.
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );
    }

    public function tear_down(): void {
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
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
        $this->assertContains( CoachWeek::ACTION_MINUTES_STATS, $row['actions'] );

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
        $this->assertContains( CoachWeek::ACTION_RATE_PLAYERS, $row['actions'] );
        $this->assertContains( CoachWeek::ACTION_MATCH_ANALYSIS, $row['actions'] );
        $this->assertLessThanOrEqual( 7, count( $row['actions'] ), 'actions are the candidates; the view shows two per device' );
    }

    public function test_a_rated_absent_player_does_not_hide_rate_players(): void {
        global $wpdb;
        $match = $this->completedMatch( '2026-11-07', 0, 3, 'home' );
        $this->minutes( $match, 70 ); // the fixture player played, unrated
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'team_id' => $this->team, 'first_name' => 'Week', 'last_name' => 'Afwezig', 'status' => 'active',
        ] );
        $absent = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id' => 1, 'activity_id' => $match, 'player_id' => $absent, 'status' => 'Absent', 'record_type' => 'actual',
        ] );
        $this->rate( $match, $absent );

        $row = $this->row( $match, '2026-11-07' );
        $this->assertSame( [ 'rated' => 0, 'played' => 1 ], $row['ratings'], 'a rating on a player who did not play does not count' );
        $this->assertContains( CoachWeek::ACTION_RATE_PLAYERS, $row['actions'], 'the player who played is still unrated' );

        $this->rate( $match, $this->player );
        $row = $this->row( $match, '2026-11-07' );
        $this->assertSame( [ 'rated' => 1, 'played' => 1 ], $row['ratings'] );
        $this->assertNotContains( CoachWeek::ACTION_RATE_PLAYERS, $row['actions'] );
    }

    public function test_on_standard_the_three_buttons_and_their_switches_are_not_offered(): void {
        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );
        $match = $this->completedMatch( '2026-11-07', 0, 3, 'home' );
        $this->minutes( $match, 999 );
        $row = $this->row( $match, '2026-11-07' );

        foreach ( [ CoachWeek::ACTION_MINUTES_STATS, CoachWeek::ACTION_RATE_PLAYERS, CoachWeek::ACTION_MATCH_ANALYSIS ] as $action ) {
            $this->assertNotContains( $action, $row['actions'], $action );
        }
        foreach ( [ 'action_minutes_stats', 'action_rate_players', 'action_match_analysis' ] as $key ) {
            $this->assertArrayNotHasKey( $key, WeekBlockRegistry::available(), "{$key} has no switch on Standard" );
        }
    }

    public function test_a_completed_match_dated_after_today_offers_no_analysis(): void {
        $match = $this->completedMatch( '2026-11-07', 1, 0, 'home' );
        $this->minutes( $match, 70 );
        $this->assertNotContains( CoachWeek::ACTION_MATCH_ANALYSIS, $this->row( $match, '2026-11-04' )['actions'], 'the composer says it has not been played' );
        $this->assertContains( CoachWeek::ACTION_MATCH_ANALYSIS, $this->row( $match, '2026-11-07' )['actions'], 'on the day it is offered' );
    }

    public function test_an_assistant_coach_without_evaluations_read_sees_no_ratings(): void {
        global $wpdb;
        $match = $this->completedMatch( '2026-11-07', 0, 3, 'home' );
        $this->minutes( $match, 70 );

        $assistant = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $person    = $this->grantTeam( $assistant );
        $wpdb->insert( $wpdb->prefix . 'tt_team_people', [
            'club_id' => 1, 'team_id' => $this->team, 'person_id' => $person, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
        ] );
        \TT\Infrastructure\Security\AuthorizationService::flushCache();
        $this->assertSame( 'assistant_coach', \TT\Modules\Authorization\PersonaResolver::landingPersona( $assistant ), 'precondition' );

        $row = null;
        foreach ( CoachWeek::forUser( $assistant, '2026-11-07' )['rows'] as $r ) {
            if ( (int) $r['activity_id'] === $match ) $row = $r;
        }
        $this->assertNotNull( $row );
        $this->assertNull( $row['ratings'], 'switched on in the settings, but the matrix gives no evaluations read' );
        $this->assertNotContains( CoachWeek::ACTION_RATE_PLAYERS, $row['actions'] );
        $this->assertNotContains( 'ratings', CoachWeek::defaultSettings()['assistant_coach']['desktop'] );
    }

    public function test_a_user_without_the_edit_right_never_gets_rate_players(): void {
        $match = $this->completedMatch( '2026-11-07', 0, 3, 'home' );
        $this->minutes( $match, 70 );

        // Positive control: on this match, on this tier, the editor gets all
        // three, so the refusals below cannot pass for want of a button.
        $coach_row = $this->row( $match, '2026-11-07' );
        foreach ( [ CoachWeek::ACTION_MINUTES_STATS, CoachWeek::ACTION_RATE_PLAYERS, CoachWeek::ACTION_MATCH_ANALYSIS ] as $action ) {
            $this->assertContains( $action, $coach_row['actions'], "the coach gets {$action}" );
        }

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
        // The assistant coach has no evaluations read (#1060), so no ratings.
        $this->assertSame( [], array_values( array_intersect( $d['assistant_coach']['desktop'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
        // #4553 — the audit reads all teams at once, so the two all-teams
        // personas get the minutes check by default again (#4494's table).
        $this->assertSame( [ 'score', 'minutes', 'ratings', 'analysis' ], array_values( array_intersect( $d['head_of_development']['desktop'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
        $this->assertSame( [ 'score' ], array_values( array_intersect( $d['head_of_development']['phone'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
        $this->assertSame( [ 'score', 'minutes' ], array_values( array_intersect( $d['academy_admin']['desktop'], [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis' ] ) ) );
    }

    public function test_the_fallback_follows_each_blocks_table_for_the_assistant_coach(): void {
        global $wpdb;
        // Settings never saved: the older list's default (head and assistant
        // coach) switches the panel on, and every match block keeps its table.
        $wpdb->delete( $wpdb->prefix . 'tt_config', [ 'club_id' => 1, 'config_key' => CoachWeek::SETTINGS_KEY ] );
        $settings = CoachWeek::settings();
        $mine     = [ 'prep_notes', 'score', 'minutes', 'ratings', 'analysis', 'action_minutes_stats', 'action_rate_players', 'action_match_analysis' ];

        $this->assertTrue( $settings['assistant_coach']['on'] );
        $this->assertSame( [], array_values( array_intersect( $settings['assistant_coach']['desktop'], $mine ) ), 'none of the match blocks reach the assistant before an academy chooses' );
        $this->assertSame( [], array_values( array_intersect( $settings['assistant_coach']['phone'], $mine ) ) );
        $this->assertSame( $mine, array_values( array_intersect( $settings['head_coach']['desktop'], $mine ) ), 'the head coach keeps them all' );
        foreach ( $mine as $key ) {
            $this->assertTrue( WeekBlockRegistry::get( $key )->optInFor( 'assistant_coach' ), "{$key} is opt-in for the assistant" );
        }
    }

    public function test_the_all_teams_personas_get_the_minutes_check_again(): void {
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, '' );
        QueryHelpers::set_config( CoachWeek::CONFIG_KEY, '["head_coach","head_of_development","academy_admin"]' );
        $settings = CoachWeek::settings();
        foreach ( [ 'head_of_development', 'academy_admin' ] as $persona ) {
            $this->assertContains( 'minutes', $settings[ $persona ]['desktop'], "{$persona}: on by default, the fallback included" );
            $this->assertNotContains( 'action_minutes_stats', $settings[ $persona ]['desktop'], "{$persona}: the button stays the head coach's" );
        }
        $this->assertContains( 'minutes', $settings['head_coach']['desktop'] );
    }

    public function test_the_minutes_check_query_count_does_not_grow_with_the_number_of_teams(): void {
        global $wpdb;
        // One completed match each for six teams this week, against one team.
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $teams = [];
        for ( $i = 0; $i < 6; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Multi ' . $i, 'age_group' => 'U14' ] );
            $teams[] = (int) $wpdb->insert_id;
        }
        $original = $this->team;
        foreach ( $teams as $team ) {
            $this->team = $team;
            $this->minutes( $this->completedMatch( '2026-11-14', 1, 0, 'home' ), 60 );
        }
        $this->team = $teams[0];
        $this->minutes( $this->completedMatch( '2026-11-21', 1, 0, 'home' ), 60 );
        $this->team = $original;

        // Only the minutes check on, so this measures the audit read alone.
        $settings = CoachWeek::settings();
        foreach ( $settings as $persona => $s ) {
            $settings[ $persona ] = array_merge( $s, [ 'on' => true, 'teams' => 'all', 'desktop' => [ 'minutes' ], 'phone' => [] ] );
        }
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, CoachWeek::normaliseSettingsStored( (string) wp_json_encode( $settings ) ) );

        $count = function ( string $today ) use ( $admin ): array {
            ActivityRegisterProgress::forget();
            $sql     = [];
            $capture = static function ( $query ) use ( &$sql ) {
                $sql[] = preg_replace( '/\s+/', ' ', (string) $query );
                return $query;
            };
            add_filter( 'query', $capture );
            $week = CoachWeek::forUser( $admin, $today );
            remove_filter( 'query', $capture );
            return [ $sql, count( array_filter( $week['rows'], static fn( $r ) => $r['minutes_check'] !== null ) ) ];
        };

        $count( '2026-11-15' );
        $count( '2026-11-22' );
        [ $six_sql, $six ] = $count( '2026-11-15' );
        [ $one_sql, $one ] = $count( '2026-11-22' );

        $this->assertGreaterThanOrEqual( 6, $six, 'the admin sees the six teams\' matches with a minutes check' );
        $this->assertSame( 1, $one );
        $this->assertSame( count( $one_sql ), count( $six_sql ), "one audit read for every team. With six:\n" . implode( "\n", $six_sql ) );
    }

    public function test_matrix_for_teams_answers_what_matrix_answers_for_each_team(): void {
        global $wpdb;
        $teams = [ $this->team ];
        for ( $i = 0; $i < 2; $i++ ) {
            $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Pair ' . $i, 'age_group' => $i === 0 ? 'U12' : 'U16' ] );
            $teams[] = (int) $wpdb->insert_id;
        }
        $original = $this->team;
        foreach ( $teams as $n => $team ) {
            $this->team = $team;
            $this->minutes( $this->completedMatch( '2026-11-04', 2, $n, 'home' ), 50 + 10 * $n );
            $this->completedMatch( '2026-11-06', null, null, 'away' ); // nothing recorded
        }
        $this->team = $original;

        $audit = new MinutesAuditQuery();
        $all   = $audit->matrixForTeams( $teams, '2026-11-02', '2026-11-08' );
        $this->assertSame( $teams, array_keys( $all ) );
        foreach ( $teams as $team ) {
            $this->assertSame( $audit->matrix( $team, '2026-11-02', '2026-11-08' ), $all[ $team ], "team {$team}" );
        }
        $this->assertSame( [], $audit->matrixForTeams( [], '2026-11-02', '2026-11-08' ) );
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
    /** @return int the person id */
    private function grantTeam( int $user_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id' => 1, 'first_name' => 'Week', 'last_name' => 'Coach', 'role_type' => 'head_coach', 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        return $person;
    }

    /** One rating for this player on this match, the rows the ratings grid writes. */
    private function rate( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id' => 1, 'player_id' => $player_id, 'coach_id' => $this->coach, 'eval_date' => '2026-11-07', 'activity_id' => $activity_id,
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_eval_ratings', [
            'club_id' => 1, 'evaluation_id' => (int) $wpdb->insert_id, 'category_id' => 1, 'rating' => 7,
        ] );
    }
}
