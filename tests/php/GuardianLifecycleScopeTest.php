<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3937 — archiving or binning a child ends the guardian's reach.
 *
 * `ParentChildResolver` narrowed a guardian's children to `status =
 * 'active'` and the current club and to nothing else. A player carries a
 * status *and* a lifecycle, and they are independent — `PlayerStatus`
 * says so in its own docblock — so a child who had been archived, or
 * moved to the recycle bin, still carried `status = 'active'` and still
 * resolved through every guardian surface: the dashboard switcher, the
 * default subject, `canViewPlayer`, and the guardian branch of
 * `MatrixGate`'s `player` scope.
 *
 * Settled as option (b): **exclude both**, matching `ScoutPlayerLinks`
 * exactly (#3928) so the two sibling resolvers carry one lifecycle rule
 * rather than two that a reader has to tell apart.
 *
 * Every refusal below is paired with a grant on an untouched sibling
 * child. A test that only ever asserts a refusal cannot tell "narrowed
 * correctly" from "refused everything" — which is what #3913 and #3922
 * were both about.
 */
final class GuardianLifecycleScopeTest extends WP_UnitTestCase {

    private int $parent_uid = 0;
    /** The child that is never hidden, so every refusal has a control. */
    private int $sibling = 0;
    /** The child each test archives or trashes. */
    private int $subject = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $this->parent_uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->sibling    = $this->makePlayer( 'Sibling' );
        $this->subject    = $this->makePlayer( 'Subject' );
        $this->link( $this->sibling );
        $this->link( $this->subject );

        // The fixture wrote what it thinks it wrote. Asserting on an
        // unwritten row is how a silently-refused fixture passes.
        $linked = ParentChildResolver::childIds( $this->parent_uid );
        sort( $linked );
        $expected = [ $this->sibling, $this->subject ];
        sort( $expected );
        $this->assertSame(
            $expected,
            $linked,
            'fixture: both children are linked and visible before anything is hidden'
        );
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        $repo->removeRow( 'parent', 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    /** An active player in club 1. */
    private function makePlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Lifecycle',
            'last_name'  => $last,
            'status'     => 'active',
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
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

    /** Archive or trash the subject child, and prove the write landed. */
    private function hide( string $column, string $when ): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ $column => $when ], [ 'id' => $this->subject ] );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT archived_at, trashed_at FROM {$wpdb->prefix}tt_players WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->subject
        ) );
        $this->assertNotNull( $row, "fixture: the subject row exists" );
        $this->assertSame( $when, (string) $row->{$column}, "fixture: {$column} was actually written" );
    }

    private function grantParentEvaluationsAtPlayerScope(): void {
        ( new MatrixRepository() )->setRow( 'parent', 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        MatrixRepository::clearCache();
    }

    /**
     * The headline: a child in the recycle bin is off every guardian
     * surface, and the sibling beside them is untouched.
     */
    public function test_a_trashed_child_leaves_every_guardian_surface(): void {
        $this->hide( 'trashed_at', '2026-03-02 10:00:00' );

        $this->assertSame( [ $this->sibling ], ParentChildResolver::childIds( $this->parent_uid ) );
        $this->assertFalse( ParentChildResolver::isParentOf( $this->parent_uid, $this->subject ) );
        $this->assertTrue(
            ParentChildResolver::isParentOf( $this->parent_uid, $this->sibling ),
            'the sibling is still this guardian\'s child'
        );
    }

    /** And the same for an archived child — option (b), #3937. */
    public function test_an_archived_child_leaves_every_guardian_surface(): void {
        $this->hide( 'archived_at', '2026-03-01 10:00:00' );

        $this->assertSame( [ $this->sibling ], ParentChildResolver::childIds( $this->parent_uid ) );
        $this->assertFalse( ParentChildResolver::isParentOf( $this->parent_uid, $this->subject ) );
        $this->assertTrue( ParentChildResolver::isParentOf( $this->parent_uid, $this->sibling ) );
    }

    /**
     * The switcher and the default subject read `children()`, not
     * `childIds()`. Pinned separately because the bug's most visible face
     * was a binned child still sitting in the parent's child switcher.
     */
    public function test_the_child_switcher_and_default_subject_drop_a_hidden_child(): void {
        global $wpdb;

        foreach ( [ 'archived_at' => '2026-03-01 10:00:00', 'trashed_at' => '2026-03-02 10:00:00' ] as $column => $when ) {
            $wpdb->update(
                "{$wpdb->prefix}tt_players",
                [ 'archived_at' => null, 'trashed_at' => null ],
                [ 'id' => $this->subject ]
            );
            $this->assertSame( 2, ParentChildResolver::childCount( $this->parent_uid ), 'both children before hiding' );

            $this->hide( $column, $when );

            $children = ParentChildResolver::children( $this->parent_uid );
            $this->assertCount( 1, $children, "{$column}: one switcher entry remains" );
            $this->assertSame( $this->sibling, (int) $children[0]->id );
            $this->assertSame( 1, ParentChildResolver::childCount( $this->parent_uid ) );
            $this->assertSame( $this->sibling, (int) ParentChildResolver::defaultChild( $this->parent_uid )->id );
        }
    }

    /**
     * The scope question, on both the specific-target path and the
     * any-scope one, and through `canViewPlayer` — which is what
     * `GET /players/{id}` and the me-view dispatch gate ask.
     */
    public function test_the_player_scope_closes_for_a_hidden_child(): void {
        $this->grantParentEvaluationsAtPlayerScope();

        $this->assertTrue(
            MatrixGate::can( $this->parent_uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->subject ),
            'the grant holds before the child is hidden'
        );
        $this->assertTrue( AuthorizationService::canViewPlayer( $this->parent_uid, $this->subject ) );

        $this->hide( 'trashed_at', '2026-03-02 10:00:00' );
        AuthorizationService::flushCache();

        $this->assertFalse(
            MatrixGate::can( $this->parent_uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->subject ),
            'a binned child is out of the guardian\'s player scope'
        );
        $this->assertFalse(
            AuthorizationService::canViewPlayer( $this->parent_uid, $this->subject ),
            'and the record is not readable by id either'
        );

        $this->assertTrue(
            MatrixGate::can( $this->parent_uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->sibling ),
            'the sibling is still in scope — this narrowed, it did not refuse everything'
        );
        $this->assertTrue( AuthorizationService::canViewPlayer( $this->parent_uid, $this->sibling ) );
        $this->assertTrue(
            MatrixGate::canAnyScope( $this->parent_uid, 'evaluations', MatrixGate::READ ),
            'and the guardian still holds an any-scope read through the sibling'
        );
    }

    /**
     * A guardian whose only child has been binned stops being a linked
     * parent at all — the dashboard rail, the tile grid and the alert
     * audience all hang off this answer.
     */
    public function test_a_guardian_of_only_hidden_children_is_not_a_linked_parent(): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_players",
            [ 'trashed_at' => '2026-03-02 10:00:00' ],
            [ 'id' => $this->sibling ]
        );
        $this->hide( 'trashed_at', '2026-03-02 10:00:00' );

        $this->assertSame( [], ParentChildResolver::childIds( $this->parent_uid ) );
        $this->assertFalse( QueryHelpers::user_is_linked_parent( $this->parent_uid ) );
        $this->assertFalse( ParentChildResolver::isParentViewer( $this->parent_uid ) );
        $this->assertNull( ParentChildResolver::defaultChild( $this->parent_uid ) );

        // The control: a guardian with a live child is still all of those.
        $other_parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $live         = $this->makePlayer( 'Live' );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $live,
            'parent_user_id' => $other_parent,
            'is_primary'     => 1,
        ] );
        $this->assertTrue( QueryHelpers::user_is_linked_parent( $other_parent ) );
        $this->assertTrue( ParentChildResolver::isParentViewer( $other_parent ) );
    }

    /**
     * Restoring from the bin restores the family's access. The bin is
     * reversible, so the rule it feeds has to be too.
     */
    public function test_restoring_a_child_restores_the_guardian_link(): void {
        $this->hide( 'trashed_at', '2026-03-02 10:00:00' );
        $this->assertFalse( ParentChildResolver::isParentOf( $this->parent_uid, $this->subject ) );

        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'trashed_at' => null ], [ 'id' => $this->subject ] );

        $this->assertTrue(
            ParentChildResolver::isParentOf( $this->parent_uid, $this->subject ),
            'a restored child is a child again'
        );
        $this->assertSame( 2, ParentChildResolver::childCount( $this->parent_uid ) );
    }

    /**
     * The JOIN reaches two archivable tables, so the lifecycle clause has
     * to be aliased or MySQL rejects the query as ambiguous. A crash here
     * would surface as "no children" rather than as an error, which is
     * indistinguishable from the fix working.
     */
    public function test_the_lifecycle_clause_does_not_break_the_query(): void {
        global $wpdb;
        $before = $wpdb->last_error;

        $children = ParentChildResolver::children( $this->parent_uid );

        $this->assertCount( 2, $children, 'the query returns rows, so it ran' );
        $this->assertSame( $before, $wpdb->last_error, 'and raised no ambiguous-column error' );
    }
}
