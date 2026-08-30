<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\DashboardShortcode;

/**
 * #3256 — a sorted or searched list must not fall through to the blog index.
 *
 * `WP_Query::parse_query()` substitutes the static front page only when the
 * main query carries no public query var beyond `preview` / `page` / `paged`
 * / `cpage`. `order`, `orderby` and `search` are all core public query vars
 * and all three are put on the URL by `FrontendListTable`, so on an install
 * whose front page IS the dashboard page — which is every install created
 * through onboarding — clicking a column header served "Hello world!".
 *
 * The four guards are as much the subject here as the fix: a `request`
 * filter that rewrote query vars it did not own would be a far worse bug
 * than the one it repairs.
 */
final class FrontPageViewQueryVarsTest extends WP_UnitTestCase {

    private int $dashboard_page;

    public function set_up(): void {
        parent::set_up();

        $this->dashboard_page = (int) self::factory()->post->create( [
            'post_type'    => 'page',
            'post_title'   => 'Dashboard',
            'post_content' => '[talenttrack_dashboard]',
            'post_status'  => 'publish',
        ] );

        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $this->dashboard_page );

        $_GET = [];
    }

    public function tear_down(): void {
        $_GET = [];
        delete_option( 'page_on_front' );
        update_option( 'show_on_front', 'posts' );
        parent::tear_down();
    }

    /** The bug: a sort URL loses the front-page substitution. */
    public function test_sorting_a_list_still_resolves_to_the_dashboard_page(): void {
        $_GET = [ 'tt_view' => 'players', 'orderby' => 'last_name', 'order' => 'asc' ];

        $out = DashboardShortcode::forceFrontPageForViews(
            [ 'orderby' => 'last_name', 'order' => 'asc' ]
        );

        $this->assertSame(
            [ 'page_id' => $this->dashboard_page ],
            $out,
            'A sorted list URL must resolve to the dashboard page, not the blog index.'
        );
    }

    /** `search` is the same failure through a different param. */
    public function test_searching_a_list_still_resolves_to_the_dashboard_page(): void {
        $_GET = [ 'tt_view' => 'players', 'search' => 'jansen' ];

        $out = DashboardShortcode::forceFrontPageForViews( [ 'search' => 'jansen' ] );

        $this->assertSame( [ 'page_id' => $this->dashboard_page ], $out );
    }

    /** Guard 1 — no `tt_view`, no business rewriting anything. */
    public function test_a_request_without_tt_view_is_left_alone(): void {
        $_GET = [ 'orderby' => 'title', 'order' => 'asc' ];

        $in  = [ 'orderby' => 'title', 'order' => 'asc' ];
        $out = DashboardShortcode::forceFrontPageForViews( $in );

        $this->assertSame( $in, $out, 'A plain blog-index sort must reach the blog index.' );
    }

    /** Guard 2 — an install serving posts on the front page is not affected. */
    public function test_a_posts_front_page_install_is_left_alone(): void {
        update_option( 'show_on_front', 'posts' );
        $_GET = [ 'tt_view' => 'players', 'orderby' => 'last_name' ];

        $in  = [ 'orderby' => 'last_name' ];
        $out = DashboardShortcode::forceFrontPageForViews( $in );

        $this->assertSame( $in, $out );
    }

    /** Guard 3 — a front page that is some other page is not ours to claim. */
    public function test_a_front_page_that_is_not_the_dashboard_is_left_alone(): void {
        $other = (int) self::factory()->post->create( [
            'post_type'   => 'page',
            'post_title'  => 'Welcome',
            'post_status' => 'publish',
        ] );
        update_option( 'page_on_front', $other );

        $_GET = [ 'tt_view' => 'players', 'orderby' => 'last_name' ];

        $in  = [ 'orderby' => 'last_name' ];
        $out = DashboardShortcode::forceFrontPageForViews( $in );

        $this->assertSame( $in, $out );
    }

    /** Guard 4 — wp-admin has its own query handling; stay out of it. */
    public function test_admin_requests_are_left_alone(): void {
        set_current_screen( 'edit-post' );
        $_GET = [ 'tt_view' => 'players', 'orderby' => 'last_name' ];

        $in  = [ 'orderby' => 'last_name' ];
        $out = DashboardShortcode::forceFrontPageForViews( $in );

        $this->assertSame( $in, $out );

        set_current_screen( 'front' );
    }

    /** The filter is wired, not merely written. */
    public function test_the_filter_is_registered(): void {
        DashboardShortcode::register();

        $this->assertNotFalse(
            has_filter( 'request', [ DashboardShortcode::class, 'forceFrontPageForViews' ] ),
            'forceFrontPageForViews must be attached to `request`.'
        );
    }
}
