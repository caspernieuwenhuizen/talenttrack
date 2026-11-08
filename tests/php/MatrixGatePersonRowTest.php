<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #4955 — `MatrixGate` reads a role scope from the person row every other
 * authorization read takes: the account's active, newest row in the current
 * club (`AuthorizationService::getPersonIdByUserId()`). It used to take the
 * first row the database returned, from any club, active or not.
 *
 * The staff persona holds `team` read at team scope only, so whether it
 * reaches a team is decided by the role scope on the person row alone.
 */
final class MatrixGatePersonRowTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Person row U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $this->flush();
    }

    public function tear_down(): void {
        $this->flush();
        parent::tear_down();
    }

    public function test_the_active_newest_row_decides_not_an_older_inactive_one(): void {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_staff' ] );

        // The older row, no longer active, still carries the team assignment.
        $old = $this->person( $user, $this->club, 'inactive' );
        $this->scope( $old, $this->team );
        // The newer, active row carries none.
        $new = $this->person( $user, $this->club, 'active' );
        $this->assertGreaterThan( $old, $new );
        $this->flush();

        $this->assertSame( [ 'staff' ], PersonaResolver::personasFor( $user ), 'Precondition: the account holds the staff persona.' );
        $this->assertFalse( MatrixGate::can( $user, 'team', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ), 'the inactive row grants nothing' );
        $this->assertFalse( MatrixGate::canAnyScope( $user, 'team', MatrixGate::READ ), 'nor at any scope' );
        $this->assertNull( MatrixGate::describeAccess( $user, 'team', MatrixGate::READ )['scope_value'] );
        $this->assertSame( [], MatrixGate::teamIdsFor( $user, 'team', MatrixGate::READ ) );

        // Move the assignment onto the active row: now it answers.
        $this->scope( $new, $this->team );
        $this->flush();
        $this->assertTrue( MatrixGate::can( $user, 'team', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ), 'the active row decides' );
        $this->assertTrue( MatrixGate::canAnyScope( $user, 'team', MatrixGate::READ ) );
        $this->assertSame( $this->team, MatrixGate::describeAccess( $user, 'team', MatrixGate::READ )['scope_value'] );
        $this->assertSame( [ $this->team ], MatrixGate::teamIdsFor( $user, 'team', MatrixGate::READ ) );
    }

    /** The source the acceptance criterion names: no person read by account id is left in the gate. */
    public function test_the_gate_reads_the_person_row_in_one_place(): void {
        $source = (string) file_get_contents( TT_PLUGIN_DIR . 'src/Modules/Authorization/MatrixGate.php' );
        $this->assertDoesNotMatchRegularExpression( '/tt_people\b[^;]*wp_user_id/s', $source );
        $this->assertStringContainsString( 'AuthorizationService::getPersonIdByUserId', $source );
    }

    public function test_a_person_row_of_another_club_is_not_read(): void {
        $user  = (int) self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $other = $this->person( $user, $this->club + 1000, 'active' );
        $this->scope( $other, $this->team, $this->club + 1000 );
        $this->flush();

        $this->assertFalse( MatrixGate::can( $user, 'team', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ), 'another club\'s person row grants nothing here' );
        $this->assertFalse( MatrixGate::canAnyScope( $user, 'team', MatrixGate::READ ) );
        $this->assertNull( MatrixGate::describeAccess( $user, 'team', MatrixGate::READ )['scope_value'] );
    }

    private function person( int $user_id, int $club, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Person', 'last_name' => 'Row' . $user_id,
            'role_type' => 'staff', 'wp_user_id' => $user_id, 'status' => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function scope( int $person_id, int $team_id, int $club = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $club > 0 ? $club : $this->club, 'person_id' => $person_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        PersonaResolver::clearCache();
        AuthorizationService::flushCache();
        wp_cache_flush();
    }
}
