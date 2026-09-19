<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ActivitiesRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer;

/**
 * #3567 — the Manager functional role grants what a team manager does.
 *
 * It was seeded as "handles logistics, roster, activities" and granted
 * nothing: a Staff account assigned as Manager read the roster through its
 * persona and got 403 on the schedule, the availability board and the
 * register, while a Kit manager on the same team read the schedule.
 *
 * Decided 2026-09-19: read the schedule, record attendance, read player
 * status — team-scoped, no activity editing, no injuries. The negative
 * assertions (another team, writing activities, the injury log) carry as
 * much weight as the positive ones.
 */
final class ManagerFunctionalRoleTest extends WP_UnitTestCase {

    private int $team    = 0;
    private int $other   = 0;
    private int $manager = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O11-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O12-1' ] );
        $this->other = (int) $wpdb->insert_id;

        $this->manager = $this->makeStaffUser();
        $this->assign( $this->manager, $this->team, 'manager' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    // ── the gate ───────────────────────────────────────────────────────

    public function test_a_manager_reads_the_schedule_and_status_of_their_team_only(): void {
        foreach ( [ [ 'activities', 'read' ], [ 'attendance', 'read' ], [ 'attendance', 'change' ], [ 'player_status', 'read' ] ] as [ $entity, $activity ] ) {
            $this->assertTrue(
                MatrixGate::can( $this->manager, $entity, $activity, MatrixGate::SCOPE_TEAM, $this->team ),
                "Manager should hold {$entity}:{$activity} on their team"
            );
            $this->assertFalse(
                MatrixGate::can( $this->manager, $entity, $activity, MatrixGate::SCOPE_TEAM, $this->other ),
                "Manager must not hold {$entity}:{$activity} on another team"
            );
        }
    }

    public function test_a_manager_does_not_edit_activities(): void {
        $this->assertFalse( MatrixGate::can( $this->manager, 'activities', MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $this->team ) );
        $this->assertFalse( MatrixGate::can( $this->manager, 'activities', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM, $this->team ) );
    }

    public function test_the_injury_log_needs_physio_as_well(): void {
        $this->assertFalse(
            MatrixGate::can( $this->manager, 'player_injuries', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ),
            'Manager alone carries no medical access (#3257)'
        );

        $this->assign( $this->manager, $this->team, 'physio' );

        $this->assertTrue( MatrixGate::can( $this->manager, 'player_injuries', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ) );
    }

    public function test_the_attendance_question_admits_a_manager_and_not_a_kit_manager(): void {
        $this->assertTrue( AuthorizationService::canRecordAttendance( $this->manager ) );

        $kit = $this->makeStaffUser();
        $this->assign( $kit, $this->team, 'kit_manager' );
        $this->assertFalse( AuthorizationService::canRecordAttendance( $kit ) );
    }

    // ── the routes ─────────────────────────────────────────────────────

    public function test_the_schedule_lists_only_the_managers_team(): void {
        $mine   = $this->activity( $this->team );
        $theirs = $this->activity( $this->other );

        wp_set_current_user( $this->manager );
        $res = $this->get( '/talenttrack/v1/activities', [ 'per_page' => 100 ] );

        $this->assertSame( 200, $res->get_status() );
        $ids = array_map( static fn( $row ): int => (int) $row['id'], $res->get_data()['data']['rows'] ?? [] );
        $this->assertContains( $mine, $ids );
        $this->assertNotContains( $theirs, $ids );
    }

    public function test_the_availability_board_opens_for_their_team_only(): void {
        wp_set_current_user( $this->manager );

        $this->assertSame( 200, $this->get( '/talenttrack/v1/teams/' . $this->team . '/player-statuses' )->get_status() );
        $this->assertSame( 403, $this->get( '/talenttrack/v1/teams/' . $this->other . '/player-statuses' )->get_status() );
    }

    public function test_a_manager_may_take_the_register_but_not_create_an_activity(): void {
        wp_set_current_user( $this->manager );

        $this->assertTrue( ActivitiesRestController::can_edit_grid(), 'the attendance grid and POST attendance/bulk' );
        $this->assertFalse( ActivitiesRestController::can_edit(), 'POST / PUT activities stay with the coaches' );
    }

    // ── the unassigned staff account ───────────────────────────────────

    public function test_an_unassigned_staff_account_is_told_why_it_sees_nothing(): void {
        $this->assertStringContainsString( 'not assigned to a team yet', $this->notice( $this->makeStaffUser() ) );
    }

    public function test_an_assigned_staff_account_gets_no_notice(): void {
        $this->assertSame( '', $this->notice( $this->manager ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function makeStaffUser(): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Manager',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        return $uid;
    }

    private function personIdFor( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d LIMIT 1",
            $user_id
        ) );
    }

    private function functionalRoleId( string $role_key ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_functional_roles";
        $id    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE role_key = %s AND club_id = %d",
            $role_key, 1
        ) );
        if ( $id > 0 ) return $id;

        $wpdb->insert( $table, [
            'club_id'     => 1,
            'role_key'    => $role_key,
            'label'       => ucfirst( str_replace( '_', ' ', $role_key ) ),
            'description' => 'Created by ManagerFunctionalRoleTest.',
            'is_system'   => 1,
            'sort_order'  => 90,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The functional-role assignment plus the team-scope row that
     * `PeopleRepository::assignToTeam()` writes beside it.
     */
    private function assign( int $user_id, int $team_id, string $role_key ): void {
        global $wpdb;
        $person = $this->personIdFor( $user_id );

        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id'            => 1,
            'team_id'            => $team_id,
            'person_id'          => $person,
            'functional_role_id' => $this->functionalRoleId( $role_key ),
            'role_in_team'       => $role_key,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => $person,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
    }

    private function activity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $team_id,
            'title'               => 'Training',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+2 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $query */
    private function get( string $route, array $query = [] ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', $route );
        $req->set_query_params( $query );
        return rest_do_request( $req );
    }

    private function notice( int $user_id ): string {
        $method = new ReflectionMethod( PersonaLandingRenderer::class, 'renderUnassignedStaffNotice' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $user_id, 'staff' );
        return (string) ob_get_clean();
    }
}
