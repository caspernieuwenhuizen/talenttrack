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

    // --- what the seat carries, and what has left it ---------------------

    /**
     * #3433 — the named successor to this test's measurement half.
     *
     * #3232 seeded `measurements [rc, team]` here as "the uncontroversial
     * half", and #3257 moved only the injuries, which left the defect one
     * entity short of fixed: `tt_staff` is still ONE persona covering
     * physio and kit manager, so a Staff account issued to move shirts
     * still read every player's growth curve. The grant moved onto the
     * Physio / Head coach / Assistant coach functional roles; its own
     * assertions live in `FunctionalRoleAccessTest`. What belongs here is
     * that the persona no longer carries it, so a future edit putting it
     * back has to argue with this rather than sail past a deleted test.
     */
    public function test_the_measurement_grant_has_left_the_staff_persona(): void {
        $rows = $this->seedRowsFor( 'staff' );

        $this->assertArrayNotHasKey(
            'measurements',
            $rows,
            'a Staff account issued to move shirts must not reach a minor\'s growth data'
        );
    }

    /**
     * #3257 — the named successor to this test's injury half.
     *
     * `player_injuries` was seeded here by #3232 and is not any more: it
     * moved onto the Physio functional role, because `tt_staff` is one
     * persona covering physio and kit manager and the matrix cannot
     * separate them. The grant's own assertions live in
     * `FunctionalRoleAccessTest`; what belongs here is that the persona
     * no longer carries it, so a future edit putting it back has to argue
     * with this rather than sail past a deleted test.
     */
    public function test_the_injury_grant_has_left_the_staff_persona(): void {
        $rows = $this->seedRowsFor( 'staff' );

        $this->assertArrayNotHasKey(
            'player_injuries',
            $rows,
            'a Staff account issued to move shirts must not reach medical data about minors'
        );
    }

    /**
     * Team scope, never global. A staff member sees the players they work
     * with, and nothing the seat still carries says otherwise.
     */
    public function test_every_remaining_staff_grant_is_team_or_self_scoped(): void {
        $scopes = $this->seedScopesFor( 'staff' );

        $this->assertNotSame( [], $scopes, 'the staff persona must still be seeded something' );

        foreach ( $scopes as $entity => $scope_kind ) {
            $this->assertContains(
                $scope_kind,
                [ 'team', 'self' ],
                sprintf( 'staff.%s is %s-scoped; a physio should not read the academy.', $entity, $scope_kind )
            );
        }
    }

    /**
     * Deleting a record about a minor is not a touchline decision, and that
     * stays with HoD / academy admin. Stated over the whole seat now that
     * the measurement row has left it, which is the wider claim anyway.
     */
    public function test_staff_holds_no_create_delete_at_all(): void {
        $rows = $this->seedRowsFor( 'staff' );

        foreach ( $rows as $entity => $activities ) {
            $this->assertNotContains(
                'create_delete',
                $activities,
                sprintf( 'staff.%s must not carry create_delete', $entity )
            );
        }
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
