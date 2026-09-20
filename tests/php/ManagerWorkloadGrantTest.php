<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3808 — the team manager reads planned load for their own team, and
 * nothing else about VCT.
 *
 * They could already read `minutes` — what has been played — and nothing at
 * all about `vct_workload`, what is planned. The two halves of the
 * availability conversation sat in different rooms and the one in their
 * hands was paper.
 *
 * The negatives are the point. This is a read-only widening into a module
 * whose write side decides how hard children are worked, so the tests that
 * matter most are the ones asserting the manager still cannot plan.
 */
final class ManagerWorkloadGrantTest extends WP_UnitTestCase {

    private int $teamId  = 0;
    private int $otherId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Mgr Mine' ] );
        $this->teamId = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Mgr Theirs' ] );
        $this->otherId = (int) $wpdb->insert_id;
    }

    /** A team_manager persona user scoped to one team. */
    private function managerOn( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Manager',
            'role_type'  => 'team_manager',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        return $uid;
    }

    // -----------------------------------------------------------------
    // the grant exists and is team-scoped
    // -----------------------------------------------------------------

    public function test_the_seed_carries_both_reads(): void {
        $repo = new MatrixRepository();

        foreach ( [ 'vct_workload', 'training_exposure' ] as $entity ) {
            $this->assertTrue(
                $repo->lookup( 'team_manager', $entity, MatrixGate::READ, MatrixGate::SCOPE_TEAM ),
                "team_manager must hold {$entity} read at team scope"
            );
        }
    }

    public function test_a_manager_reads_their_own_teams_load(): void {
        $uid = $this->managerOn( $this->teamId );

        $this->assertTrue(
            MatrixGate::can( $uid, 'vct_workload', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->teamId )
        );
        $this->assertTrue(
            MatrixGate::can( $uid, 'training_exposure', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->teamId )
        );
    }

    public function test_a_manager_does_not_read_another_teams_load(): void {
        $uid = $this->managerOn( $this->teamId );

        $this->assertFalse(
            MatrixGate::can( $uid, 'vct_workload', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->otherId ),
            'the grant is team-scoped and must not reach a squad they do not manage'
        );
    }

    // -----------------------------------------------------------------
    // and nothing more than that
    // -----------------------------------------------------------------

    public function test_a_manager_cannot_plan(): void {
        $uid = $this->managerOn( $this->teamId );

        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, 'vct', MatrixGate::READ ),
            'tt_vct_plan bridges to vct:read — sessions, cycles and the schedule stay with the coach'
        );
        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, 'vct_library', MatrixGate::READ ),
            'macro-blocks and age profiles stay with the head of development'
        );
    }

    public function test_the_reads_are_read_only(): void {
        $uid = $this->managerOn( $this->teamId );

        foreach ( [ 'vct_workload', 'training_exposure' ] as $entity ) {
            $this->assertFalse(
                MatrixGate::can( $uid, $entity, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $this->teamId ),
                "{$entity} is a read grant; a manager may not write load"
            );
        }
    }

    /**
     * A team manager on this install may be the persona OR the `manager`
     * functional role on the `staff` persona — `functional_role_grants.php`
     * says so itself. The answer must not depend on which.
     */
    public function test_the_functional_role_carries_the_same_reads(): void {
        $grants = require dirname( __DIR__, 2 ) . '/config/functional_role_grants.php';

        $manager = $grants['grants']['manager'] ?? null;
        $this->assertIsArray( $manager, 'the manager functional role must still exist under grants' );

        foreach ( [ 'vct_workload', 'training_exposure' ] as $entity ) {
            $this->assertArrayHasKey(
                $entity,
                $manager,
                "the functional-role shape must grant {$entity} too, or the two shapes disagree"
            );
            $this->assertSame( 'r', $manager[ $entity ][0], "{$entity} must be read-only for the functional role" );
        }
    }

    /** Coach and HoD are untouched by this widening. */
    public function test_the_coach_is_unchanged(): void {
        $repo = new MatrixRepository();

        $this->assertTrue(
            $repo->lookup( 'head_coach', 'vct_workload', MatrixGate::READ, MatrixGate::SCOPE_TEAM ),
            'the head coach keeps the grant #3706 gave them'
        );
        $this->assertTrue(
            $repo->lookup( 'head_of_development', 'vct_library', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
            'the head of development keeps the library'
        );
    }
}
