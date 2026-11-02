<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4637 — over REST, a read without a window before the current season has
 * started answers `422 no_period` with the message the screen shows, on every
 * route that used to fall back to the season default. A window that is given
 * still answers, so each refusal stands next to a grant on the same fixture.
 *
 * The player report routes run as a `tt_club_admin` (see `PlayerReportTest`);
 * the attendance, minutes and grid routes as an administrator, as their own
 * tests do.
 */
final class ReportNoPeriodRestTest extends WP_UnitTestCase {

    private const PICK = 'Pick a period to see this report.';

    private int $team   = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Rest period U16', 'age_group' => 'U16' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team,
            'first_name' => 'Rest', 'last_name' => 'Period', 'status' => 'active', 'wp_user_id' => null,
        ] );
        $this->player = (int) $wpdb->insert_id;

        $start = gmdate( 'Y-m-d', (int) strtotime( '+30 days' ) );
        $wpdb->update( "{$wpdb->prefix}tt_seasons", [ 'is_current' => 0 ], [ 'club_id' => CurrentClub::id() ] );
        $wpdb->insert( "{$wpdb->prefix}tt_seasons", [
            'club_id' => CurrentClub::id(), 'name' => 'Not yet', 'start_date' => $start,
            'end_date' => gmdate( 'Y-m-d', (int) strtotime( '+330 days' ) ), 'is_current' => 1,
        ] );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_player_report_route_without_a_window_is_422(): void {
        $this->asClubAdmin();

        $this->assertNoPeriod( $this->call( 'GET', "/players/{$this->player}/report" ) );
        $this->assertSame( 200, $this->call( 'GET', "/players/{$this->player}/report", [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] )->get_status() );
    }

    public function test_a_snapshot_without_a_window_is_422_not_500(): void {
        global $wpdb;
        $this->asClubAdmin();
        $before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_report_snapshots WHERE player_id = %d", $this->player ) );

        $this->assertNoPeriod( $this->call( 'POST', "/players/{$this->player}/report-snapshots", [ 'title' => 'Before the season' ] ) );
        $this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_report_snapshots WHERE player_id = %d", $this->player ) ), 'nothing is frozen' );

        $picked = $this->call( 'POST', "/players/{$this->player}/report-snapshots", [ 'title' => 'March', 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 201, $picked->get_status(), 'a picked window is frozen as before' );
    }

    public function test_the_report_reads_without_a_window_are_422(): void {
        $this->asAdministrator();

        foreach ( [ '/reports/attendance', '/reports/attendance-leaderboard', '/reports/attendance-at-risk', '/reports/coach-evaluation-quality' ] as $route ) {
            $this->assertNoPeriod( $this->call( 'GET', $route ), $route );
            $this->assertSame( 200, $this->call( 'GET', $route, [ 'from' => '2020-03-01', 'to' => '2020-03-31' ] )->get_status(), $route . ' with a window' );
        }
        $this->assertNoPeriod( $this->call( 'GET', '/reports/minutes-audit', [ 'team_id' => $this->team ] ), 'minutes audit' );
        $this->assertSame( 200, $this->call( 'GET', '/reports/minutes-audit', [ 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ] )->get_status() );
    }

    public function test_a_from_without_a_to_runs_up_to_today(): void {
        $this->asAdministrator();

        $res = $this->call( 'GET', '/reports/attendance', [ 'from' => '2020-03-01' ] );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( gmdate( 'Y-m-d' ), $res->get_data()['data']['to'] );
    }

    public function test_the_grid_reads_without_a_window_are_422(): void {
        $this->asAdministrator();

        foreach ( [ '/activities/attendance-grid', '/activities/minutes-grid' ] as $route ) {
            $this->assertNoPeriod( $this->call( 'GET', $route, [ 'team_id' => $this->team ] ), $route );
            $this->assertSame( 200, $this->call( 'GET', $route, [ 'team_id' => $this->team, 'from' => '2020-03-01', 'to' => '2020-03-31' ] )->get_status(), $route . ' with a window' );
        }
    }

    private function assertNoPeriod( \WP_REST_Response $res, string $where = '' ): void {
        $this->assertSame( 422, $res->get_status(), $where );
        $error = $res->get_data()['errors'][0] ?? [];
        $this->assertSame( 'no_period', $error['code'] ?? null, $where );
        $this->assertSame( self::PICK, $error['message'] ?? null, $where );
    }

    /** @param array<string,mixed> $params */
    private function call( string $method, string $route, array $params = [] ): \WP_REST_Response {
        $request = new WP_REST_Request( $method, '/talenttrack/v1' . $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return rest_get_server()->dispatch( $request );
    }

    private function asClubAdmin(): void {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ) );
    }

    private function asAdministrator(): void {
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }
}
