<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Players\ParentDashboardTiles;

/**
 * #3479 — a coach whose own child plays in the academy is a coach.
 *
 * `FrontendTileGrid::render()` opens with `isParentViewer()` and returns early
 * with the child-scoped rail. That predicate only ever asked "does this user
 * have their own player record?", so a coach — who has none — matched on a
 * single `tt_player_parents` row and lost every coaching tile. Under the
 * default `classic` shell there is no sidebar to fall back on, so they had no
 * route to any coaching surface at all. Coaches being parents of players in
 * the club is routine in youth football, not an edge case.
 *
 * #3472 — and the parent surface itself dropped any tile missing from the
 * child-noun allowlist, which is how `measurements` (granted by #1856) and
 * `my-messages` were granted and unreachable at the same time.
 */
final class ParentViewerStaffPersonaTest extends WP_UnitTestCase {

    private int $player_id = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Persona Test U13' ] );
        $team_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Kid',
            'last_name'  => 'Ofstaff',
            'team_id'    => $team_id,
            'wp_user_id' => null,
        ] );
        $this->player_id = (int) $wpdb->insert_id;
    }

    private function linkAsParent( int $user_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->player_id,
            'parent_user_id' => $user_id,
            'is_primary'     => 1,
        ] );
    }

    /** The plain guardian: no staff persona, no player record. Unchanged. */
    public function test_a_plain_parent_is_a_parent_viewer(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->linkAsParent( $uid );

        $this->assertTrue( ParentChildResolver::isParentViewer( $uid ) );
    }

    /** The regression. */
    public function test_a_coach_with_a_linked_child_is_not_a_parent_viewer(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->linkAsParent( $uid );

        $this->assertSame(
            [ $this->player_id ],
            ParentChildResolver::childIds( $uid ),
            'Precondition: the guardian link exists and the resolver sees it.'
        );
        $this->assertFalse(
            ParentChildResolver::isParentViewer( $uid ),
            'A coach must keep their coach dashboard, whoever their child is.'
        );
    }

    /** Not only coaches — any staff seat. */
    public function test_other_staff_personas_with_a_linked_child_keep_their_own_dashboard(): void {
        foreach ( [ 'tt_head_dev', 'tt_scout', 'tt_team_manager', 'tt_staff', 'administrator' ] as $role ) {
            $uid = self::factory()->user->create( [ 'role' => $role ] );
            $this->linkAsParent( $uid );

            $this->assertFalse(
                ParentChildResolver::isParentViewer( $uid ),
                "A user with the {$role} role must not be pushed onto the parent rail."
            );
        }
    }

    /** A guardian link is still required — staff without one are unaffected. */
    public function test_a_parent_without_a_linked_child_is_not_a_parent_viewer(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        $this->assertFalse( ParentChildResolver::isParentViewer( $uid ) );
    }

    /**
     * #3472 — the two halves of the parent surface must between them cover
     * every tile the registry yields, so nothing can be granted and invisible.
     */
    public function test_every_tile_a_parent_can_see_is_rendered_somewhere(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->linkAsParent( $uid );

        $visible = [];
        foreach ( \TT\Shared\Tiles\TileRegistry::tilesForUserGrouped( $uid ) as $group ) {
            foreach ( $group['tiles'] as $tile ) {
                $slug = (string) ( $tile['view_slug'] ?? '' );
                if ( $slug !== '' ) $visible[ $slug ] = true;
            }
        }

        $rendered = [];
        foreach ( ParentDashboardTiles::tiles( $uid ) as $t )    { $rendered[ $t['view_slug'] ] = true; }
        foreach ( ParentDashboardTiles::ownTiles( $uid ) as $t ) { $rendered[ $t['view_slug'] ] = true; }

        $missing = array_keys( array_diff_key( $visible, $rendered ) );
        sort( $missing );

        $this->assertSame(
            [],
            $missing,
            'These slugs are granted to a parent but rendered on neither half of their dashboard, '
            . 'so on the classic shell they are unreachable: ' . implode( ', ', $missing )
        );
    }

    /** The specific slug #1856 granted and #3472 found missing. */
    public function test_measurements_is_child_framed_on_the_parent_dashboard(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->linkAsParent( $uid );

        $slugs = array_column( ParentDashboardTiles::tiles( $uid ), 'view_slug' );

        $this->assertContains( 'measurements', $slugs );
    }
}
