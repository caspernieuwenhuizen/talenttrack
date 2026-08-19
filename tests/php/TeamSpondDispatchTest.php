<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Shared\Frontend\DashboardShortcode;

/**
 * #2559 — `?tt_view=team-spond&id=N` returned a 500 for every user since
 * #2388 shipped: the dispatch case passed `$detail_id`, a variable that
 * only exists in dispatchCoachingView's scope, so the view received null
 * for its `int $team_id` parameter and PHP fatalled with a TypeError.
 *
 * The view itself was covered (TeamSpondAccessTest, TeamSpondGroupRoutesTest)
 * while the path that reaches it was dead, so this test goes through the
 * dispatcher rather than calling the view directly.
 */
final class TeamSpondDispatchTest extends WP_UnitTestCase {

    private const TEAM_ID = 4211;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'id' => self::TEAM_ID, 'club_id' => 1, 'name' => 'Spond Team' ] );

        // The panel lists Spond groups on render. No credentials are seeded
        // here, so no call should leave the process — this makes that a
        // guarantee rather than an assumption about test-env config.
        add_filter( 'pre_http_request', [ __CLASS__, 'blockHttp' ], 10, 3 );
    }

    public function tear_down(): void {
        remove_filter( 'pre_http_request', [ __CLASS__, 'blockHttp' ], 10 );
        unset( $_GET['id'] );
        parent::tear_down();
    }

    public static function blockHttp( $preempt, $args, $url ) {
        return new \WP_Error( 'http_blocked', 'No outbound HTTP in tests: ' . $url );
    }

    /** Invoke the private dispatch method and capture what it rendered. */
    private function dispatch( int $user_id ): array {
        $m = new ReflectionMethod( DashboardShortcode::class, 'dispatchAdminView' );
        $m->setAccessible( true );
        ob_start();
        $handled = $m->invoke( null, 'team-spond', $user_id, true );
        return [ $handled, (string) ob_get_clean() ];
    }

    public function test_team_spond_renders_the_panel_for_the_team_on_the_url(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        $_GET['id'] = (string) self::TEAM_ID;

        [ $handled, $html ] = $this->dispatch( $admin );

        $this->assertTrue( $handled, 'team-spond should be handled by dispatchAdminView' );
        $this->assertStringContainsString( 'data-tt-spond', $html, 'the connect panel should render' );
        $this->assertStringContainsString( 'Spond Team', $html, 'the panel should name the team from ?id' );
    }

    /** No `?id` is a missing team, not a fatal: the view's own guard answers. */
    public function test_team_spond_without_an_id_degrades_to_the_no_access_notice(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        unset( $_GET['id'] );

        [ $handled, $html ] = $this->dispatch( $admin );

        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'tt-notice', $html );
        $this->assertStringNotContainsString( 'data-tt-spond', $html );
    }

    /** A non-numeric id is absint'ed to 0 and takes the same guarded path. */
    public function test_team_spond_with_a_junk_id_degrades_to_the_no_access_notice(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        $_GET['id'] = 'not-a-team';

        [ $handled, $html ] = $this->dispatch( $admin );

        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'tt-notice', $html );
        $this->assertStringNotContainsString( 'data-tt-spond', $html );
    }
}
