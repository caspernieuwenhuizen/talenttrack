<?php
/**
 * #2720 — where a `tt_view` URL points.
 *
 * `tt_view` is only read where the [talenttrack_dashboard] shortcode runs.
 * Twenty-nine call sites built their URLs on `home_url( '/' )` instead, so
 * on any install whose dashboard is not the front page they landed the user
 * on the theme's homepage. What needs pinning is the resolution order in
 * RecordLink, because the sweep replaced hand-rolled URLs with calls to it.
 */

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\RecordLink;

class DashboardUrlResolutionTest extends WP_UnitTestCase {

    /** @var int */
    private $page = 0;

    /** @var string|null */
    private $request_uri = null;

    public function set_up(): void {
        parent::set_up();

        // Saved, not unset later: WordPress reads REQUEST_URI from
        // add_query_arg()/remove_query_arg() and errors when it is absent,
        // so leaving it missing breaks whichever test runs next.
        $this->request_uri = $_SERVER['REQUEST_URI'] ?? null;

        $this->page = self::factory()->post->create( [
            'post_type'    => 'page',
            'post_title'   => 'Dashboard',
            'post_status'  => 'publish',
            'post_content' => '[talenttrack_dashboard]',
        ] );

        QueryHelpers::set_config( 'dashboard_page_id', (string) $this->page );
    }

    public function tear_down(): void {
        if ( $this->request_uri === null ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $this->request_uri;
        }

        remove_filter( 'wp_doing_cron', '__return_true' );

        parent::tear_down();
    }

    public function test_the_dashboard_page_wins_over_the_site_root(): void {
        $this->assertNotSame(
            home_url( '/' ),
            get_permalink( $this->page ),
            'fixture is pointless unless the two differ'
        );

        $this->assertSame( get_permalink( $this->page ), RecordLink::dashboardUrl() );
        $this->assertStringStartsWith( get_permalink( $this->page ), RecordLink::detailUrlFor( 'players', 7 ) );
    }

    /**
     * #1462 — a trashed dashboard used to leave the stale id pointing every
     * link at a dead page. Resolution must fall through and adopt the live
     * page instead.
     */
    public function test_a_trashed_page_is_abandoned_for_a_live_one(): void {
        wp_trash_post( $this->page );

        $live = self::factory()->post->create( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => 'intro [talenttrack_dashboard] outro',
        ] );

        $this->assertSame( $live, RecordLink::dashboardPageId() );
        $this->assertSame( get_permalink( $live ), RecordLink::dashboardUrl() );

        // ...and the discovery is cached back, so the next call is cheap.
        $this->assertSame( (string) $live, QueryHelpers::get_config( 'dashboard_page_id', '0' ) );
    }

    public function test_no_dashboard_anywhere_resolves_to_nothing(): void {
        wp_trash_post( $this->page );
        QueryHelpers::set_config( 'dashboard_page_id', '0' );

        $this->assertSame( 0, RecordLink::dashboardPageId() );
    }

    /**
     * The last resort builds on REQUEST_URI. Under wp-cron that is
     * `/wp-cron.php`, and a link to the cron endpoint in a notification
     * email is worse than a plain home-page link — the reader cannot tell
     * it is broken until they have clicked it.
     */
    public function test_cron_never_builds_a_url_from_the_current_request(): void {
        wp_trash_post( $this->page );
        QueryHelpers::set_config( 'dashboard_page_id', '0' );

        $_SERVER['REQUEST_URI'] = '/wp-cron.php?doing_wp_cron=1';

        // Filtered, not define()'d — DOING_CRON cannot be unset again and
        // would make every later test in this process look like cron.
        add_filter( 'wp_doing_cron', '__return_true' );
        $cron_url = RecordLink::dashboardUrl();
        remove_filter( 'wp_doing_cron', '__return_true' );

        $this->assertStringNotContainsString( 'wp-cron', $cron_url );
        $this->assertSame( home_url( '/' ), $cron_url );

        // Outside cron the request-based last resort is still in play —
        // that behaviour predates this change and is not being removed.
        $web_url = RecordLink::dashboardUrl();
        $this->assertStringContainsString( 'wp-cron.php', $web_url );
    }
}
