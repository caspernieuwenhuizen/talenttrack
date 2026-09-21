<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Threads\Adapters\GoalThreadAdapter;

/**
 * #3947 — goal threads ask `ParentChildResolver` who a guardian is.
 *
 * The adapter used to scan the raw `tt_player_parents` pivot, which has no
 * status or lifecycle filter, so a guardian of a released, archived or
 * binned child kept reading and posting in that child's goal threads — and
 * kept being notified — after every other surface had closed.
 *
 * Decided: the same rule as everywhere else. Read and post both close, and
 * the notify set follows access. Each refusal below is paired with a grant
 * on the untouched sibling's thread, and read and post are asserted
 * separately.
 */
final class GoalThreadGuardianLifecycleTest extends WP_UnitTestCase {

    private int $parent_uid = 0;
    private int $sibling = 0;
    private int $subject = 0;
    private int $siblingGoal = 0;
    private int $subjectGoal = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $author = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->parent_uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        $this->sibling = $this->makePlayer( 'Sibling' );
        $this->subject = $this->makePlayer( 'Subject' );
        $this->link( $this->sibling );
        $this->link( $this->subject );

        $this->siblingGoal = $this->makeGoal( $this->sibling, $author );
        $this->subjectGoal = $this->makeGoal( $this->subject, $author );

        $linked = ParentChildResolver::childIds( $this->parent_uid );
        sort( $linked );
        $expected = [ $this->sibling, $this->subject ];
        sort( $expected );
        $this->assertSame( $expected, $linked, 'fixture: both children are linked and visible before anything is hidden' );

        // Before anything is hidden the guardian is in both conversations —
        // otherwise every refusal below is vacuous.
        $adapter = new GoalThreadAdapter();
        $this->assertTrue( $adapter->canRead( $this->parent_uid, $this->subjectGoal ), 'fixture: the guardian reads the thread while the child is active' );
        $this->assertTrue( $adapter->canPost( $this->parent_uid, $this->subjectGoal ), 'fixture: the guardian posts while the child is active' );
        $this->assertContains( $this->parent_uid, $adapter->participantUserIds( $this->subjectGoal ) );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_a_released_child_closes_read_post_and_notify(): void {
        $this->set( 'status', 'released' );
        $this->assertClosedOnSubjectOpenOnSibling();
    }

    public function test_an_archived_child_closes_read_post_and_notify(): void {
        $this->set( 'archived_at', '2026-03-01 10:00:00' );
        $this->assertClosedOnSubjectOpenOnSibling();
    }

    public function test_a_binned_child_closes_read_post_and_notify(): void {
        $this->set( 'trashed_at', '2026-03-02 10:00:00' );
        $this->assertClosedOnSubjectOpenOnSibling();
    }

    public function test_a_stranger_is_not_a_participant_on_either_thread(): void {
        $stranger = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $adapter  = new GoalThreadAdapter();

        $this->assertFalse( $adapter->canRead( $stranger, $this->siblingGoal ) );
        $this->assertFalse( $adapter->canPost( $stranger, $this->siblingGoal ) );
        $this->assertTrue( $adapter->canRead( $this->parent_uid, $this->siblingGoal ), 'the linked guardian on the same thread' );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function assertClosedOnSubjectOpenOnSibling(): void {
        AuthorizationService::flushCache();
        $adapter = new GoalThreadAdapter();

        // Read.
        $this->assertTrue( $adapter->canRead( $this->parent_uid, $this->siblingGoal ), 'the active sibling\'s thread stays readable' );
        $this->assertFalse( $adapter->canRead( $this->parent_uid, $this->subjectGoal ), 'read closes with the guardian relationship' );

        // Post.
        $this->assertTrue( $adapter->canPost( $this->parent_uid, $this->siblingGoal ), 'the active sibling\'s thread stays open to post' );
        $this->assertFalse( $adapter->canPost( $this->parent_uid, $this->subjectGoal ), 'post closes with the guardian relationship' );

        // Notify.
        $this->assertContains( $this->parent_uid, $adapter->participantUserIds( $this->siblingGoal ) );
        $this->assertNotContains(
            $this->parent_uid,
            $adapter->participantUserIds( $this->subjectGoal ),
            'a closed-out family is not notified of a thread it can no longer read'
        );
    }

    private function makePlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Thread',
            'last_name'  => $last,
            'status'     => 'active',
            'wp_user_id' => null,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }

    private function link( int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $player_id,
            'parent_user_id' => $this->parent_uid,
            'is_primary'     => 0,
        ] );
    }

    private function makeGoal( int $player_id, int $author ): int {
        $id = ( new \TT\Infrastructure\Goals\GoalsRepository() )->create( [
            'player_id'  => $player_id,
            'title'      => 'Win more duels',
            'status'     => \TT\Domain\Vocabularies\Lookups\GoalStatus::IN_PROGRESS,
            'due_date'   => '2099-06-01',
            'created_by' => $author,
        ] );
        $this->assertGreaterThan( 0, $id, 'fixture: the goal was written' );
        $this->assertNotNull( ( new GoalThreadAdapter() )->findEntity( $id ), 'fixture: the goal resolves as a thread' );
        return $id;
    }

    /** Change one column on the subject child, and prove the write landed. */
    private function set( string $column, string $value ): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ $column => $value ], [ 'id' => $this->subject ] );

        $written = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$wpdb->prefix}tt_players WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->subject
        ) );
        $this->assertSame( $value, (string) $written, "fixture: {$column} was actually written" );
    }
}
