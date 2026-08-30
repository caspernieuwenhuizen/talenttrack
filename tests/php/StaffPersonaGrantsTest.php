<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;

/**
 * #3232 — the Staff seat does a physio's job, and stops doing an
 * academy admin's.
 *
 * Two halves that look separate and are not. Fixing either alone leaves
 * the role worse shaped than before: strip the over-grant and a physio
 * loses the test-setup wizard while gaining nothing; grant the medical
 * rows and the academy-admin surface stays reachable on matrix-inactive
 * installs.
 */
final class StaffPersonaGrantsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
    }

    /** @return array<string, list<string>> entity => activities */
    private function seedRowsFor( string $persona ): array {
        $seed = require TT_PLUGIN_DIR . 'config/authorization_seed.php';

        $out = [];
        foreach ( $seed as $row ) {
            if ( ( $row['persona'] ?? '' ) !== $persona ) continue;
            $out[ (string) $row['entity'] ][] = (string) $row['activity'];
        }
        return $out;
    }

    /** @return array<string, string> entity => scope_kind */
    private function seedScopesFor( string $persona ): array {
        $seed = require TT_PLUGIN_DIR . 'config/authorization_seed.php';

        $out = [];
        foreach ( $seed as $row ) {
            if ( ( $row['persona'] ?? '' ) !== $persona ) continue;
            $out[ (string) $row['entity'] ] = (string) $row['scope_kind'];
        }
        return $out;
    }

    // --- the under-grant, now fixed --------------------------------------

    public function test_staff_can_read_and_change_measurements_and_injuries(): void {
        $rows = $this->seedRowsFor( 'staff' );

        foreach ( [ 'measurements', 'player_injuries' ] as $entity ) {
            $this->assertArrayHasKey( $entity, $rows, "staff needs a {$entity} row to do a physio's job" );
            $this->assertContains( 'read', $rows[ $entity ] );
            $this->assertContains( 'change', $rows[ $entity ] );
        }
    }

    /**
     * Team scope, never global. A physio sees the players they work with —
     * this is half of what makes the wider injury grant acceptable while
     * the physio / kit-manager split (#3257) does not exist.
     */
    public function test_the_medical_grants_are_team_scoped(): void {
        $scopes = $this->seedScopesFor( 'staff' );

        $this->assertSame( 'team', $scopes['measurements'] ?? '' );
        $this->assertSame( 'team', $scopes['player_injuries'] ?? '' );
    }

    /**
     * Deleting a minor's medical record is not a touchline decision, and
     * that stays with HoD / academy admin.
     */
    public function test_staff_cannot_delete_measurements_or_injuries(): void {
        $rows = $this->seedRowsFor( 'staff' );

        $this->assertNotContains( 'create_delete', $rows['measurements'] ?? [] );
        $this->assertNotContains( 'create_delete', $rows['player_injuries'] ?? [] );
    }

    // --- the over-grant, now removed -------------------------------------

    /**
     * `tt_manage_players` is not "manage the roster": it gates season
     * rollover, player login accounts, install-wide custom-field
     * definitions and player deletion. It does not belong on a physio.
     */
    public function test_the_staff_role_no_longer_grants_manage_players(): void {
        $role = get_role( 'tt_staff' );

        $this->assertNotNull( $role );
        $this->assertFalse(
            (bool) ( $role->capabilities['tt_manage_players'] ?? false ),
            'tt_manage_players reaches season rollover and player deletion'
        );
    }

    /**
     * #3177's assertion, still true. Nothing here grants it, and if a
     * future change needs it, this is the thing to argue with rather than
     * delete.
     */
    public function test_staff_still_holds_no_players_create_delete(): void {
        $rows = $this->seedRowsFor( 'staff' );

        $this->assertNotContains( 'create_delete', $rows['players'] ?? [] );
    }

    // --- what made the removal safe --------------------------------------

    /**
     * The one useful thing the over-grant reached was the "+ New test"
     * wizard, and it now asks about the entity it is actually setting up.
     * Without this the removal would have cost a physio the test catalogue
     * for nothing.
     */
    public function test_the_measurement_wizard_asks_about_definitions(): void {
        $this->assertSame(
            'tt_manage_measurement_definitions',
            ( new \TT\Modules\Measurements\Wizards\NewMeasurementWizard() )->requiredCap()
        );
    }

    /**
     * And the roles that reached that wizard before still reach it — on a
     * matrix-INACTIVE install too, which is why the cap is granted raw and
     * not left matrix-only.
     */
    public function test_the_test_catalogue_cap_is_granted_to_the_roles_that_had_it(): void {
        foreach ( [ 'tt_head_dev', 'tt_club_admin' ] as $slug ) {
            $role = get_role( $slug );
            $this->assertNotNull( $role, "{$slug} should exist" );
            $this->assertTrue(
                (bool) ( $role->capabilities['tt_manage_measurement_definitions'] ?? false ),
                "{$slug} reached the test wizard before and must still"
            );
        }
    }

    /** Staff is not quietly given the test catalogue on the way past. */
    public function test_staff_does_not_get_the_test_catalogue_cap(): void {
        $role = get_role( 'tt_staff' );

        $this->assertNotNull( $role );
        $this->assertFalse( (bool) ( $role->capabilities['tt_manage_measurement_definitions'] ?? false ) );
    }
}
