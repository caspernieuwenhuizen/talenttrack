<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ReportsRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3770 — the attendance-at-risk list is the one surface that answers
 * "which of my players keep missing training", and a team manager was
 * refused it for their own squad.
 *
 * The three attendance report routes gate on `tt_view_analytics`, which
 * bridges to `analytics: read`, and the seed granted that to head of
 * development and academy admin only. Nothing in the controller was
 * wrong: `attendanceScope()` below the gate already narrows a non-admin
 * to `get_teams_for_coach()`.
 *
 * A team manager is one of two shapes on this install — the
 * `team_manager` persona from the `tt_team_manager` role, or a `staff`
 * account holding the Manager functional role on a squad (which is the
 * shape the bug was reported from). Both are asserted here, because the
 * report should not depend on which one an academy happened to use.
 */
final class ManagerAttendanceAnalyticsTest extends WP_UnitTestCase {

    private int $myTeam    = 0;
    private int $otherTeam = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O11-1' ] );
        $this->myTeam = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O17-2' ] );
        $this->otherTeam = (int) $wpdb->insert_id;

        // Through the action, not register() directly: WordPress raises a
        // doing_it_wrong notice for routes registered outside rest_api_init.
        ReportsRestController::init();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function makePerson( int $user_id, string $role_type ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Kees',
            'last_name'  => 'Teammanager',
            'role_type'  => $role_type,
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** The team scope `attendanceScope()` reads through `get_teams_for_coach()`. */
    private function scopeToTeam( int $person_id, int $team_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }

    private function functionalRoleId( string $role_key ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_functional_roles";

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE role_key = %s AND club_id = %d",
            $role_key, 1
        ) );
        if ( $id > 0 ) return $id;

        $wpdb->insert( $table, [
            'club_id'     => 1,
            'role_key'    => $role_key,
            'label'       => ucfirst( $role_key ),
            'description' => 'Created by ManagerAttendanceAnalyticsTest.',
            'is_system'   => 1,
            'sort_order'  => 90,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The `team_manager` persona, from the `tt_team_manager` WordPress role.
     *
     * The role is added by migration 0030 rather than by `RolesService`, so
     * it is created here when absent — a user created against a role that
     * does not exist holds no role at all, resolves to no persona, and the
     * assertion below would pass for the wrong reason.
     */
    private function makePersonaManager(): int {
        if ( get_role( 'tt_team_manager' ) === null ) {
            add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        }
        $uid = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $this->scopeToTeam( $this->makePerson( $uid, 'team_manager' ), $this->myTeam );
        AuthorizationService::flushCache();
        return $uid;
    }

    /** A `staff` account holding the Manager functional role — the reported shape. */
    private function makeFunctionalRoleManager(): int {
        global $wpdb;

        $uid       = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $person_id = $this->makePerson( $uid, 'staff' );

        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id'            => 1,
            'team_id'            => $this->myTeam,
            'person_id'          => $person_id,
            'functional_role_id' => $this->functionalRoleId( 'manager' ),
            'role_in_team'       => 'manager',
        ] );
        $this->scopeToTeam( $person_id, $this->myTeam );

        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        return $uid;
    }

    private function atRisk( int $user_id, int $team_id ): \WP_REST_Response {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/attendance-at-risk' );
        $req->set_param( 'team_id', $team_id );
        return rest_get_server()->dispatch( $req );
    }

    // ── the report answers for a manager's own team ────────────────────

    public function test_the_persona_manager_reads_their_own_teams_at_risk_list(): void {
        $this->assertSame( 200, $this->atRisk( $this->makePersonaManager(), $this->myTeam )->get_status() );
    }

    public function test_the_functional_role_manager_reads_their_own_teams_at_risk_list(): void {
        $this->assertSame(
            200,
            $this->atRisk( $this->makeFunctionalRoleManager(), $this->myTeam )->get_status(),
            'the reported account is a staff seat holding the Manager functional role'
        );
    }

    // ── and stops at the squad they hold ───────────────────────────────

    public function test_neither_manager_reads_a_team_they_do_not_manage(): void {
        $this->assertSame( 403, $this->atRisk( $this->makePersonaManager(), $this->otherTeam )->get_status() );
        $this->assertSame( 403, $this->atRisk( $this->makeFunctionalRoleManager(), $this->otherTeam )->get_status() );
    }

    public function test_the_two_sibling_attendance_routes_answer_the_same_way(): void {
        $uid = $this->makePersonaManager();
        wp_set_current_user( $uid );

        foreach ( [ '/reports/attendance', '/reports/attendance-leaderboard' ] as $route ) {
            $own = new WP_REST_Request( 'GET', '/talenttrack/v1' . $route );
            $own->set_param( 'team_id', $this->myTeam );
            $this->assertSame( 200, rest_get_server()->dispatch( $own )->get_status(), $route );

            $theirs = new WP_REST_Request( 'GET', '/talenttrack/v1' . $route );
            $theirs->set_param( 'team_id', $this->otherTeam );
            $this->assertSame( 403, rest_get_server()->dispatch( $theirs )->get_status(), $route );
        }
    }

    // ── a seat with no manager grant is still refused ──────────────────

    public function test_a_plain_staff_account_is_still_refused(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $this->scopeToTeam( $this->makePerson( $uid, 'staff' ), $this->myTeam );
        AuthorizationService::flushCache();

        $this->assertNotSame(
            200,
            $this->atRisk( $uid, $this->myTeam )->get_status(),
            'the grant follows the Manager role, not the staff persona'
        );
    }

    // ── the top-up, for installs that already ran the seed ─────────────

    public function test_the_migration_gives_an_existing_install_the_grant(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'persona' => 'team_manager', 'entity' => 'analytics' ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0277_authorization_seed_topup_manager_analytics.php';
        $migration->up();
        // Twice, because a re-run must add nothing.
        $migration->up();

        $rows = $wpdb->get_results(
            "SELECT activity, scope_kind FROM {$table}
              WHERE persona = 'team_manager' AND entity = 'analytics'",
            ARRAY_A
        );
        $this->assertSame( [ [ 'activity' => 'read', 'scope_kind' => 'team' ] ], $rows );
    }
}
