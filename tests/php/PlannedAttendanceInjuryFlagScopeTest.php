<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\PlayerAvailability;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * On a training the "unavailable" flag can only mean an open injury, so it
 * is shown to a reader who plans the team or reads the player's injuries.
 * A club-wide reader without either, the Read-Only Observer, gets the
 * planned roster without it.
 */
final class PlannedAttendanceInjuryFlagScopeTest extends WP_UnitTestCase {

    private int $club     = 0;
    private int $team     = 0;
    private int $activity = 0;
    private int $injured  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Injflag U11', 'age_group' => 'U11' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Injflag training',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '+3 days' ) ),
            'activity_type_key' => 'training', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Injflag',
            'last_name' => 'Kidx', 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $this->injured = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->injured, 'fixture: the player row was written' );
        $wpdb->insert( "{$p}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $this->activity, 'player_id' => $this->injured,
            'is_guest' => 0, 'status' => 'Present', 'record_type' => 'expected',
        ] );
        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id' => $this->club, 'player_id' => $this->injured,
            'started_on' => gmdate( 'Y-m-d', (int) strtotime( '-7 days' ) ),
            'expected_return' => null, 'actual_return' => null, 'notes' => 'ankle',
        ] );
        $this->assertTrue( PlayerAvailability::isUnavailable( $this->injured ), 'precondition: the injury is open' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_observer_gets_the_roster_without_the_flag(): void {
        $this->as( self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] ) );
        $row = $this->row();

        $this->assertSame( 'expected', $row['plan_status'], 'precondition: the observer reads the roster' );
        $this->assertNull( $row['availability'] );
        $this->assertFalse( $row['availability_check'] );
    }

    public function test_the_observer_sees_no_flag_on_the_activity_page(): void {
        $this->as( self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] ) );
        $html = $this->card();

        $this->assertStringContainsString( 'Injflag', $html, 'precondition: the card lists the player' );
        $this->assertStringNotContainsString( 'tt-act-rp__unavailable', $html );
    }

    public function test_the_teams_coach_still_gets_the_flag(): void {
        $this->as( $this->coachOfTheTeam() );

        $this->assertSame( PlayerAvailability::UNAVAILABLE, $this->row()['availability'] );
        $this->assertStringContainsString( 'tt-act-rp__unavailable', $this->card() );
    }

    public function test_a_reader_of_the_players_injuries_still_gets_the_flag(): void {
        $this->as( self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) );

        $this->assertSame( PlayerAvailability::UNAVAILABLE, $this->row()['availability'] );
    }

    private function as( int $user_id ): void {
        wp_set_current_user( $user_id );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    /** @return array<string, mixed> */
    private function row(): array {
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance' ) );
        $this->assertSame( 200, $res->get_status() );
        foreach ( (array) ( $res->get_data()['data']['roster'] ?? [] ) as $row ) {
            $row = (array) $row;
            if ( (int) $row['player_id'] === $this->injured ) return $row;
        }
        $this->fail( 'the injured player is not on the roster' );
    }

    private function card(): string {
        global $wpdb;
        $session = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d", $this->activity ) );
        $render  = new \ReflectionMethod( FrontendActivitiesManageView::class, 'renderPlannedAttendance' );
        $render->setAccessible( true );
        ob_start();
        $render->invoke( null, $session );
        return (string) ob_get_clean();
    }

    private function coachOfTheTeam(): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Injflag', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        return $coach;
    }
}
