<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\GenericCascadeDeleter;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\People\PersonDeletionCascade;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Teams\Admin\TeamsPage;

final class CascadeAuthFlushRedirected extends \Exception {}

/**
 * #4778 — the three paths that remove team staff or team scope rows outside
 * PeopleRepository flush the per-request authorization caches, and a deleted
 * team leaves no team scope row behind.
 */
final class CascadeAuthFlushTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $coach  = 0;
    private int $person = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p     = $wpdb->prefix;
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Cascadeflush U16' ] );
        $this->team  = (int) $wpdb->insert_id;
        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Cascadeflush', 'last_name' => 'Coach',
            'wp_user_id' => $this->coach, 'role_type' => 'staff', 'status' => 'active',
        ] );
        $this->person = (int) $wpdb->insert_id;
        ( new PeopleRepository() )->assignToTeam( $this->team, $this->person, $this->functionalRoleId( 'head_coach' ) );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        $_GET     = [];
        $_REQUEST = [];
        parent::tear_down();
    }

    public function test_the_person_deletion_cascade_is_seen_by_the_next_check(): void {
        $this->assertTrue( MatrixGate::canAnyScope( $this->coach, 'team', MatrixGate::READ ), 'precondition: the coach reads their team' );

        ( new PersonDeletionCascade() )->cascade( [ $this->person ] );

        $this->assertFalse( MatrixGate::canAnyScope( $this->coach, 'team', MatrixGate::READ ) );
    }

    public function test_the_team_cascade_is_seen_by_the_next_check(): void {
        $this->assertTrue( MatrixGate::canAnyScope( $this->coach, 'team', MatrixGate::READ ), 'precondition: the coach reads their team' );

        ( new GenericCascadeDeleter() )->cascade( 'team', [ $this->team ] );

        $this->assertSame( 0, $this->teamScopeRows() );
        $this->assertFalse( MatrixGate::canAnyScope( $this->coach, 'team', MatrixGate::READ ) );
    }

    public function test_the_wp_admin_team_delete_removes_the_scope_rows_and_is_seen(): void {
        $this->assertSame( 1, $this->teamScopeRows(), 'precondition: the assignment mirrored a scope row' );
        $this->assertTrue( MatrixGate::canAnyScope( $this->coach, 'team', MatrixGate::READ ), 'precondition: the coach reads their team' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $_GET     = [ 'id' => (string) $this->team, '_wpnonce' => wp_create_nonce( 'tt_delete_team_' . $this->team ) ];
        $_REQUEST = $_GET;

        $redirect = static function ( $location ): string {
            throw new CascadeAuthFlushRedirected( (string) $location );
        };
        add_filter( 'wp_redirect', $redirect );
        try {
            TeamsPage::handle_delete();
            $this->fail( 'the delete must end in a redirect' );
        } catch ( CascadeAuthFlushRedirected $e ) {
            $this->assertStringContainsString( 'tt_msg=deleted', $e->getMessage() );
        } finally {
            remove_filter( 'wp_redirect', $redirect );
        }

        $this->assertSame( 0, $this->teamScopeRows() );
        $this->assertFalse( MatrixGate::canAnyScope( $this->coach, 'team', MatrixGate::READ ) );
    }

    private function teamScopeRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_user_role_scopes WHERE scope_type = 'team' AND scope_id = %d",
            $this->team
        ) );
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
