<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * #3717 — the three attendance report routes resolve the same default
 * window as the screens that show the same report, and say which window
 * they used.
 *
 * They used to fall back to a rolling 90 days while the leaderboard, the
 * team report and the player report all seeded
 * `ReportFilters::seasonDefaultWindow()`, so the same report answered with
 * a different set of players over REST than on screen. Neither half of the
 * problem was visible in the payload, which carried no window at all: a
 * caller that let the default resolve could not label the period, and an
 * empty `players` list could not be read in context.
 */
final class AttendanceReportWindowTest extends WP_UnitTestCase {

    private const ROUTES = [
        '/talenttrack/v1/reports/attendance',
        '/talenttrack/v1/reports/attendance-at-risk',
        '/talenttrack/v1/reports/attendance-leaderboard',
    ];

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function seedCurrentSeason( string $start, string $end ): void {
        $repo = new SeasonsRepository();
        $id   = $repo->create( [ 'name' => 'Window Test Season', 'start_date' => $start, 'end_date' => $end ] );
        $this->assertGreaterThan( 0, $id, 'season row created' );
        $this->assertTrue( $repo->setCurrent( $id ), 'season marked current' );
    }

    /**
     * @param array<string,scalar> $params
     * @return array<string,mixed>
     */
    private function payload( string $route, array $params = [] ): array {
        $req = new WP_REST_Request( 'GET', $route );
        foreach ( $params as $key => $value ) {
            $req->set_param( $key, $value );
        }
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status(), "{$route} answers 200 to an administrator" );

        $data = $res->get_data();
        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'data', $data, "{$route} uses the standard envelope" );
        $this->assertIsArray( $data['data'] );

        return $data['data'];
    }

    public function test_default_window_is_the_season_window_the_screens_seed(): void {
        $this->seedCurrentSeason( '2026-08-01', '2027-06-30' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $expected = ReportFilters::seasonDefaultWindow();
        $this->assertSame( '2026-08-01', $expected['from'], 'the helper seeds the season start' );

        foreach ( self::ROUTES as $route ) {
            $payload = $this->payload( $route );
            $this->assertSame(
                $expected['from'],
                $payload['from'] ?? null,
                "{$route} defaults to the season start, not 90 days back"
            );
            $this->assertSame(
                $expected['to'],
                $payload['to'] ?? null,
                "{$route} defaults to today"
            );
        }
    }

    public function test_explicit_window_is_honoured_and_echoed_unchanged(): void {
        $this->seedCurrentSeason( '2026-08-01', '2027-06-30' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        foreach ( self::ROUTES as $route ) {
            $payload = $this->payload( $route, [ 'from' => '2026-01-01', 'to' => '2026-01-31' ] );
            $this->assertSame( '2026-01-01', $payload['from'] ?? null, "{$route} echoes the supplied from" );
            $this->assertSame( '2026-01-31', $payload['to'] ?? null, "{$route} echoes the supplied to" );
        }
    }

    /**
     * A malformed date is not a window. It falls back to the same default
     * the screens use — and the payload says so rather than leaving the
     * caller to assume their own value was taken.
     */
    public function test_malformed_dates_fall_back_to_the_season_window(): void {
        $this->seedCurrentSeason( '2026-08-01', '2027-06-30' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $expected = ReportFilters::seasonDefaultWindow();
        $payload  = $this->payload(
            '/talenttrack/v1/reports/attendance-at-risk',
            [ 'from' => 'last-tuesday', 'to' => '31-01-2026' ]
        );

        $this->assertSame( $expected['from'], $payload['from'] ?? null );
        $this->assertSame( $expected['to'], $payload['to'] ?? null );
    }

    /**
     * With no season configured the helper's own 90-day rolling fallback
     * still applies, so a fresh install never gets an empty window.
     */
    public function test_without_a_season_the_ninety_day_fallback_still_applies(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $expected = ReportFilters::seasonDefaultWindow();
        $payload  = $this->payload( '/talenttrack/v1/reports/attendance-at-risk' );

        $this->assertSame( $expected['from'], $payload['from'] ?? null );
        $this->assertSame( $expected['to'], $payload['to'] ?? null );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) $payload['from'] );
    }

    /**
     * The window rides alongside the documented keys; it does not replace
     * them. The drill-down accordion reads `data.players`, the leaderboard
     * screen reads `top` / `bottom` / `total`.
     */
    public function test_the_window_is_added_next_to_the_existing_keys(): void {
        $this->seedCurrentSeason( '2026-08-01', '2027-06-30' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        foreach ( [ '/talenttrack/v1/reports/attendance', '/talenttrack/v1/reports/attendance-at-risk' ] as $route ) {
            $payload = $this->payload( $route );
            $this->assertArrayHasKey( 'players', $payload, "{$route} still carries players" );
            $this->assertArrayHasKey( 'threshold', $payload, "{$route} still carries the threshold" );
        }

        $board = $this->payload( '/talenttrack/v1/reports/attendance-leaderboard' );
        $this->assertArrayHasKey( 'top', $board );
        $this->assertArrayHasKey( 'bottom', $board );
        $this->assertArrayHasKey( 'total', $board );
    }
}
