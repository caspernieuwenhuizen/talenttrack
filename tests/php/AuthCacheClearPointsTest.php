<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #4730 — two writes that change an authorization answer also clear the
 * per-request answers, so the next check in the same request sees them:
 * removing a coach's last team assignment, and switching a module off.
 */
final class AuthCacheClearPointsTest extends WP_UnitTestCase {

    private string $module = '';

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        if ( $this->module !== '' ) {
            ModuleRegistry::setEnabled( $this->module, true );
        }
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_unassigning_the_last_team_is_seen_by_the_next_check(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Cacheclear U14' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Cacheclear', 'last_name' => 'Coach',
            'wp_user_id' => $coach, 'role_type' => 'staff', 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $people = new PeopleRepository();
        $this->assertTrue( $people->assignToTeam( $team, $person, $this->functionalRoleId( 'head_coach' ) ), 'precondition: assigned' );
        $this->assertTrue( MatrixGate::canAnyScope( $coach, 'team', MatrixGate::READ ), 'precondition: the coach reads their team' );

        $assignment = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_team_people WHERE team_id = %d AND person_id = %d",
            $team, $person
        ) );
        $this->assertTrue( $people->unassign( $assignment ) );

        $this->assertFalse( MatrixGate::canAnyScope( $coach, 'team', MatrixGate::READ ) );
    }

    /** The scope row survives (another role on the team remains), the personas still change. */
    public function test_unassigning_head_coach_with_an_assistant_row_left_drops_the_persona(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Cacheclear Dual U13' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Cacheclear', 'last_name' => 'Dual',
            'wp_user_id' => $coach, 'role_type' => 'staff', 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $people = new PeopleRepository();
        $this->assertTrue( $people->assignToTeam( $team, $person, $this->functionalRoleId( 'head_coach' ) ) );
        $this->assertTrue( $people->assignToTeam( $team, $person, $this->functionalRoleId( 'assistant_coach' ) ) );
        $personas = PersonaResolver::personasFor( $coach );
        sort( $personas );
        $this->assertSame( [ 'assistant_coach', 'head_coach' ], $personas, 'precondition: both personas' );

        $head_row = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_team_people WHERE team_id = %d AND person_id = %d AND is_head_coach = 1",
            $team, $person
        ) );
        $this->assertTrue( $people->unassign( $head_row ) );

        $this->assertSame( [ 'assistant_coach' ], PersonaResolver::personasFor( $coach ) );
    }

    /** The importer and the demo generator write tt_team_people themselves and then sync. */
    public function test_a_direct_scope_sync_removal_is_seen_by_the_next_check(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Cacheclear Import U15' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Cacheclear', 'last_name' => 'Imported',
            'wp_user_id' => $coach, 'role_type' => 'staff', 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team, 'person_id' => $person,
            'functional_role_id' => $this->functionalRoleId( 'head_coach' ),
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        PeopleRepository::syncTeamScopeRow( $team, $person );
        AuthorizationService::flushCache();
        $this->assertTrue( MatrixGate::canAnyScope( $coach, 'team', MatrixGate::READ ), 'precondition: the coach reads their team' );

        $wpdb->delete( "{$p}tt_team_people", [ 'team_id' => $team, 'person_id' => $person ] );
        PeopleRepository::syncTeamScopeRow( $team, $person );

        $this->assertFalse( MatrixGate::canAnyScope( $coach, 'team', MatrixGate::READ ) );
    }

    public function test_switching_a_module_off_is_seen_by_the_next_check(): void {
        global $wpdb;
        $user = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $this->module = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT module_class FROM {$wpdb->prefix}tt_authorization_matrix WHERE entity = %s LIMIT 1",
            'tournaments'
        ) );
        $this->assertNotSame( '', $this->module, 'precondition: the entity has an owning module' );
        $this->assertFalse( ModuleRegistry::isAlwaysOn( $this->module ), 'precondition: the module can be switched off' );
        ModuleRegistry::setEnabled( $this->module, true );
        $this->assertTrue( MatrixGate::canAnyScope( $user, 'tournaments', MatrixGate::CREATE_DELETE ), 'precondition: allowed while the module is on' );

        ModuleRegistry::setEnabled( $this->module, false );

        $this->assertFalse( MatrixGate::canAnyScope( $user, 'tournaments', MatrixGate::CREATE_DELETE ) );
    }

    private function functionalRoleId( string $role_key ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = 1 LIMIT 1",
            $role_key
        ) );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [
            'club_id'  => 1,
            'role_key' => $role_key,
            'label'    => ucwords( str_replace( '_', ' ', $role_key ) ),
        ] );
        return (int) $wpdb->insert_id;
    }
}
