<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Search\RecentItems;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FrontendSearchView;
use TT\Shared\Frontend\ShellPreference;

/**
 * #4672 — the staff results page (`?tt_view=search&q=`), the header search
 * box in both shells, and the recent list a record page feeds.
 */
final class SearchPageTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
    }

    public function tear_down(): void {
        foreach ( [ 'q', 'type', 'team_id', 'season_id', 'paged' ] as $key ) unset( $_GET[ $key ] );
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_the_page_finds_what_the_coach_may_open_and_nothing_else(): void {
        $html = $this->page( $this->personas['coach'], [ 'q' => 'training' ] );

        $this->assertStringContainsString( 'Searchside training', $html );
        $this->assertStringNotContainsString( 'Farside training', $html, 'another team\'s activity is not found' );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'the breadcrumb chain renders' );
        $this->assertStringContainsString( 'tt-search-page__kinds', $html );
    }

    public function test_a_match_the_coach_may_not_open_reads_as_no_match(): void {
        $refused = $this->page( $this->personas['coach'], [ 'q' => 'Farplayer' ] );
        $unknown = $this->page( $this->personas['coach'], [ 'q' => 'Nosuchname' ] );

        $this->assertStringContainsString( 'Nothing matched.', $refused );
        $this->assertStringNotContainsString( 'Farplayer', strip_tags( str_replace( 'value="Farplayer"', '', $refused ) ) );
        $this->assertSame(
            substr_count( $unknown, 'tt-search-page__result' ),
            substr_count( $refused, 'tt-search-page__result' )
        );
    }

    public function test_the_kind_filter_keeps_one_kind(): void {
        $html = $this->page( $this->personas['admin'], [ 'q' => 'Searchside', 'type' => 'team' ] );

        $this->assertStringContainsString( 'Searchside O14-1', $html );
        $this->assertStringNotContainsString( 'Searchside training', $html, 'activities are filtered out' );
        $this->assertMatchesRegularExpression( '/class="tt-search-page__kind is-current"[^>]*aria-current="true"/', $html );
    }

    public function test_one_pass_lists_one_kind_and_counts_them_all(): void {
        $admin = $this->personas['admin'];
        wp_set_current_user( $admin );
        $this->resetSearchCaches();
        $service = new \TT\Infrastructure\Search\SearchService();
        $query   = new \TT\Infrastructure\Search\SearchQuery( 'Searchside' );

        $both = $service->page( $query, [], 1, 20, $admin );
        $one  = $service->page( $query, [], 1, 20, $admin, [ 'team' ] );

        $this->assertSame( [ 'team' ], array_values( array_unique( array_column( $one['items'], 'type' ) ) ) );
        $this->assertSame( $both['counts'], $one['counts'], 'every kind is still counted' );
        $this->assertGreaterThan( 0, $one['counts']['activity'] ?? 0 );
    }

    public function test_the_team_filter_narrows_and_an_unknown_kind_is_ignored(): void {
        $html = $this->page( $this->personas['admin'], [ 'q' => 'training', 'team_id' => (string) $this->team_b, 'type' => 'nonsense' ] );

        $this->assertStringContainsString( 'Farside training', $html );
        $this->assertStringNotContainsString( 'Searchside training', $html );
    }

    public function test_an_empty_query_suggests_what_to_type(): void {
        $html = $this->page( $this->personas['coach'], [] );

        $this->assertStringContainsString( 'tt-search-page__tips', $html );
        $this->assertStringNotContainsString( 'tt-search-page__results', $html );
    }

    public function test_the_header_search_renders_in_the_classic_shell(): void {
        $coach = $this->personas['coach'];
        ShellPreference::setUserOverride( $coach, ShellPreference::CLASSIC );
        wp_set_current_user( $coach );

        $html = $this->header();

        $this->assertStringContainsString( 'data-tt-spotlight-form', $html );
        $this->assertMatchesRegularExpression( '/<input type="search"[^>]*role="combobox"[^>]*aria-expanded="false"/', $html );
        $this->assertMatchesRegularExpression( '/<a class="tt-spotlight-open" href="[^"]*tt_view=search/', $html );
        $this->assertSame( 1, substr_count( $html, 'data-tt-spotlight-form' ), 'rendered once' );
    }

    public function test_the_header_search_renders_in_the_app_shell(): void {
        $coach = $this->personas['coach'];
        ShellPreference::setUserOverride( $coach, ShellPreference::APP );
        wp_set_current_user( $coach );

        $this->assertSame( 1, substr_count( $this->header(), 'data-tt-spotlight-form' ) );
    }

    public function test_opening_a_record_page_puts_it_on_the_recent_list(): void {
        $coach  = $this->personas['coach'];
        $recent = new RecentItems();

        $this->assertTrue( $recent->recordView( $coach, 'players', $this->players['jose'] ) );
        $this->assertTrue( $recent->recordView( $coach, 'activities', $this->activities['a'] ) );
        $this->assertFalse( $recent->recordView( $coach, 'players', $this->players['far'] ), 'a player the coach may not open' );
        $this->assertFalse( $recent->recordView( $coach, 'evaluations', 1 ), 'not a record page' );

        wp_set_current_user( $coach );
        $this->resetSearchCaches();
        $items = $recent->forUser( $coach );
        $kinds = array_column( $items, 'type' );
        sort( $kinds );
        $this->assertSame( [ 'activity', 'player' ], $kinds );
    }

    public function test_the_groups_follow_the_owner_order(): void {
        $this->assertSame(
            [ 'player', 'team', 'activity', 'evaluation', 'goal', 'message', 'person', 'setting', 'view', 'help' ],
            array_column( FrontendSearchView::groups(), 'kind' )
        );
        $this->assertSame( 4, FrontendSearchView::groups()[0]['limit'] );
    }

    /** @param array<string,string> $get */
    private function page( int $user_id, array $get ): string {
        foreach ( [ 'q', 'type', 'team_id', 'season_id', 'paged' ] as $key ) unset( $_GET[ $key ] );
        foreach ( $get as $key => $value ) $_GET[ $key ] = $value;
        wp_set_current_user( $user_id );
        $this->resetSearchCaches();
        ob_start();
        FrontendSearchView::render( $user_id );
        return (string) ob_get_clean();
    }

    private function header(): string {
        $method = new \ReflectionMethod( DashboardShortcode::class, 'renderHeader' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null );
        return (string) ob_get_clean();
    }
}
