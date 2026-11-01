<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Shared\Frontend\FrontendConfigurationView;

/**
 * #4493 — the training blocks of the "This week" panel: the training theme
 * from the linked plan, and attendance after the fact on a past training.
 * Uses the week of Monday 2 to Sunday 8 November 2026.
 */
final class CoachWeekTrainingBlocksTest extends WP_UnitTestCase {

    private int $team  = 0;
    private int $coach = 0;

    /** @var list<int> */
    private array $players = [];

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

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'JO11-1' ] );
        $this->team = (int) $wpdb->insert_id;
        foreach ( [ 'Een', 'Twee', 'Drie' ] as $last ) {
            $wpdb->insert( $wpdb->prefix . 'tt_players', [
                'club_id' => 1, 'team_id' => $this->team, 'first_name' => 'Week', 'last_name' => $last, 'status' => 'active',
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id' => 1, 'first_name' => 'Week', 'last_name' => 'Coach', 'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id' => (int) $wpdb->insert_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        wp_set_current_user( $this->coach );

        // Both blocks on for every persona, so the test does not depend on
        // which coaching persona the fixture resolves to.
        $settings = CoachWeek::settings();
        foreach ( $settings as $persona => $s ) {
            $settings[ $persona ]['on']      = true;
            $settings[ $persona ]['desktop'] = WeekBlockRegistry::keys();
        }
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, CoachWeek::normaliseSettingsStored( (string) wp_json_encode( $settings ) ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_past_training_shows_who_was_there_and_the_plan_theme(): void {
        $tuesday = $this->training( '2026-11-03' );
        $this->runPlan( $tuesday, 'pressing' );
        $this->record( $tuesday, [ 'Present', 'Late', 'Absent' ] );
        $thursday = $this->training( '2026-11-05' );

        $rows = $this->rowsFor( '2026-11-04' );

        $this->assertSame( [ 'present' => 2, 'recorded' => 3 ], $rows[ $tuesday ]['attendance_rate'], 'Late counts as attended; the base is the recorded rows' );
        $expected = (string) LookupTranslator::byTypeAndName( 'vct_tactical_theme', 'pressing' );
        $this->assertSame( $expected !== '' ? $expected : 'pressing', $rows[ $tuesday ]['theme'] );

        $this->assertNull( $rows[ $thursday ]['theme'], 'no plan, no theme' );
        $this->assertNull( $rows[ $thursday ]['attendance_rate'], 'an upcoming training has no attendance yet' );
    }

    public function test_a_past_training_without_a_register_has_no_rate(): void {
        $tuesday = $this->training( '2026-11-03' );
        $this->assertNull( $this->rowsFor( '2026-11-04' )[ $tuesday ]['attendance_rate'] );
    }

    public function test_the_chips_render_as_neutral_facts(): void {
        $tuesday = $this->training( '2026-11-03' );
        $this->runPlan( $tuesday, 'pressing' );
        $this->record( $tuesday, [ 'Present', 'Present' ] );

        $method = new \ReflectionMethod( \TT\Shared\Frontend\FrontendTileGrid::class, 'renderWeekPanel' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, CoachWeek::forUser( $this->coach, '2026-11-04' ) );
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression( '#class="tt-week-chip tt-week-chip--neutral[^"]*">2 of 2 present<#', $html );
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_trainings(): void {
        // Week of 9 November: eight past trainings, each with a plan and a register. Week of 16 November: one.
        foreach ( [ '2026-11-09', '2026-11-09', '2026-11-10', '2026-11-10', '2026-11-11', '2026-11-11', '2026-11-12', '2026-11-12' ] as $date ) {
            $aid = $this->training( $date );
            $this->runPlan( $aid, 'pressing' );
            $this->record( $aid, [ 'Present', 'Late' ] );
        }
        $one = $this->training( '2026-11-16' );
        $this->runPlan( $one, 'pressing' );
        $this->record( $one, [ 'Present', 'Late' ] );

        $this->queriesFor( '2026-11-15' );
        $this->queriesFor( '2026-11-22' );
        $eight = $this->queriesFor( '2026-11-15' );
        $single = $this->queriesFor( '2026-11-22' );

        $this->assertSame( 8, $eight['rated'] );
        $this->assertSame( 1, $single['rated'] );
        $this->assertSame( count( $single['sql'] ), count( $eight['sql'] ), "one query per source, whatever the number of trainings. With eight:\n" . implode( "\n", $eight['sql'] ) );
    }

    public function test_both_blocks_are_switches_under_trainings_with_the_issue_defaults(): void {
        $method = new \ReflectionMethod( FrontendConfigurationView::class, 'renderCoachWeekConfig' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, [ 'head_coach' => 'Head coach' ] );
        $html = (string) ob_get_clean();
        foreach ( [ 'theme', 'att_rate' ] as $key ) {
            $this->assertStringContainsString( 'data-tt-coach-week-block="' . $key . '" data-tt-coach-week-device="desktop"', $html );
            $this->assertStringContainsString( 'data-tt-coach-week-block="' . $key . '" data-tt-coach-week-device="phone"', $html );
            $this->assertSame( 'training', WeekBlockRegistry::get( $key )->group() );
        }

        $defaults = CoachWeek::defaultSettings();
        $this->assertContains( 'theme', $defaults['head_coach']['desktop'] );
        $this->assertContains( 'att_rate', $defaults['head_coach']['desktop'] );
        $this->assertContains( 'theme', $defaults['assistant_coach']['desktop'] );
        $this->assertNotContains( 'att_rate', $defaults['assistant_coach']['desktop'] );
        foreach ( CoachWeek::PERSONAS as $persona ) {
            $this->assertNotContains( 'theme', $defaults[ $persona ]['phone'], $persona );
            $this->assertNotContains( 'att_rate', $defaults[ $persona ]['phone'], $persona );
        }
        foreach ( [ 'team_manager', 'head_of_development', 'academy_admin', 'scout', 'readonly_observer' ] as $persona ) {
            $this->assertNotContains( 'theme', $defaults[ $persona ]['desktop'], $persona );
            $this->assertNotContains( 'att_rate', $defaults[ $persona ]['desktop'], $persona );
        }
    }

    // Helpers

    /** @return array<int, array<string, mixed>> */
    private function rowsFor( string $today ): array {
        $rows = [];
        foreach ( CoachWeek::forUser( $this->coach, $today )['rows'] as $row ) $rows[ (int) $row['activity_id'] ] = $row;
        return $rows;
    }

    /** @return array{sql:list<string>, rated:int} */
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
        $rated = count( array_filter( $week['rows'], static fn( $r ) => $r['attendance_rate'] !== null && $r['theme'] !== null ) );
        return [ 'sql' => $sql, 'rated' => $rated ];
    }

    private function training( string $date ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'team_id' => $this->team, 'title' => 'Training', 'session_date' => $date,
            'start_time' => '18:30:00', 'activity_type_key' => 'training',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function runPlan( int $activity_id, string $theme ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_training_plans', [
            'club_id' => 1, 'uuid' => wp_generate_uuid4(), 'title' => 'Plan', 'team_id' => $this->team, 'theme_key' => $theme,
        ] );
        $plan_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_training_plan_runs', [
            'club_id' => 1, 'uuid' => wp_generate_uuid4(), 'plan_id' => $plan_id, 'activity_id' => $activity_id,
            'team_id' => $this->team, 'run_date' => '2026-11-03',
        ] );
    }

    /** @param list<string> $statuses one per fixture player, in order */
    private function record( int $activity_id, array $statuses ): void {
        global $wpdb;
        foreach ( $statuses as $i => $status ) {
            $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
                'club_id' => 1, 'activity_id' => $activity_id, 'player_id' => $this->players[ $i ],
                'status' => $status, 'record_type' => 'actual',
            ] );
        }
        ActivityRegisterProgress::forget();
    }
}
