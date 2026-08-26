<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2867 — an age-category **filter** offers only categories that have teams
 * in them. An academy with two teams used to scroll every category the seed
 * shipped, and every one of them but two returned nothing.
 *
 * Edit and create forms keep the whole vocabulary, which is why this is a
 * separate helper rather than a change to `get_lookup_names()`: you have to
 * be able to put the first team into a category nobody is in yet.
 */
final class AgeGroupsInUseTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        foreach ( [ 'O13' => 10, 'O14' => 20, 'O16' => 30, 'O19' => 40 ] as $name => $sort ) {
            $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
                'club_id'     => (int) CurrentClub::id(),
                'lookup_type' => 'age_group',
                'name'        => $name,
                'sort_order'  => $sort,
            ] );
        }
    }

    private function team( string $age_group, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', array_merge( [
            'club_id'   => (int) CurrentClub::id(),
            'name'      => 'Team ' . $age_group,
            'age_group' => $age_group,
        ], $extra ) );
        return (int) $wpdb->insert_id;
    }

    public function test_only_categories_with_teams_are_offered(): void {
        $this->team( 'O13' );
        $this->team( 'O14' );

        $in_use = QueryHelpers::age_groups_in_use();

        $this->assertSame( [ 'O13', 'O14' ], $in_use, 'the two empty categories are dead ends' );
    }

    /** Ordering follows the lookup, not the teams table. */
    public function test_ordering_follows_the_lookup(): void {
        $this->team( 'O16' );
        $this->team( 'O13' );

        $this->assertSame( [ 'O13', 'O16' ], QueryHelpers::age_groups_in_use() );
    }

    /** An archived or trashed team does not keep its category alive. */
    public function test_archived_and_trashed_teams_do_not_count(): void {
        $this->team( 'O13' );
        $this->team( 'O14', [ 'archived_at' => '2026-07-01 00:00:00' ] );
        $this->team( 'O16', [ 'trashed_at'  => '2026-07-01 00:00:00' ] );

        $this->assertSame( [ 'O13' ], QueryHelpers::age_groups_in_use() );
    }

    /**
     * A value already on the URL stays selectable even once it is unused, so
     * a bookmarked or shared link does not silently start filtering by
     * something else.
     */
    public function test_the_current_value_is_kept_even_when_unused(): void {
        $this->team( 'O13' );

        $this->assertSame( [ 'O13' ], QueryHelpers::age_groups_in_use() );
        $this->assertSame( [ 'O13', 'O16' ], QueryHelpers::age_groups_in_use( 'O16' ) );
    }

    /**
     * A team can carry an age group that is no longer in the vocabulary —
     * renamed, or typed before the lookup existed. Dropping it would hide
     * those teams behind a filter with no way to select them.
     */
    public function test_a_category_not_in_the_vocabulary_is_still_offered(): void {
        $this->team( 'O13' );
        $this->team( 'Onder 21' );

        $in_use = QueryHelpers::age_groups_in_use();

        $this->assertContains( 'O13', $in_use );
        $this->assertContains( 'Onder 21', $in_use );
    }

    /** Edit forms are unaffected: the full vocabulary is still available. */
    public function test_the_full_vocabulary_is_still_readable_for_edit_forms(): void {
        $this->team( 'O13' );

        $all = array_map( 'strval', QueryHelpers::get_lookup_names( 'age_group' ) );

        $this->assertContains( 'O19', $all, 'assigning a team to an unused category must stay possible' );
    }
}
