<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Shared\Frontend\FrontendTileGrid;

/**
 * #4495 — the "This week" summary strip: trainings, matches, and the
 * attendance % the team attendance report gives for the same activities.
 *
 * The report counts only activities dated on or before the database's
 * today, so the fixtures sit in a week two weeks before the run.
 */
final class CoachWeekSummaryTest extends WP_UnitTestCase {

    private int $team      = 0;
    private int $otherTeam = 0;
    private int $coach     = 0;

    /** @var list<int> */
    private array $players = [];

    /** @var list<string> Monday to Sunday of the fixture week */
    private array $days = [];

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

        $monday = (int) strtotime( 'monday this week', time() ) - 14 * DAY_IN_SECONDS;
        for ( $i = 0; $i < 7; $i++ ) $this->days[] = gmdate( 'Y-m-d', $monday + $i * DAY_IN_SECONDS );

        $this->team      = $this->team( 'JO13-1' );
        $this->otherTeam = $this->team( 'JO15-1' );
        $this->players   = [ $this->player( $this->team, 'Een' ), $this->player( $this->team, 'Twee' ) ];

        $this->coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coach, $this->team );
        wp_set_current_user( $this->coach );

        // The owner's week: three trainings and a match, all played.
        $mon = $this->activity( $this->team, $this->days[0], 'training' );
        $tue = $this->activity( $this->team, $this->days[1], 'training' );
        $this->activity( $this->team, $this->days[3], 'training' );          // no register taken
        $sat = $this->activity( $this->team, $this->days[5], 'game' );

        $this->attendance( $mon, [ 'present', 'absent' ] );
        $this->attendance( $tue, [ 'late', 'present' ] );
        $this->attendance( $sat, [ 'present', 'excused' ] );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_strip_reads_three_trainings_one_match_and_the_reports_attendance(): void {
        $week = CoachWeek::forUser( $this->coach, $this->days[2] );
        $this->assertContains( 'summary', $week['blocks']['desktop'], 'on for a head coach on desktop by default' );

        $report = AttendanceFlagService::pooledPresentPct(
            ( new AttendanceRankingQuery() )->teamRows( $this->days[0], $this->days[6], [ $this->team ] )
        );
        $this->assertSame( 66.7, $report, 'four of six recorded rows attended, late counted as attended' );
        $this->assertSame(
            [ 'trainings' => 3, 'matches' => 1, 'attendance_pct' => $report ],
            $week['summary'],
            'the strip gives the attendance report\'s figure for the same activities'
        );
    }

    public function test_all_academy_teams_covers_every_team_in_the_window(): void {
        $this->activity( $this->otherTeam, $this->days[2], 'training' );
        $admin   = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $persona = (string) \TT\Modules\Authorization\PersonaResolver::landingPersona( $admin );
        $this->configure( $persona, [ 'on' => true, 'teams' => 'all', 'desktop' => [ 'summary' ] ] );

        $week = CoachWeek::forUser( $admin, $this->days[2] );
        $this->assertSame( 4, $week['summary']['trainings'] ?? null, 'the other team\'s training is counted too' );
        $this->assertSame( 1, $week['summary']['matches'] ?? null );
    }

    public function test_off_for_the_persona_leaves_the_field_null(): void {
        $this->configure( 'head_coach', [ 'desktop' => [ 'type' ], 'phone' => [ 'type' ] ] );
        $this->assertNull( CoachWeek::forUser( $this->coach, $this->days[2] )['summary'] );
    }

    public function test_the_defaults(): void {
        $block = WeekBlockRegistry::get( 'summary' );
        $this->assertNotNull( $block );
        $this->assertTrue( $block->defaultFor( 'head_coach', 'desktop' ) );
        $this->assertFalse( $block->defaultFor( 'head_coach', 'phone' ) );
        foreach ( [ 'head_of_development', 'academy_admin' ] as $persona ) {
            $this->assertTrue( $block->defaultFor( $persona, 'desktop' ), $persona );
            $this->assertTrue( $block->defaultFor( $persona, 'phone' ), $persona );
        }
        foreach ( [ 'assistant_coach', 'team_manager', 'scout' ] as $persona ) {
            $this->assertFalse( $block->defaultFor( $persona, 'desktop' ), $persona );
            $this->assertFalse( $block->defaultFor( $persona, 'phone' ), $persona );
        }
    }

    public function test_the_strip_renders_three_labelled_figures_and_a_dash_without_a_register(): void {
        $week = CoachWeek::forUser( $this->coach, $this->days[2] );
        $html = $this->panelHtml( $week );
        $this->assertSame( 3, substr_count( $html, 'tt-week-summary-cell' ) );
        $this->assertStringContainsString( '<dt>Trainings</dt><dd>3</dd>', $html );
        $this->assertStringContainsString( '<dt>Matches</dt><dd>1</dd>', $html );
        $this->assertStringContainsString( '66.7%', $html );
        $this->assertStringContainsString( 'tt-week-off-phone', $html, 'off on a phone for a head coach' );

        $week['summary']['attendance_pct'] = null;
        $this->assertStringContainsString( '<dt>Attendance</dt><dd>—</dd>', $this->panelHtml( $week ) );
    }

    // Helpers

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

    private function activity( int $team_id, string $date, string $type ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'             => 1,
            'team_id'             => $team_id,
            'title'               => ucfirst( $type ),
            'session_date'        => $date,
            'start_time'          => '18:30:00',
            'activity_type_key'   => $type,
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param list<string> $statuses one per fixture player, in order */
    private function attendance( int $activity_id, array $statuses ): void {
        global $wpdb;
        foreach ( $statuses as $i => $status ) {
            $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
                'club_id'     => 1,
                'activity_id' => $activity_id,
                'player_id'   => $this->players[ $i ],
                'is_guest'    => 0,
                'status'      => $status,
                'record_type' => 'actual',
            ] );
        }
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
