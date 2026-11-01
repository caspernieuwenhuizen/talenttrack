<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #4379 — the coach's week, as `CoachWeek::forUser()` and `GET me/week`
 * return it, in the four states of the mockup: mid-week with a match on
 * Saturday, match day, a week without a match, and an empty week.
 *
 * The week used is Monday 2 to Sunday 8 November 2026.
 */
final class CoachWeekTest extends WP_UnitTestCase {

    private int $team      = 0;
    private int $otherTeam = 0;
    private int $coach     = 0;

    /** @var list<int> */
    private array $players = [];

    private int $tuesday  = 0;
    private int $thursday = 0;
    private int $match    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        ActivityRegisterProgress::forget();
        $wpdb->delete( $wpdb->prefix . 'tt_config', [ 'club_id' => 1, 'config_key' => CoachWeek::CONFIG_KEY ] );
        $wpdb->delete( $wpdb->prefix . 'tt_config', [ 'club_id' => 1, 'config_key' => \TT\Shared\Dates\TTDate::WEEK_START_KEY ] );
        $this->resetWeekStartCache();

        $this->team      = $this->team( 'JO13-1' );
        $this->otherTeam = $this->team( 'JO15-1' );
        for ( $i = 1; $i <= 4; $i++ ) $this->players[] = $this->player( $this->team, 'Speler' . $i );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coach, $this->team );
        wp_set_current_user( $this->coach );

        $this->tuesday  = $this->activity( $this->team, '2026-11-03', 'training', 'Training JO13-1' );
        $this->thursday = $this->activity( $this->team, '2026-11-05', 'training', 'Training JO13-1' );
        $this->match    = $this->activity( $this->team, '2026-11-07', 'game', 'Uit', [
            'opponent'         => 'VV Zwaluwen JO13-2',
            'home_away'        => 'away',
            'time_of_presence' => '09:15:00',
            'start_time'       => '10:00:00',
            'location'         => 'Wijchen',
        ] );
        // Not this coach's team, and a cancelled one: neither is in the week.
        $this->activity( $this->otherTeam, '2026-11-04', 'training', 'Other team' );
        $this->activity( $this->team, '2026-11-06', 'training', 'Cancelled', [ 'activity_status_key' => 'cancelled' ] );

        // Thursday's planned squad: three expected, one not coming.
        foreach ( $this->players as $n => $pid ) {
            $this->planned( $this->thursday, $pid, $n === 3 ? 'Absent' : 'Present' );
        }

        // One player is injured over Saturday's match.
        $wpdb->insert( $wpdb->prefix . 'tt_player_injuries', [
            'club_id'    => 1,
            'player_id'  => $this->players[0],
            'started_on' => '2026-11-01',
            'notes'      => 'Ankle',
        ] );

        // A match prep with two starters placed.
        $repo    = new MatchPrepRepository();
        $prep_id = $repo->ensureForActivity( $this->match, 30 );
        $repo->replaceLineupForHalf( $prep_id, 1, [ 1 => $this->players[1], 2 => $this->players[2] ] );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        $this->resetWeekStartCache();
        parent::tear_down();
    }

    public function test_the_default_week_runs_monday_to_sunday(): void {
        $this->assertSame( [ '2026-11-02', '2026-11-08' ], CoachWeek::weekBounds( '2026-11-04' ) );
        $this->assertSame( [ '2026-11-02', '2026-11-08' ], CoachWeek::weekBounds( '2026-11-02' ) );
        $this->assertSame( [ '2026-11-02', '2026-11-08' ], CoachWeek::weekBounds( '2026-11-08' ) );
    }

    public function test_a_sunday_start_week_runs_sunday_to_saturday(): void {
        $this->assertSame( [ '2026-11-01', '2026-11-07' ], CoachWeek::weekBounds( '2026-11-04', false ) );
        $this->assertSame( [ '2026-11-01', '2026-11-07' ], CoachWeek::weekBounds( '2026-11-01', false ) );
        $this->assertSame( [ '2026-11-01', '2026-11-07' ], CoachWeek::weekBounds( '2026-11-07', false ) );
        $this->assertSame( [ '2026-11-08', '2026-11-14' ], CoachWeek::weekBounds( '2026-11-08', false ) );
    }

    public function test_the_academy_week_start_setting_moves_the_week(): void {
        \TT\Infrastructure\Query\QueryHelpers::set_config( \TT\Shared\Dates\TTDate::WEEK_START_KEY, 'sun' );
        $this->resetWeekStartCache();

        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );
        $this->assertSame( '2026-11-01', $week['week_start'] );
        $this->assertSame( '2026-11-07', $week['week_end'] );
        $this->assertSame(
            [ $this->tuesday, $this->thursday, $this->match ],
            array_column( $week['rows'], 'activity_id' )
        );

        // Sunday the 8th opens the next week, so Saturday's match is gone.
        $next = CoachWeek::forUser( $this->coach, '2026-11-08' );
        $this->assertSame( [ '2026-11-08', '2026-11-14' ], [ $next['week_start'], $next['week_end'] ] );
        $this->assertSame( [], $next['rows'] );
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_matches(): void {
        $repo = new MatchPrepRepository();
        // Week of 9 November: ten fixtures. Week of 16 November: one.
        for ( $i = 0; $i < 10; $i++ ) {
            $aid  = $this->activity( $this->team, '2026-11-1' . ( $i % 6 ), 'game', 'Fixture ' . $i, [ 'opponent' => 'Opp ' . $i ] );
            $prep = $repo->ensureForActivity( $aid, 30 );
            $repo->replaceLineupForHalf( $prep, 1, [ 1 => $this->players[1] ] );
        }
        $single = $this->activity( $this->team, '2026-11-17', 'game', 'Only fixture' );
        $repo->replaceLineupForHalf( $repo->ensureForActivity( $single, 30 ), 1, [ 1 => $this->players[1] ] );

        // Warm the per-request caches (lookup translations, team form, the
        // matrix) so both measured calls start from the same state.
        $this->queriesFor( '2026-11-09' );
        $this->queriesFor( '2026-11-16' );

        $one = $this->queriesFor( '2026-11-16' );
        $ten = $this->queriesFor( '2026-11-09' );

        $this->assertSame( $one['matches'], 1 );
        $this->assertSame( $ten['matches'], 10 );
        $this->assertSame(
            count( $one['sql'] ),
            count( $ten['sql'] ),
            "one query per source, whatever the number of matches. With ten:\n" . implode( "\n", $ten['sql'] )
        );
    }

    public function test_post_config_never_stores_player_or_parent(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/config' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [ 'config' => [ CoachWeek::CONFIG_KEY => '["player","parent","head_coach"]' ] ] ) );

        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( '["head_coach"]', \TT\Infrastructure\Query\QueryHelpers::get_config( CoachWeek::CONFIG_KEY, '' ) );
        $this->assertSame( [ 'head_coach' ], CoachWeek::configuredPersonas() );
    }

    /** @return array{sql:list<string>, matches:int} */
    private function queriesFor( string $today ): array {
        ActivityRegisterProgress::forget();
        \TT\Infrastructure\Journey\SuspensionService::flushCache();
        $sql     = [];
        $capture = static function ( $query ) use ( &$sql ) {
            $sql[] = preg_replace( '/\s+/', ' ', (string) $query );
            return $query;
        };
        add_filter( 'query', $capture );
        $week = CoachWeek::forUser( $this->coach, $today );
        remove_filter( 'query', $capture );
        return [
            'sql'     => $sql,
            'matches' => count( array_filter( $week['rows'], static fn( $r ) => $r['is_match'] && $r['lineup'] !== null ) ),
        ];
    }

    /** TTDate memoises the week start per request; a test that changes it must clear it. */
    private function resetWeekStartCache(): void {
        $prop = new \ReflectionProperty( \TT\Shared\Dates\TTDate::class, 'week_monday_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
    }

    public function test_mid_week_with_a_match_on_saturday(): void {
        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );

        $this->assertTrue( $week['visible'] );
        $this->assertSame( '2026-11-02', $week['week_start'] );
        $this->assertSame( '2026-11-08', $week['week_end'] );
        $this->assertFalse( $week['multiple_teams'] );
        $this->assertTrue( $week['has_match'] );
        $this->assertSame(
            [ $this->tuesday, $this->thursday, $this->match ],
            array_column( $week['rows'], 'activity_id' ),
            'own team only, in date order, the cancelled activity left out'
        );

        [ $tue, $thu, $sat ] = $week['rows'];

        $this->assertSame( 'past', $tue['when'] );
        $this->assertFalse( $tue['attendance_recorded'] );
        $this->assertContains( CoachWeek::ACTION_MARK_ATTENDANCE, $tue['actions'] );

        $this->assertSame( 'upcoming', $thu['when'] );
        $this->assertSame( [ 'expected' => 3, 'roster' => 4 ], $thu['planned'] );
        $this->assertFalse( $thu['training_plan'] );
        $this->assertSame( [], $thu['actions'], 'an upcoming training opens its activity, nothing more' );

        $this->assertTrue( $sat['is_match'] );
        $this->assertSame( 'VV Zwaluwen JO13-2', $sat['opponent'] );
        $this->assertSame( 'away', $sat['home_away'] );
        $this->assertSame( '09:15', $sat['meet_time'] );
        $this->assertSame( '10:00', $sat['start_time'] );
        $this->assertSame( [ 'available' => 3, 'unavailable' => 1 ], $sat['availability'] );
        $this->assertSame( 2, $sat['lineup']['placed'] );
        $this->assertGreaterThanOrEqual( 2, $sat['lineup']['target'] );
        $this->assertSame( [ CoachWeek::ACTION_MATCH_PREP, CoachWeek::ACTION_OPEN_ACTIVITY ], $sat['actions'] );
    }

    public function test_match_day(): void {
        $this->recorded( $this->tuesday );
        $week = CoachWeek::forUser( $this->coach, '2026-11-07' );

        [ $tue, $thu, $sat ] = $week['rows'];
        $this->assertTrue( $tue['attendance_recorded'] );
        $this->assertSame( [], $tue['actions'] );
        $this->assertFalse( $thu['attendance_recorded'] );

        $this->assertSame( 'today', $sat['when'] );
        $this->assertSame( [ CoachWeek::ACTION_START_MATCH, CoachWeek::ACTION_OPEN_ACTIVITY ], $sat['actions'] );
    }

    /** #4486 — a match completed by hand on the day offers its record, not a live start. */
    public function test_a_completed_match_today_offers_no_start_and_no_prep(): void {
        $this->complete( $this->match, 'Completed' );
        $week = CoachWeek::forUser( $this->coach, '2026-11-07' );
        $sat  = $week['rows'][2];

        $this->assertSame( 'today', $sat['when'] );
        $this->assertSame( [ CoachWeek::ACTION_MARK_ATTENDANCE, CoachWeek::ACTION_OPEN_ACTIVITY ], $sat['actions'], 'nothing recorded yet: mark attendance, then the activity' );
        $this->assertFalse( $sat['attendance_recorded'] );
        $this->assertNull( $sat['availability'] );
        $this->assertNull( $sat['lineup'] );

        $this->recorded( $this->match );
        $sat = CoachWeek::forUser( $this->coach, '2026-11-07' )['rows'][2];
        $this->assertTrue( $sat['attendance_recorded'] );
        $this->assertSame( [ CoachWeek::ACTION_OPEN_ACTIVITY ], $sat['actions'] );
    }

    public function test_an_upcoming_match_already_completed_follows_the_same_rule(): void {
        $this->complete( $this->match, 'completed' );
        $sat = CoachWeek::forUser( $this->coach, '2026-11-04' )['rows'][2];

        $this->assertSame( 'upcoming', $sat['when'] );
        $this->assertFalse( $sat['attendance_recorded'] );
        $this->assertSame( [ CoachWeek::ACTION_OPEN_ACTIVITY ], $sat['actions'], 'no Mark attendance before the day: the attendance rule refuses present and late on a future date' );
        $this->assertNotContains( CoachWeek::ACTION_MATCH_PREP, $sat['actions'] );
        $this->assertNotContains( CoachWeek::ACTION_START_MATCH, $sat['actions'] );
        $this->assertContains( CoachWeek::ACTION_OPEN_ACTIVITY, $sat['actions'] );
        $this->assertNull( $sat['availability'] );
        $this->assertNull( $sat['lineup'] );
    }

    public function test_rest_returns_no_start_match_for_a_completed_match_today(): void {
        $today = \TT\Modules\Activities\Services\AttendanceDateRule::today();
        $repo  = new MatchPrepRepository();
        $live  = $this->activity( $this->team, $today, 'game', 'Played', [ 'opponent' => 'Baronie O14-1' ] );
        $done  = $this->activity( $this->team, $today, 'game', 'Recorded', [ 'opponent' => 'Baronie O14-2', 'activity_status_key' => 'completed' ] );
        foreach ( [ $live, $done ] as $aid ) {
            $repo->replaceLineupForHalf( $repo->ensureForActivity( $aid, 30 ), 1, [ 1 => $this->players[1] ] );
        }

        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/week' ) );
        $this->assertSame( 200, $res->get_status() );
        $rows = [];
        foreach ( (array) $res->get_data()['data']['rows'] as $row ) $rows[ (int) $row['activity_id'] ] = $row;

        $this->assertContains( CoachWeek::ACTION_START_MATCH, $rows[ $live ]['actions'], 'a planned match today with a line-up still starts' );
        $this->assertNotContains( CoachWeek::ACTION_START_MATCH, $rows[ $done ]['actions'] );
        $this->assertNotContains( CoachWeek::ACTION_MATCH_PREP, $rows[ $done ]['actions'] );
    }

    public function test_a_week_without_a_match(): void {
        $today    = $this->activity( $this->team, '2026-11-17', 'training', 'Training JO13-1' );
        $thursday = $this->activity( $this->team, '2026-11-19', 'training', 'Training JO13-1' );
        $this->trainingRun( $today );

        $week = CoachWeek::forUser( $this->coach, '2026-11-17' );

        $this->assertFalse( $week['has_match'] );
        $this->assertSame( [ $today, $thursday ], array_column( $week['rows'], 'activity_id' ) );
        $this->assertSame( 'today', $week['rows'][0]['when'] );
        $this->assertTrue( $week['rows'][0]['training_plan'] );
        $this->assertSame( [ CoachWeek::ACTION_MARK_ATTENDANCE ], $week['rows'][0]['actions'] );
        $this->assertFalse( $week['rows'][1]['training_plan'] );
        $this->assertNull( $week['rows'][1]['planned'], 'no planned squad captured, so no count' );
    }

    public function test_an_empty_week(): void {
        $week = CoachWeek::forUser( $this->coach, '2026-12-23' );

        $this->assertTrue( $week['visible'] );
        $this->assertSame( [], $week['rows'] );
        $this->assertFalse( $week['has_match'] );
    }

    public function test_two_teams_merge_by_date(): void {
        $this->grantTeam( $this->coach, $this->otherTeam );
        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );

        $this->assertTrue( $week['multiple_teams'] );
        $this->assertCount( 4, $week['rows'] );
        $this->assertSame( [ '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-07' ], array_column( $week['rows'], 'date' ) );
        $this->assertSame( 'JO15-1', $week['rows'][1]['team_name'] );
    }

    public function test_availability_carries_counts_and_nothing_about_the_injury(): void {
        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );

        // #4496 — the injuries line names the injured as a marker, behind its
        // own naming rule (CoachWeekAttentionTest). Everything else in the
        // week still says nothing about an injury, and that line says
        // nothing about the injury itself.
        $line = (string) wp_json_encode( $week['attention_injuries'] );
        unset( $week['attention_injuries'] );
        // The block's own key in the device lists names the switch, not a player.
        foreach ( $week['blocks'] as $device => $keys ) {
            $week['blocks'][ $device ] = array_values( array_diff( (array) $keys, [ 'injuries' ] ) );
        }
        $json = (string) wp_json_encode( $week );

        $this->assertStringNotContainsString( 'Ankle', $line );
        $this->assertStringNotContainsString( 'Ankle', $json );
        $this->assertStringNotContainsString( 'injur', strtolower( $json ) );
        $this->assertSame( [ 'available', 'unavailable' ], array_keys( $week['rows'][2]['availability'] ) );
    }

    public function test_a_user_who_coaches_no_team_sees_no_week(): void {
        $other = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $this->assertFalse( CoachWeek::visibleFor( $other ) );
        $week = CoachWeek::forUser( $other, '2026-11-04' );
        $this->assertFalse( $week['visible'] );
        $this->assertSame( [], $week['rows'] );
    }

    public function test_the_persona_list_is_the_academy_setting(): void {
        $this->assertSame( [ 'head_coach', 'assistant_coach' ], CoachWeek::configuredPersonas() );
        $this->assertTrue( CoachWeek::visibleFor( $this->coach ) );

        \TT\Infrastructure\Query\QueryHelpers::set_config( CoachWeek::CONFIG_KEY, CoachWeek::normaliseStored( '["team_manager"]' ) );
        $this->assertSame( [ 'team_manager' ], CoachWeek::configuredPersonas() );
        $this->assertFalse( CoachWeek::visibleFor( $this->coach ), 'head coach unticked: no panel' );
    }

    public function test_the_stored_list_keeps_known_staff_personas_only(): void {
        $this->assertSame( '["head_coach","team_manager"]', CoachWeek::normaliseStored( '["head_coach","player","parent","bogus","team_manager","head_coach"]' ) );
        $this->assertSame( '["scout"]', CoachWeek::normaliseStored( 'scout, parent' ) );
        $this->assertSame( '[]', CoachWeek::normaliseStored( '[]' ) );
    }

    public function test_rest_returns_the_same_rows(): void {
        $this->activity( $this->team, \TT\Modules\Activities\Services\AttendanceDateRule::today(), 'training', 'Today' );

        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/week' ) );
        $this->assertSame( 200, $res->get_status() );

        $data = (array) $res->get_data()['data'];
        $this->assertNotSame( [], $data['rows'] );
        $this->assertSame(
            CoachWeek::forUser( $this->coach ),
            $data,
            'the route answers what the panel renders'
        );
    }

    public function test_rest_answers_visible_false_rather_than_refusing_a_listed_out_user(): void {
        \TT\Infrastructure\Query\QueryHelpers::set_config( CoachWeek::CONFIG_KEY, '["team_manager"]' );

        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/week' ) );
        $this->assertSame( 200, $res->get_status() );
        $data = (array) $res->get_data()['data'];
        $this->assertFalse( $data['visible'] );
        $this->assertSame( [], $data['rows'] );
    }

    public function test_rest_refuses_a_user_who_may_not_read_activities(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/week' ) );
        $this->assertSame( 403, $res->get_status() );
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function player( int $team_id, string $last ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $team_id,
            'first_name' => 'Week',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string, mixed> $extra */
    private function activity( int $team_id, string $date, string $type, string $title, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', array_merge( [
            'club_id'           => 1,
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => $date,
            'start_time'        => '18:30:00',
            'activity_type_key' => $type,
        ], $extra ) );
        return (int) $wpdb->insert_id;
    }

    private function planned( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'record_type' => 'expected',
        ] );
    }

    private function recorded( int $activity_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => $activity_id,
            'player_id'   => $this->players[0],
            'status'      => 'Present',
            'record_type' => 'actual',
        ] );
        ActivityRegisterProgress::forget();
    }

    private function complete( int $activity_id, string $status ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_activities', [ 'activity_status_key' => $status ], [ 'id' => $activity_id ] );
    }

    private function trainingRun( int $activity_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_training_plan_runs', [
            'club_id'     => 1,
            'uuid'        => wp_generate_uuid4(),
            'plan_id'     => 0,
            'activity_id' => $activity_id,
            'team_id'     => $this->team,
            'run_date'    => '2026-11-17',
        ] );
    }

    /** A staff record and an active team-scope grant, what get_teams_for_coach() reads. */
    private function grantTeam( int $user_id, int $team_id ): void {
        global $wpdb;
        $person_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d",
            $user_id
        ) );
        if ( $person_id <= 0 ) {
            $wpdb->insert( $wpdb->prefix . 'tt_people', [
                'club_id'    => 1,
                'first_name' => 'Week',
                'last_name'  => 'Coach',
                'role_type'  => 'head_coach',
                'wp_user_id' => $user_id,
                'status'     => 'active',
            ] );
            $person_id = (int) $wpdb->insert_id;
        }
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }
}
