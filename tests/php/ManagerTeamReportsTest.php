<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\TeamsRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #4259 (D-7) — a team manager reads the team reports for their own team.
 *
 * The minutes share, the monthly team report and its PDF all ask
 * `TeamReportAccess`, which needs `reports: read` at global or team scope.
 * The grant goes through the matrix only: the `team_manager` persona row in
 * the seed (plus migration 0301) and the `manager` functional role in
 * `functional_role_grants.php`. Both shapes are asserted, as in
 * `ManagerAttendanceAnalyticsTest`, because a team manager on an install may
 * be either.
 */
final class ManagerTeamReportsTest extends WP_UnitTestCase {

    private int $myTeam    = 0;
    private int $otherTeam = 0;
    private int $myPlayer  = 0;
    private int $hisPlayer = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O11-1' ] );
        $this->myTeam = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O17-2' ] );
        $this->otherTeam = (int) $wpdb->insert_id;

        $this->myPlayer  = $this->seedPlayedMinutes( $this->myTeam, '2026-03-14' );
        $this->hisPlayer = $this->seedPlayedMinutes( $this->otherTeam, '2026-03-15' );

        TeamsRestController::init();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ----

    /** One played match with one player's minutes on the register; returns the player. */
    private function seedPlayedMinutes( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'team_id'    => $team_id,
            'first_name' => 'Speler',
            'last_name'  => 'Team ' . $team_id,
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $team_id,
            'title'               => 'Wedstrijd ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'game',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
            'opponent'            => 'Ajax',
            'home_away'           => 'home',
        ] );
        $activity_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'        => 1,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'status'         => 'present',
            'minutes_played' => 60,
        ] );
        return $player_id;
    }

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
            'description' => 'Created by ManagerTeamReportsTest.',
            'is_system'   => 1,
            'sort_order'  => 90,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** The `team_manager` persona, from the `tt_team_manager` role. */
    private function makePersonaManager(): int {
        if ( get_role( 'tt_team_manager' ) === null ) {
            add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        }
        $uid = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $this->scopeToTeam( $this->makePerson( $uid, 'team_manager' ), $this->myTeam );
        AuthorizationService::flushCache();
        return $uid;
    }

    /** A `staff` account holding the Manager functional role: the reported shape. */
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

    private function get( int $user_id, string $route ): int {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1' . $route );
        $req->set_param( 'from', '2026-03-01' );
        $req->set_param( 'to', '2026-03-31' );
        return rest_get_server()->dispatch( $req )->get_status();
    }

    // ---- own team: open ----

    public function test_the_persona_manager_reads_their_own_teams_minutes_share(): void {
        $uid = $this->makePersonaManager();

        $this->assertSame( 200, $this->get( $uid, '/teams/' . $this->myTeam . '/minutes-share' ) );
        $this->assertSame( 200, $this->get( $uid, '/teams/' . $this->myTeam . '/minutes-share/' . $this->myPlayer ) );
    }

    public function test_the_functional_role_manager_reads_their_own_teams_minutes_share(): void {
        $uid = $this->makeFunctionalRoleManager();

        $this->assertSame( 200, $this->get( $uid, '/teams/' . $this->myTeam . '/minutes-share' ) );
        $this->assertSame( 200, $this->get( $uid, '/teams/' . $this->myTeam . '/minutes-share/' . $this->myPlayer ) );
    }

    /** The monthly team report and its PDF ask the same rule; the owner accepted that. */
    public function test_both_shapes_may_read_their_own_teams_reports(): void {
        $this->assertTrue( TeamReportAccess::canRead( $this->makePersonaManager(), $this->myTeam ) );
        $this->assertTrue( TeamReportAccess::canRead( $this->makeFunctionalRoleManager(), $this->myTeam ) );
    }

    // ---- another team: still refused ----

    public function test_neither_manager_reads_a_team_they_do_not_manage(): void {
        foreach ( [ $this->makePersonaManager(), $this->makeFunctionalRoleManager() ] as $uid ) {
            $this->assertSame( 403, $this->get( $uid, '/teams/' . $this->otherTeam . '/minutes-share' ) );
            $this->assertSame( 403, $this->get( $uid, '/teams/' . $this->otherTeam . '/minutes-share/' . $this->hisPlayer ) );
            $this->assertFalse( TeamReportAccess::canRead( $uid, $this->otherTeam ) );
        }
    }

    public function test_a_plain_staff_account_is_still_refused(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $this->scopeToTeam( $this->makePerson( $uid, 'staff' ), $this->myTeam );
        AuthorizationService::flushCache();

        $this->assertFalse(
            TeamReportAccess::canRead( $uid, $this->myTeam ),
            'the grant follows the Manager role, not the staff persona'
        );
    }

    // ---- the grant lives in the matrix ----

    public function test_the_functional_role_carries_the_reports_read(): void {
        $grants  = require dirname( __DIR__, 2 ) . '/config/functional_role_grants.php';
        $manager = $grants['grants']['manager'] ?? null;

        $this->assertIsArray( $manager );
        $this->assertArrayHasKey( 'reports', $manager );
        $this->assertSame( 'r', $manager['reports'][0] );
    }

    public function test_the_migration_gives_an_existing_install_the_grant(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'persona' => 'team_manager', 'entity' => 'reports' ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0301_authorization_seed_topup_manager_reports.php';
        $migration->up();
        // Twice, because a re-run must add nothing.
        $migration->up();

        $rows = $wpdb->get_results(
            "SELECT activity, scope_kind FROM {$table}
              WHERE persona = 'team_manager' AND entity = 'reports'",
            ARRAY_A
        );
        $this->assertSame( [ [ 'activity' => 'read', 'scope_kind' => 'team' ] ], $rows );
    }
}
