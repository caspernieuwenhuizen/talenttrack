<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Wizards\Team\ReviewStep;

/**
 * #4729 — a head coach set in the new-team wizard coaches the new team at
 * once: the assignment carries its team scope row, so the coach's team
 * list and the matrix team-scope check both include the team.
 */
final class TeamWizardStaffScopeTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->ensureFunctionalRole( 'head_coach' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_wizards_head_coach_coaches_the_new_team(): void {
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $result = ( new ReviewStep() )->submit( [
            'name'             => 'Wizscope U15',
            'age_group'        => 'U15',
            'staff_head_coach' => $coach,
        ] );
        $this->assertIsArray( $result, 'precondition: the team is created' );
        $team_id = $this->teamId( 'Wizscope U15' );
        $this->assertGreaterThan( 0, $team_id );

        $teams = array_map( static fn( $t ) => (int) $t->id, QueryHelpers::get_teams_for_coach( $coach ) );
        $this->assertContains( $team_id, $teams );
        $this->assertTrue( MatrixGate::can( $coach, 'team', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $team_id ) );
    }

    public function test_the_assignment_is_written_once_with_the_head_coach_flag(): void {
        global $wpdb;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        ( new ReviewStep() )->submit( [ 'name' => 'Wizscope U16', 'staff_head_coach' => $coach ] );
        $team_id = $this->teamId( 'Wizscope U16' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT is_head_coach, role_in_team FROM {$wpdb->prefix}tt_team_people WHERE team_id = %d",
            $team_id
        ) );
        $this->assertCount( 1, $rows );
        $this->assertSame( 1, (int) $rows[0]->is_head_coach );
        $this->assertSame( 'head_coach', (string) $rows[0]->role_in_team );
    }

    private function teamId( string $name ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_teams WHERE name = %s ORDER BY id DESC LIMIT 1",
            $name
        ) );
    }

    private function ensureFunctionalRole( string $role_key ): void {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = 1 LIMIT 1",
            $role_key
        ) );
        if ( $id > 0 ) return;
        $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [
            'club_id'  => 1,
            'role_key' => $role_key,
            'label'    => ucwords( str_replace( '_', ' ', $role_key ) ),
        ] );
    }
}
