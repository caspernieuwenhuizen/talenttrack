<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\EvalWindowsRepository;
use TT\Modules\Pdp\Services\PdpPlanningService;
use TT\Shared\Frontend\FrontendTileGrid;

/**
 * #4497 — "Follow-ups due this week" on the This week panel: PDP talks due,
 * evaluations due and the viewer's own tasks. Each count comes from the
 * service its linked view reads, and a line shows only for a viewer that
 * view admits.
 *
 * Coverage reads the site's real today, so the panel's week is this week.
 */
final class CoachWeekFollowUpsTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $admin  = 0;
    private int $coach  = 0;
    private int $season = 0;

    /** @var list<int> */
    private array $players = [];

    private string $today = '';

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
        FeatureRegistry::setEnabled( 'analytics_eval_coverage', true );

        $this->today = (string) current_time( 'Y-m-d' );

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'JO14-1' ] );
        $this->team = (int) $wpdb->insert_id;
        foreach ( [ 'Een', 'Twee', 'Drie' ] as $last ) {
            $wpdb->insert( $wpdb->prefix . 'tt_players', [
                'club_id'    => 1,
                'team_id'    => $this->team,
                'first_name' => 'Week',
                'last_name'  => $last,
                'status'     => 'active',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }
        // An activity this week, so the coach's week holds the team.
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $this->team,
            'title'             => 'Training',
            'session_date'      => $this->today,
            'start_time'        => '18:30:00',
            'activity_type_key' => 'training',
        ] );

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $persona     = (string) \TT\Modules\Authorization\PersonaResolver::landingPersona( $this->admin );
        $this->configure( $persona, [ 'on' => true, 'teams' => 'all', 'desktop' => [ 'pdp', 'evals', 'tasks' ], 'phone' => [ 'tasks' ] ] );

        $this->coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coach, $this->team );

        $wpdb->insert( $wpdb->prefix . 'tt_seasons', [
            'club_id'    => 1,
            'name'       => 'This season',
            'start_date' => $this->day( -120 ),
            'end_date'   => $this->day( 200 ),
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        FeatureRegistry::setEnabled( 'analytics_eval_coverage', false );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // PDP conversations due

    public function test_the_pdp_count_matches_the_planning_view(): void {
        $week = $this->week( $this->admin );
        $ends = $week['week_end'];
        // Block 1 closes this week: one talk held, one marked not held, one still to hold.
        $this->talk( $this->players[0], 1, $ends, 'conducted' );
        $this->talk( $this->players[1], 1, $ends, 'not_held' );
        $this->talk( $this->players[2], 1, $ends, null );
        // Block 2 closes later: never counted this week.
        foreach ( $this->players as $pid ) $this->talk( $pid, 2, $this->day( 60 ), null );

        $this->assertSame( $this->season, PdpPlanningService::currentSeasonId(), 'the season the planning view opens on' );
        $cell     = PdpPlanningService::buildMatrix( $this->season, [ $this->team ] )['teams'][ $this->team ]['blocks'][1];
        $expected = $cell['expected'] - $cell['conducted'] - $cell['not_held'];
        $this->assertSame( 1, $expected, 'the planning view: three on the roster, one held, one not held' );

        $week = $this->week( $this->admin );
        $this->assertSame( [ 'count' => $expected, 'closes' => $ends ], $week['pdp_due'] );
    }

    public function test_no_talk_left_to_hold_leaves_the_line_out(): void {
        $ends = $this->week( $this->admin )['week_end'];
        foreach ( $this->players as $pid ) $this->talk( $pid, 1, $ends, 'conducted' );
        $this->assertNull( $this->week( $this->admin )['pdp_due'] );
    }

    // Evaluations due

    public function test_the_evaluations_count_matches_the_coverage_view(): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [
            [ 'name' => 'Round 1', 'start' => $this->day( -30 ), 'end' => $this->day( 30 ) ],
            [ 'name' => 'Round 2', 'start' => $this->day( 31 ), 'end' => $this->day( 90 ) ],
        ] );
        $this->evaluate( $this->players[0], $this->day( -2 ) );

        $gaps = 0;
        foreach ( ( new EvalCoverageService() )->coverage()['teams'] as $team ) {
            if ( (int) $team['team_id'] !== $this->team ) continue;
            foreach ( $team['players'] as $player ) {
                if ( $player['cells'][0]['state'] === 'gap' ) $gaps++;
            }
        }
        $this->assertSame( 2, $gaps, 'the coverage view: two of three not evaluated in the open round' );

        $due = $this->week( $this->admin )['evals_due'];
        $this->assertSame( $gaps, $due['count'] ?? null );
        $this->assertSame( 'Round 1', $due['round'] ?? null );
    }

    public function test_a_viewer_who_may_not_open_coverage_gets_no_evaluations_line(): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [
            [ 'name' => 'Round 1', 'start' => $this->day( -30 ), 'end' => $this->day( 30 ) ],
        ] );
        $this->assertFalse( EvalCoverageService::readerMayOpen( $this->coach ), 'a team-scoped coach cannot open the academy-wide report' );

        $week = $this->week( $this->coach );
        $this->assertContains( 'evals', $week['blocks']['desktop'], 'on by default for a head coach' );
        $this->assertNull( $week['evals_due'], 'no count for a view the coach cannot open' );
        $this->assertStringNotContainsString( 'eval-coverage', $this->panelHtml( $week ), 'and no link to it' );
    }

    public function test_coverage_switched_off_gives_no_evaluations_line(): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [
            [ 'name' => 'Round 1', 'start' => $this->day( -30 ), 'end' => $this->day( 30 ) ],
        ] );
        FeatureRegistry::setEnabled( 'analytics_eval_coverage', false );
        $this->assertNull( $this->week( $this->admin )['evals_due'] );
    }

    // My tasks due this week

    public function test_my_tasks_counts_my_open_tasks_due_in_the_window(): void {
        $week = $this->week( $this->admin );
        $this->task( $this->admin, $week['week_end'] . ' 18:00:00', 'open' );
        $this->task( $this->admin, $week['week_start'] . ' 09:00:00', 'overdue' );
        $this->task( $this->admin, $week['week_end'] . ' 18:00:00', 'completed' );   // done
        $this->task( $this->admin, $this->day( 40 ) . ' 09:00:00', 'open' );         // later
        $this->task( $this->coach, $week['week_end'] . ' 18:00:00', 'open' );         // somebody else's

        $this->assertSame( [ 'count' => 2 ], $this->week( $this->admin )['tasks_due'] );
    }

    // Defaults and the section

    public function test_the_defaults(): void {
        $expect = [
            'pdp'   => [ 'head_coach' => [ true, true ], 'head_of_development' => [ true, false ], 'team_manager' => [ false, false ], 'academy_admin' => [ false, false ] ],
            'evals' => [ 'head_coach' => [ true, false ], 'head_of_development' => [ true, false ], 'team_manager' => [ false, false ], 'academy_admin' => [ false, false ] ],
            'tasks' => [ 'head_coach' => [ true, false ], 'head_of_development' => [ false, false ], 'team_manager' => [ true, true ], 'academy_admin' => [ true, true ] ],
        ];
        foreach ( $expect as $key => $personas ) {
            $block = WeekBlockRegistry::get( $key );
            $this->assertNotNull( $block, $key );
            foreach ( $personas as $persona => [ $desktop, $phone ] ) {
                $this->assertSame( $desktop, $block->defaultFor( $persona, 'desktop' ), "{$key} desktop for {$persona}" );
                $this->assertSame( $phone, $block->defaultFor( $persona, 'phone' ), "{$key} phone for {$persona}" );
            }
            $this->assertTrue( $block->optInFor( 'scout' ), "{$key} reaches a scout only when switched on" );
        }
    }

    public function test_the_section_links_each_line_and_hides_when_empty(): void {
        // Only a round that has not opened yet, so no evaluation is due.
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [
            [ 'name' => 'Later', 'start' => $this->day( 31 ), 'end' => $this->day( 90 ) ],
        ] );
        $week = $this->week( $this->admin );
        $this->assertStringNotContainsString( 'tt-week-followups', $this->panelHtml( $week ), 'nothing due, no section' );

        $week['pdp_due']   = [ 'count' => 4, 'closes' => $week['week_end'] ];
        $week['evals_due'] = [ 'count' => 2, 'round' => 'Round 1', 'closes' => $week['week_end'] ];
        $week['tasks_due'] = [ 'count' => 2 ];
        $html = $this->panelHtml( $week );

        $this->assertStringContainsString( 'Follow-ups due this week', $html );
        $this->assertStringContainsString( 'tt_view=pdp-planning', $html );
        $this->assertStringContainsString( 'tt_view=eval-coverage', $html );
        $this->assertStringContainsString( 'tt_view=my-tasks', $html );
        $this->assertStringContainsString( '4 players · closes', $html );
        $this->assertStringContainsString( '2 due', $html );
        $this->assertStringContainsString( '<li class="tt-week-off-phone"><a class="tt-week-followup" href="', $html, 'the PDP and evaluation lines are desktop-only' );
        $this->assertStringContainsString( '<div class="tt-week-followups">', $html, 'the tasks line is on both devices, so the section is too' );
    }

    // Helpers

    /** @return array<string, mixed> */
    private function week( int $user_id ): array {
        wp_set_current_user( $user_id );
        return CoachWeek::forUser( $user_id, $this->today );
    }

    private function day( int $offset ): string {
        return gmdate( 'Y-m-d', (int) strtotime( $this->today . ' 12:00:00' ) + $offset * DAY_IN_SECONDS );
    }

    private function talk( int $player_id, int $sequence, string $window_end, ?string $state ): void {
        global $wpdb;
        $file = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_pdp_files WHERE player_id = %d AND season_id = %d",
            $player_id, $this->season
        ) );
        if ( $file === 0 ) {
            $wpdb->insert( $wpdb->prefix . 'tt_pdp_files', [
                'club_id'        => 1,
                'player_id'      => $player_id,
                'season_id'      => $this->season,
                'owner_coach_id' => $this->admin,
                'cycle_size'     => 3,
                'status'         => 'open',
            ] );
            $file = (int) $wpdb->insert_id;
        }
        $wpdb->insert( $wpdb->prefix . 'tt_pdp_conversations', [
            'club_id'               => 1,
            'pdp_file_id'           => $file,
            'sequence'              => $sequence,
            'template_key'          => 'start',
            'planning_window_start' => $this->day( -20 ),
            'planning_window_end'   => $window_end,
            'conducted_at'          => $state === 'conducted' ? $this->day( -1 ) . ' 09:00:00' : null,
            'not_held_at'           => $state === 'not_held' ? $this->day( -1 ) . ' 09:00:00' : null,
        ] );
    }

    private function evaluate( int $player_id, string $date ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id'   => 1,
            'player_id' => $player_id,
            'coach_id'  => $this->admin,
            'eval_date' => $date,
        ] );
    }

    private function task( int $user_id, string $due_at, string $status ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_workflow_tasks', [
            'club_id'          => 1,
            'template_key'     => 'follow_up_test',
            'assignee_user_id' => $user_id,
            'status'           => $status,
            'due_at'           => $due_at,
        ] );
    }

    /** @param array<string, mixed> $overrides */
    private function configure( string $persona, array $overrides ): void {
        $settings             = CoachWeek::settings();
        $settings[ $persona ] = array_merge( $settings[ $persona ], $overrides );
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, CoachWeek::normaliseSettingsStored( (string) wp_json_encode( $settings ) ) );
    }

    /** @param array<string, mixed> $week */
    private function panelHtml( array $week ): string {
        $method = new \ReflectionMethod( FrontendTileGrid::class, 'renderWeekPanel' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $week );
        return (string) ob_get_clean();
    }

    private function grantTeam( int $user_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Week',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }
}
