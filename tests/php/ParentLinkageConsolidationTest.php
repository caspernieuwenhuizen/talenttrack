<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Authorization\MatrixGate;

/**
 * #3476 — one answer to "is this user a guardian of this player?".
 *
 * Six places asked it, each with its own inline pivot query, and none of the
 * six was club-scoped. They had also drifted apart on what a released child
 * means: `ParentChildResolver` filters to `status = 'active'` and the copies
 * did not, so a guardian whose only child had been released got no parent
 * dashboard and no child switcher, and could still open that child's record
 * by typing the URL.
 *
 * Settled 2026-09-16: **a release ends the guardian's access.** What is pinned
 * here is that every path now agrees about it, and that the club boundary
 * holds — the one that will matter when there is more than one tenant.
 */
final class ParentLinkageConsolidationTest extends WP_UnitTestCase {

    private int $parent_uid = 0;
    private int $active_player = 0;
    private int $released_player = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $this->parent_uid      = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->active_player   = $this->makePlayer( 'active' );
        $this->released_player = $this->makePlayer( 'released' );

        $this->link( $this->parent_uid, $this->active_player );
        $this->link( $this->parent_uid, $this->released_player );
    }

    private function makePlayer( string $status, int $club_id = 1 ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $club_id,
            'first_name' => 'Linkage',
            'last_name'  => ucfirst( $status ),
            'status'     => $status,
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function link( int $uid, int $player_id, int $club_id = 1 ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $club_id,
            'player_id'      => $player_id,
            'parent_user_id' => $uid,
            'is_primary'     => 0,
        ] );
    }

    public function test_the_resolver_sees_the_active_child(): void {
        $this->assertTrue( ParentChildResolver::isParentOf( $this->parent_uid, $this->active_player ) );
    }

    /** The decision: a release ends it. */
    public function test_a_released_child_is_no_longer_reachable(): void {
        $this->assertFalse(
            ParentChildResolver::isParentOf( $this->parent_uid, $this->released_player ),
            'A release ends guardian access — settled 2026-09-16.'
        );
        $this->assertNotContains( $this->released_player, ParentChildResolver::childIds( $this->parent_uid ) );
    }

    /**
     * The point of the consolidation: every path gives the same answer for
     * the same pair, rather than four of them giving a different one.
     */
    public function test_every_path_agrees_about_the_released_child(): void {
        $answers = [
            'canViewPlayer'        => AuthorizationService::canViewPlayer( $this->parent_uid, $this->released_player ),
            'MatrixGate player'    => MatrixGate::can( $this->parent_uid, 'players', MatrixGate::READ, 'player', $this->released_player ),
            'activities repo'      => ( new ActivitiesRepository() )->userIsParentOfPlayer( $this->parent_uid, $this->released_player ),
            'resolver'             => ParentChildResolver::isParentOf( $this->parent_uid, $this->released_player ),
        ];

        foreach ( $answers as $path => $answer ) {
            $this->assertFalse( $answer, "{$path} still reaches a released child." );
        }
    }

    /** And the same agreement for the active one, in the other direction. */
    public function test_every_path_agrees_about_the_active_child(): void {
        $this->assertTrue(
            AuthorizationService::canViewPlayer( $this->parent_uid, $this->active_player ),
            'A guardian must still reach their active child.'
        );
        $this->assertTrue(
            ( new ActivitiesRepository() )->userIsParentOfPlayer( $this->parent_uid, $this->active_player )
        );
    }

    /** `user_is_linked_parent` is the same question asked without a player. */
    public function test_a_guardian_of_only_released_children_is_not_a_linked_parent(): void {
        $lonely = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->link( $lonely, $this->makePlayer( 'released' ) );

        $this->assertFalse( QueryHelpers::user_is_linked_parent( $lonely ) );
        $this->assertTrue( QueryHelpers::user_is_linked_parent( $this->parent_uid ) );
    }

    /**
     * The club boundary the six copies did not have. Today there is one
     * tenant, so this asserts the scaffold rather than a live behaviour —
     * which is the point of building it before it is load-bearing (§4).
     */
    public function test_a_link_in_another_club_does_not_resolve(): void {
        $other_club_player = $this->makePlayer( 'active', 2 );
        $this->link( $this->parent_uid, $other_club_player, 2 );

        $this->assertFalse(
            ParentChildResolver::isParentOf( $this->parent_uid, $other_club_player ),
            'A guardian link in another club must not resolve in this one.'
        );
        $this->assertNotContains( $other_club_player, ParentChildResolver::childIds( $this->parent_uid ) );
    }

    /** A stranger is a stranger on every path. */
    public function test_an_unlinked_user_reaches_nothing(): void {
        $stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertFalse( ParentChildResolver::isParentOf( $stranger, $this->active_player ) );
        $this->assertFalse( QueryHelpers::user_is_linked_parent( $stranger ) );
        $this->assertFalse( ( new ActivitiesRepository() )->userIsParentOfPlayer( $stranger, $this->active_player ) );
    }
}
