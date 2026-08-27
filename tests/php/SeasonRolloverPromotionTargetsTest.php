<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\SeasonRollover\FrontendSeasonRolloverView;

/**
 * #2868 — "Promote to" offered every other team, with no reference to age
 * group. In a two-team academy the O14 side was offered the O13 side as
 * somewhere to be promoted to: the only target it had was a step backwards.
 *
 * A target qualifies only when its age group is strictly older. The oldest
 * team gets none, which is correct — a leaving cohort is handled per player
 * on step 2, not by promoting the team somewhere.
 *
 * `age_group` is operator-editable free text, so the ordering comes from the
 * lookup's own `sort_order` rather than from parsing the name.
 */
final class SeasonRolloverPromotionTargetsTest extends WP_UnitTestCase {

    private ReflectionMethod $isOlder;

    /** @var array<string,int> */
    private array $ranks;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        // Keepers deliberately shares O14's sort_order — a specialist group
        // that is neither older nor younger than the age band beside it.
        foreach ( [ 'O13' => 10, 'O14' => 20, 'O16' => 30, 'Keepers' => 20 ] as $name => $sort ) {
            $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
                'club_id'     => (int) CurrentClub::id(),
                'lookup_type' => 'age_group',
                'name'        => $name,
                'sort_order'  => $sort,
            ] );
        }

        $ranks = new ReflectionMethod( FrontendSeasonRolloverView::class, 'ageGroupRanks' );
        $ranks->setAccessible( true );
        $this->ranks = (array) $ranks->invoke( null );

        $this->isOlder = new ReflectionMethod( FrontendSeasonRolloverView::class, 'isOlderAgeGroup' );
        $this->isOlder->setAccessible( true );
    }

    private function team( string $age_group ): object {
        return (object) [ 'id' => 1, 'name' => 'T', 'age_group' => $age_group ];
    }

    private function isOlder( string $target, string $source ): bool {
        return (bool) $this->isOlder->invoke(
            null,
            $this->team( $target ),
            $this->team( $source ),
            $this->ranks
        );
    }

    public function test_an_older_group_is_a_promotion_target(): void {
        $this->assertTrue( $this->isOlder( 'O14', 'O13' ) );
        $this->assertTrue( $this->isOlder( 'O16', 'O13' ) );
    }

    /** The reported case: the oldest team must not be offered a younger one. */
    public function test_a_younger_group_is_never_a_target(): void {
        $this->assertFalse( $this->isOlder( 'O13', 'O14' ) );
        $this->assertFalse( $this->isOlder( 'O13', 'O16' ) );
    }

    public function test_the_same_group_is_not_a_target(): void {
        $this->assertFalse( $this->isOlder( 'O14', 'O14' ) );
    }

    /**
     * Two groups sharing a sort_order are neither older nor younger, so
     * neither is offered for the other — a specialist group like Keepers
     * sitting alongside O14 must not become a promotion destination.
     */
    public function test_groups_sharing_a_sort_order_are_not_targets(): void {
        $this->assertFalse( $this->isOlder( 'Keepers', 'O14' ) );
        $this->assertFalse( $this->isOlder( 'O14', 'Keepers' ) );
    }

    /**
     * No ordering can be established, so no promotion is offered. An empty
     * dropdown is better than a confident wrong one on a screen that moves
     * whole squads.
     */
    public function test_unknown_or_empty_age_groups_offer_nothing(): void {
        $this->assertFalse( $this->isOlder( 'O14', '' ) );
        $this->assertFalse( $this->isOlder( '', 'O13' ) );
        $this->assertFalse( $this->isOlder( 'O99', 'O13' ) );
    }

    /** Team rows carry whatever casing was typed when the team was created. */
    public function test_matching_is_case_insensitive(): void {
        $this->assertTrue( $this->isOlder( 'o14', 'o13' ) );
    }
}
