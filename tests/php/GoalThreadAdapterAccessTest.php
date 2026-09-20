<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Threads\Adapters\GoalThreadAdapter;

/**
 * #3720 — the goal thread's academy-wide rung used to test
 * `tt_view_settings`, a capability the `head_of_development` persona is
 * granted nowhere, so the Head of Development got a 403 on every goal
 * conversation for a player they don't personally coach.
 *
 * Both cases below go through the authorization matrix, never through a
 * hand-granted capability: a test that added `tt_view_settings` to the
 * user would have passed while the bug stood.
 */
final class GoalThreadAdapterAccessTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // TT WP roles are installed on plugin activation, which doesn't
        // fire in the wp-env bootstrap. PersonaResolver maps the roles.
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        $repo->removeRow( 'head_of_development', 'goals', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );
        $repo->removeRow( 'scout', 'goals', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    /**
     * A goal on a player nobody in this test coaches, authored by a user
     * who is not the subject of either assertion.
     */
    private function seed_goal(): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_players", [
            'first_name' => 'Thread',
            'last_name'  => 'Subject',
            'club_id'    => CurrentClub::id(),
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $player_id );

        $wpdb->insert( "{$p}tt_goals", [
            'player_id'  => $player_id,
            'title'      => 'Win more duels',
            'status'     => 'pending',
            'created_by' => 999001,
            'club_id'    => CurrentClub::id(),
        ] );
        $goal_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $goal_id );

        // Guard: the adapter resolves the goal by id + club, and every
        // assertion below is meaningless if it can't find the row.
        $this->assertNotNull(
            ( new GoalThreadAdapter() )->findEntity( $goal_id ),
            'the fixture goal must be visible to the adapter'
        );

        return $goal_id;
    }

    public function test_head_of_development_reads_and_posts_via_the_matrix(): void {
        $goal_id = $this->seed_goal();

        $uid = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->assertContains(
            'head_of_development',
            PersonaResolver::effectivePersonas( $uid ),
            'tt_head_dev must resolve to the head_of_development persona'
        );
        $this->assertFalse(
            user_can( $uid, 'tt_view_settings' ) && user_can( $uid, 'tt_edit_settings' ),
            'the HoD must not reach the thread through a settings capability'
        );

        // The academy-wide goals read the seed grants HoD. Rewritten
        // here rather than relied upon: `setRow` leaves an existing
        // row's `module_class` alone, and a row carrying one is only
        // granted while that module reports enabled — which is about
        // the test install, not about this adapter. Removing first
        // pins the row to the empty module_class MatrixGateScopeTest
        // uses, so the assertion is about authorization only.
        $repo = new MatrixRepository();
        $repo->removeRow( 'head_of_development', 'goals', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );
        $repo->setRow( 'head_of_development', 'goals', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL, '' );
        MatrixRepository::clearCache();

        // Guard: separates "the matrix doesn't grant it" from "the
        // adapter doesn't ask the matrix", which is the actual bug.
        $this->assertTrue(
            QueryHelpers::user_has_global_entity_read( $uid, 'goals' ),
            'the matrix must resolve an academy-wide goals read for the HoD'
        );

        $adapter = new GoalThreadAdapter();

        $this->assertTrue(
            $adapter->canRead( $uid, $goal_id ),
            'a globally-scoped goals reader reads any goal thread'
        );
        $this->assertTrue(
            $adapter->canPost( $uid, $goal_id ),
            'the HoD holds tt_edit_goals, so they also write in it'
        );
    }

    public function test_global_reader_without_edit_goals_reads_but_cannot_post(): void {
        $goal_id = $this->seed_goal();

        // Scout: no `tt_edit_goals` in the role definition, so the
        // academy-wide read grant alone must not buy a write.
        $uid = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->assertFalse(
            user_can( $uid, 'tt_edit_goals' ),
            'the scout role holds no goals change right — the premise of this test'
        );

        $repo = new MatrixRepository();
        $repo->removeRow( 'scout', 'goals', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );
        $repo->setRow( 'scout', 'goals', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL, '' );
        MatrixRepository::clearCache();

        $this->assertTrue(
            QueryHelpers::user_has_global_entity_read( $uid, 'goals' ),
            'the matrix must resolve an academy-wide goals read for the scout'
        );

        $adapter = new GoalThreadAdapter();

        $this->assertTrue(
            $adapter->canRead( $uid, $goal_id ),
            'an academy-wide goals reader follows the conversation'
        );
        $this->assertFalse(
            $adapter->canPost( $uid, $goal_id ),
            'reading academy-wide does not grant writing in the thread'
        );
    }

    public function test_unrelated_user_without_any_grant_is_denied(): void {
        $goal_id = $this->seed_goal();

        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $adapter = new GoalThreadAdapter();

        $this->assertFalse( $adapter->canRead( $uid, $goal_id ) );
        $this->assertFalse( $adapter->canPost( $uid, $goal_id ) );
    }
}
