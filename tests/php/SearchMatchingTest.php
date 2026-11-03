<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchText;

/**
 * #4671 — matching: the fold, the ranking and the query patterns.
 */
final class SearchMatchingTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_the_fold_lowercases_removes_accents_and_keeps_particles(): void {
        $this->assertSame( ' jose perez', SearchText::fold( 'José Pérez' ) );
        $this->assertSame( ' daan den besten', SearchText::fold( 'Daan  den-Besten' ) );
        $this->assertSame( '', SearchText::fold( '' ) );
        $this->assertSame( [ 'den', 'b' ], SearchText::tokens( 'Den B' ) );
    }

    public function test_jose_finds_jose_and_den_b_finds_den_besten(): void {
        $admin = $this->personas['admin'];
        $this->assertContains( $this->players['jose'], $this->idsOf( $this->paged( $admin, 'jose' ), 'player' ) );
        $this->assertContains( $this->players['besten'], $this->idsOf( $this->paged( $admin, 'den b' ), 'player' ) );
        $this->assertNotContains( $this->players['jose'], $this->idsOf( $this->paged( $admin, 'den b' ), 'player' ), 'every token must match' );
    }

    public function test_a_row_not_folded_yet_is_still_found_on_its_raw_columns(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'search_title' => null, 'search_text' => null, 'search_v' => null ], [ 'id' => $this->players['boot'] ] );
        $this->assertContains( $this->players['boot'], $this->idsOf( $this->paged( $this->personas['admin'], 'boot' ), 'player' ) );
    }

    public function test_boot_ranks_the_player_above_a_title_that_only_contains_it(): void {
        $this->activity( $this->team_a, 'Reddingsbootoefening', current_time( 'Y-m-d' ) );
        $items = $this->paged( $this->personas['admin'], 'boot' )['items'];
        $first = $items[0] ?? [];
        $this->assertSame( 'player', $first['type'] ?? '' );
        $this->assertSame( $this->players['boot'], (int) ( $first['id'] ?? 0 ) );
        $types = array_column( $items, 'type' );
        $this->assertContains( 'activity', $types, 'the activity is found, below the player' );
    }

    public function test_a_shirt_number_finds_the_players_wearing_it(): void {
        $data = $this->paged( $this->personas['admin'], '#7' );
        $this->assertSame( SearchQuery::PATTERN_SHIRT, $data['understood'][0]['pattern'] ?? '' );
        $ids = $this->idsOf( $data, 'player' );
        sort( $ids );
        $expected = [ $this->players['jose'], $this->players['far'] ];
        sort( $expected );
        $this->assertSame( $expected, $ids );
        $this->assertSame( [], $this->idsOf( $data, 'activity' ), 'a shirt number is about players' );

        $coach = $this->idsOf( $this->paged( $this->personas['coach'], '#7' ), 'player' );
        $this->assertSame( [ $this->players['jose'] ], $coach, 'and only those the viewer may open' );
    }

    public function test_an_age_group_finds_the_teams_and_their_players(): void {
        $data = $this->paged( $this->personas['admin'], 'O14' );
        $this->assertSame( SearchQuery::PATTERN_AGE_GROUP, $data['understood'][0]['pattern'] ?? '' );
        $this->assertSame( [ $this->team_a ], $this->idsOf( $data, 'team' ) );
        $this->assertContains( $this->players['jose'], $this->idsOf( $data, 'player' ) );
        $this->assertNotContains( $this->players['far'], $this->idsOf( $data, 'player' ) );
    }

    public function test_a_day_and_month_finds_that_days_activities(): void {
        $query = new SearchQuery( '12-10' );
        $this->assertCount( 1, $query->dates, 'Precondition: 12-10 falls in the current season.' );
        $on_day = $this->activity( $this->team_a, 'Day match', $query->dates[0] );

        $data = $this->paged( $this->personas['admin'], '12-10' );
        $this->assertSame( SearchQuery::PATTERN_DATE, $data['understood'][0]['pattern'] ?? '' );
        $this->assertSame( [ $on_day ], $this->idsOf( $data, 'activity' ) );
        $this->assertSame( [], $this->idsOf( $data, 'player' ), 'a date is about activities' );
    }

    public function test_a_weekday_finds_the_last_and_the_next_such_day(): void {
        $query = new SearchQuery( 'zaterdag' );
        $this->assertCount( 2, $query->dates );
        $this->assertSame( '6', gmdate( 'N', (int) strtotime( $query->dates[0] ) ) );
        $this->assertSame( '6', gmdate( 'N', (int) strtotime( $query->dates[1] ) ) );
        $this->assertLessThanOrEqual( current_time( 'Y-m-d' ), $query->dates[0] );
        $this->assertGreaterThan( current_time( 'Y-m-d' ), $query->dates[1] );

        $last = $this->activity( $this->team_a, 'Weekend game', $query->dates[0] );
        $next = $this->activity( $this->team_a, 'Weekend game', $query->dates[1] );
        $data = $this->paged( $this->personas['admin'], 'zaterdag' );
        $this->assertSame( SearchQuery::PATTERN_WEEKDAY, $data['understood'][0]['pattern'] ?? '' );
        $ids = $this->idsOf( $data, 'activity' );
        sort( $ids );
        $this->assertSame( [ $last, $next ], $ids );
        $this->assertSame( $this->idsOf( $this->paged( $this->personas['admin'], 'saturday' ), 'activity' ), $this->idsOf( $data, 'activity' ), 'English names the same day' );
    }

    public function test_an_activity_type_name_finds_activities_of_that_type(): void {
        $match = $this->activity( $this->team_a, 'Sparta at home', current_time( 'Y-m-d' ), 'match' );
        $types = \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'activity_type' );
        $keys  = array_map( static fn( $l ): string => (string) ( (array) $l )['name'], $types );
        if ( ! in_array( 'match', $keys, true ) ) {
            $this->markTestSkipped( 'no match activity type seeded on this install' );
        }
        $this->assertContains( $match, $this->idsOf( $this->paged( $this->personas['admin'], 'match' ), 'activity' ) );
    }
}
