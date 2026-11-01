<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\ActivitiesRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Wizards\Evaluation\AttendanceStep;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;
use TT\Modules\Wizards\Evaluation\EvaluationRowRestController;
use TT\Modules\Wizards\Evaluation\ReviewStep;
use TT\Modules\Wizards\MarkAttendance\MarkAttendanceWizard;

/**
 * The roles the tightened checks must keep working: a team manager takes
 * the register for the team they manage, the head of development evaluates
 * any player, and a coach rates a linked guest who played on their activity.
 */
final class RegisterAndEvaluationRolesTest extends WP_UnitTestCase {

    private int $own_team       = 0;
    private int $other_team     = 0;
    private int $own_activity   = 0;
    private int $other_activity = 0;
    private int $own_player     = 0;
    private int $other_player   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Rolekeep Own U10', 'age_group' => 'U10' ] );
        $this->own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Rolekeep Other U10', 'age_group' => 'U10' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->own_player     = $this->player( $this->own_team, 'Rolekeepown' );
        $this->other_player   = $this->player( $this->other_team, 'Rolekeepother' );
        $this->own_activity   = $this->activity( $this->own_team );
        $this->other_activity = $this->activity( $this->other_team );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_manager_takes_the_register_for_their_own_team(): void {
        $manager = $this->functionalRoleManager();
        wp_set_current_user( $manager );

        $this->assertTrue( ActivityAccess::canTakeRegister( $manager, $this->own_activity ) );
        $this->assertFalse( ActivityAccess::canTakeRegister( $manager, $this->other_activity ) );
        $this->assertFalse( ActivityAccess::canWriteActivity( $manager, $this->own_activity ), 'precondition: the manager does not plan the activity' );

        $grid = new \WP_REST_Request( 'POST', '/talenttrack/v1/attendance/bulk' );
        $grid->set_param( 'changes', [
            [ 'activity_id' => $this->own_activity, 'player_id' => $this->own_player, 'status' => 'present' ],
            [ 'activity_id' => $this->other_activity, 'player_id' => $this->other_player, 'status' => 'present' ],
        ] );
        $data = (array) ( ActivitiesRestController::bulk_attendance( $grid )->get_data()['data'] ?? [] );
        $this->assertSame( 1, (int) ( $data['saved'] ?? 0 ), 'the own team saves, the other is skipped' );
        $this->assertSame( [ $this->own_player ], $this->actualPlayers( $this->own_activity ) );
        $this->assertSame( [], $this->actualPlayers( $this->other_activity ) );
    }

    public function test_a_manager_takes_the_register_through_the_wizard(): void {
        $manager = $this->functionalRoleManager();
        wp_set_current_user( $manager );

        $this->assertSame( $this->own_activity, ( new MarkAttendanceWizard() )->initialState( [ 'activity_id' => $this->own_activity ] )['activity_id'] ?? 0 );
        ( new AttendanceStep() )->validate(
            [ 'attendance' => [ (string) $this->own_player => 'present' ] ],
            [ '_path' => 'activity-first', 'activity_id' => $this->own_activity ]
        );
        $this->assertSame( [ $this->own_player ], $this->actualPlayers( $this->own_activity ) );
    }

    public function test_the_head_of_development_evaluates_any_player(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        wp_set_current_user( $hod );
        AuthorizationService::flushCache();

        $this->assertTrue( EvaluationInserter::mayWriteFor( $hod, $this->other_player ) );

        $result = ( new ReviewStep() )->submit( [
            '_path' => 'player-first', 'player_id' => $this->other_player,
            'ratings_self' => [ 1 => 7 ], 'eval_date' => current_time( 'Y-m-d' ),
        ] );
        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 1, $this->evaluationsFor( $this->other_player ), 'the player-first path writes' );

        $this->assertSame( 201, $this->rowRoute( $this->own_player, 0 )->get_status(), 'the row route writes' );
        $this->assertSame( 1, $this->evaluationsFor( $this->own_player ) );
    }

    public function test_a_coach_rates_a_linked_guest_who_took_part(): void {
        $coach = $this->headCoachOf( $this->own_team );
        wp_set_current_user( $coach );
        $this->addGuest( $this->own_activity, $this->other_player );
        $stranger = $this->player( $this->other_team, 'Rolekeepstranger' );

        $this->assertFalse( EvaluationInserter::mayWriteFor( $coach, $this->other_player ), 'precondition: not the coach\'s own player' );

        ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->own_activity,
            'ratings' => [ $this->other_player => [ 1 => 7 ], $stranger => [ 1 => 7 ] ],
        ] );
        $this->assertSame( 1, $this->evaluationsFor( $this->other_player ), 'the guest who played is rated' );
        $this->assertSame( 0, $this->evaluationsFor( $stranger ), 'a player who was not there is not' );

        $this->assertSame( 201, $this->rowRoute( $this->other_player, $this->own_activity )->get_status(), 'the row route rates the guest too' );
    }

    public function test_a_guest_only_planned_for_the_activity_is_not_rated(): void {
        $coach = $this->headCoachOf( $this->own_team );
        wp_set_current_user( $coach );
        ( new \TT\Modules\Activities\Repositories\AttendanceWriter() )->planExpected( [
            'club_id' => 1, 'activity_id' => $this->own_activity, 'player_id' => $this->other_player,
            'is_guest' => 0, 'status' => 'Present',
        ] );

        $this->assertFalse( EvaluationInserter::mayWriteForActivity( $coach, $this->other_player, $this->own_activity ) );
        $this->assertSame( 403, $this->rowRoute( $this->other_player, $this->own_activity )->get_status() );
    }

    public function test_a_coach_is_not_admitted_on_another_teams_or_a_teamless_activity(): void {
        global $wpdb;
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        $teamless = $this->activity( 0 );
        $guest    = $this->player( $this->other_team, 'Rolekeepvisitor' );
        $this->addGuest( $this->other_activity, $guest );
        $this->addGuest( $teamless, $guest );
        $this->assertContains( $guest, \TT\Modules\Activities\Repositories\AttendanceWriter::attendedPlayerIds( $teamless ), 'precondition: the guest took part' );

        $coach = $this->headCoachOf( $this->own_team );
        wp_set_current_user( $coach );
        $this->assertFalse( EvaluationInserter::mayWriteForActivity( $coach, $guest, $this->other_activity ) );
        $this->assertFalse( EvaluationInserter::mayWriteForActivity( $coach, $guest, $teamless ) );
        ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $teamless, 'ratings' => [ $guest => [ 1 => 7 ] ],
        ] );
        $this->assertSame( 0, $this->evaluationsFor( $guest ) );

    }

    public function test_a_manager_who_takes_the_register_cannot_rate(): void {
        $manager = $this->functionalRoleManager();
        wp_set_current_user( $manager );
        ( new AttendanceStep() )->validate(
            [ 'attendance' => [ (string) $this->own_player => 'present' ] ],
            [ '_path' => 'activity-first', 'activity_id' => $this->own_activity ]
        );
        $this->assertSame( [ $this->own_player ], $this->actualPlayers( $this->own_activity ), 'precondition: the manager took the register' );

        $this->assertFalse( EvaluationInserter::mayWriteForActivity( $manager, $this->own_player, $this->own_activity ) );
        ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->own_activity, 'ratings' => [ $this->own_player => [ 1 => 7 ] ],
        ] );
        $this->assertSame( 0, $this->evaluationsFor( $this->own_player ) );
    }

    /** A linked guest, added the way the activity screen adds one. */
    private function addGuest( int $activity_id, int $player_id ): void {
        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/activities/' . $activity_id . '/guests' );
        $request->set_param( 'id', $activity_id );
        $request->set_param( 'guest_player_id', $player_id );
        $request->set_param( 'status', 'present' );
        $response = rest_ensure_response( ActivitiesRestController::add_guest( $request ) );
        $this->assertLessThan( 300, $response->get_status(), 'fixture: the guest was added' );
    }

    private function rowRoute( int $player_id, int $activity_id ): \WP_REST_Response {
        $body = [ 'player_id' => $player_id, 'eval_date' => current_time( 'Y-m-d' ), 'ratings' => [ 1 => 7 ] ];
        if ( $activity_id > 0 ) $body['activity_id'] = $activity_id;
        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/wizards/new-evaluation/insert-row' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        return EvaluationRowRestController::insert( $request );
    }

    private function functionalRoleManager(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Rolekeep', 'last_name' => 'Managerx',
            'role_type' => 'staff', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d", 'manager', 1
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id' => 1, 'role_key' => 'manager', 'label' => 'Manager',
                'description' => 'Created by RegisterAndEvaluationRolesTest.', 'is_system' => 1, 'sort_order' => 90,
            ] );
            $role_id = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $this->own_team, 'person_id' => $person,
            'functional_role_id' => $role_id, 'role_in_team' => 'manager',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->own_team,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        MatrixRepository::clearCache();
        return $uid;
    }

    private function headCoachOf( int $team_id ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Rolekeep', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team_id, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $coach;
    }

    /** @return list<int> */
    private function actualPlayers( int $activity_id ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT player_id FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d AND record_type = 'actual' ORDER BY player_id",
            $activity_id
        ) ) );
    }

    private function evaluationsFor( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE player_id = %d", $player_id
        ) );
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 1, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Kidx',
            'status' => 'active', 'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }

    private function activity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => 1, 'team_id' => $team_id, 'title' => 'Rolekeep training',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-2 days' ) ),
            'activity_type_key' => 'training', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }
}
