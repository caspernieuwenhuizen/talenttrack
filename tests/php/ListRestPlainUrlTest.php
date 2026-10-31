<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\BackLink;

/**
 * #4505 — the list REST endpoints behind `FrontendListTable` return plain
 * target URLs: the same `detail_url`, `row_url` and link HTML whichever
 * page made the call, with no `tt_back` in any of them. The list table adds
 * the back hint in the browser.
 *
 * A REST call takes its back target from the Referer, and only when
 * `REST_REQUEST` is defined. `rest_do_request()` does not define it and a
 * constant cannot be undefined, so each case runs in its own process. The
 * precondition in `assertSameFromAnyReferer()` proves `BackLink` reads the
 * Referer there, so the old code fails these tests.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ListRestPlainUrlTest extends WP_UnitTestCase {

    private int $club   = 0;
    private int $team   = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        if ( ! defined( 'REST_REQUEST' ) ) define( 'REST_REQUEST', true );
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Plain U14', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Plain',
            'last_name'  => 'Linkx',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_holidays(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_holidays", [
            'uuid' => wp_generate_uuid4(), 'club_id' => $this->club, 'name' => 'Plain break',
            'start_date' => '2026-10-12', 'end_date' => '2026-10-18',
        ] );
        $this->assertSameFromAnyReferer( 'holidays', [] );
    }

    public function test_match_executions(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Plain match',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-10 days' ) ),
            'activity_type_key' => 'game', 'opponent' => 'Rivals FC',
        ] );
        $wpdb->insert( "{$p}tt_match_execution", [
            'uuid' => wp_generate_uuid4(), 'club_id' => $this->club, 'activity_id' => (int) $wpdb->insert_id,
            'match_prep_id' => 0, 'state' => 'pending_review', 'home_score' => 2, 'away_score' => 0,
        ] );
        $this->assertSameFromAnyReferer( 'match-executions', [ 'filter' => [ 'team_id' => $this->team ] ] );
    }

    public function test_pdp_files_and_coverage(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_seasons", [
            'club_id' => $this->club, 'name' => '2026/27', 'start_date' => '2026-07-01',
            'end_date' => '2027-06-30', 'is_current' => 1,
        ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id' => $this->club, 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open',
        ] );
        $this->assertSameFromAnyReferer( 'pdp-files', [ 'season_id' => $season ] );
        $this->assertSameFromAnyReferer( 'pdp-files/coverage', [ 'season_id' => $season ] );
    }

    public function test_activities(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Plain training',
            'session_date' => current_time( 'Y-m-d' ), 'activity_type_key' => 'training',
        ] );
        $this->assertSameFromAnyReferer( 'activities', [] );
    }

    public function test_evaluations(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => $this->player, 'coach_id' => 1, 'eval_date' => '2026-08-23',
        ] );
        $this->assertSameFromAnyReferer( 'evaluations', [] );
    }

    public function test_goals(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id' => $this->club, 'player_id' => $this->player, 'title' => 'Plain goal',
            'created_by' => 1, 'status' => 'pending',
        ] );
        $this->assertSameFromAnyReferer( 'goals', [] );
    }

    public function test_people(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Plain', 'last_name' => 'Staffx',
            'role_type' => 'coach', 'status' => 'active',
        ] );
        $this->assertSameFromAnyReferer( 'people', [] );
    }

    public function test_players(): void {
        $this->assertSameFromAnyReferer( 'players', [] );
    }

    public function test_teams(): void {
        $this->assertSameFromAnyReferer( 'teams', [] );
    }

    public function test_tournaments(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_tournaments", [
            'club_id' => $this->club, 'team_id' => $this->team, 'name' => 'Plain toernooi',
            'start_date' => current_time( 'Y-m-d' ), 'uuid' => wp_generate_uuid4(), 'created_by' => 0,
        ] );
        $this->assertSameFromAnyReferer( 'tournaments', [] );
    }

    /**
     * Calls the route from two pages and compares every link-bearing field
     * of every row.
     *
     * @param array<string,mixed> $params
     */
    private function assertSameFromAnyReferer( string $route, array $params ): void {
        $server_was = $_SERVER;
        try {
            $_SERVER['REQUEST_URI'] = '/wp-json/talenttrack/v1/' . $route;

            $_SERVER['HTTP_REFERER'] = home_url( '/?tt_view=players' );
            $this->assertSame( $_SERVER['HTTP_REFERER'], BackLink::captureCurrent(), 'precondition: the Referer is the back target' );
            $first = $this->links( $route, $params );

            $_SERVER['HTTP_REFERER'] = home_url( '/?tt_view=my-tasks&task_id=1' );
            $this->assertSame( $_SERVER['HTTP_REFERER'], BackLink::captureCurrent() );
            $second = $this->links( $route, $params );
        } finally {
            $_SERVER = $server_was;
        }

        $this->assertNotSame( [], $first, $route . ': the fixture gives at least one row with a link' );
        $this->assertSame( $first, $second, $route . ': the same links from any page' );
        foreach ( $first as $field => $value ) {
            $this->assertStringNotContainsString( 'tt_back', $value, $route . ' ' . $field . ' carries no back chain' );
        }
    }

    /**
     * Every string field in the rows that holds a `tt_view` link, keyed
     * `row index . field`.
     *
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    private function links( string $route, array $params ): array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/' . $route );
        foreach ( $params + [ 'page' => 1, 'per_page' => 25 ] as $k => $v ) $req->set_param( $k, $v );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status(), $route . ' answers' );

        $data = (array) $res->get_data();
        $rows = (array) ( ( (array) ( $data['data'] ?? [] ) )['rows'] ?? [] );
        $out  = [];
        foreach ( $rows as $i => $row ) {
            foreach ( (array) $row as $field => $value ) {
                if ( is_string( $value ) && strpos( $value, 'tt_view=' ) !== false ) {
                    $out[ $i . '.' . $field ] = $value;
                }
            }
        }
        return $out;
    }
}
