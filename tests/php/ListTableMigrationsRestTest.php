<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #4194 — the three lists moved onto the shared list table read their rows
 * over REST in the list envelope `{success, data: {rows, total, page,
 * per_page}}`:
 *
 *   - `GET /match-executions` (new route),
 *   - `GET /measurement-results` when a page is asked for,
 *   - `GET /reports/attendance-leaderboard?filter[board]=top|bottom`.
 *
 * Smoke + shape at the REST boundary: the new route registers and refuses
 * an anonymous caller, each list answers in the envelope the list table
 * reads, and the older response shapes are unchanged for callers that do
 * not ask for a page or a board.
 */
final class ListTableMigrationsRestTest extends WP_UnitTestCase {

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

    private function asAdmin(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    /** A global measurements reader, the persona MeasurementResultsScopeTest uses. */
    private function asClubAdmin(): void {
        ( new RolesService() )->installRoles();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ) );
    }

    /** @param array<string, mixed> $params */
    private function get( string $route, array $params ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/' . $route );
        foreach ( $params as $k => $v ) $req->set_param( $k, $v );
        return rest_do_request( $req );
    }

    /** @return array<string, mixed> */
    private function envelope( \WP_REST_Response $res ): array {
        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data();
        $this->assertIsArray( $data );
        $this->assertTrue( (bool) ( $data['success'] ?? false ), 'the list envelope says success' );
        $this->assertIsArray( $data['data'] ?? null );
        foreach ( [ 'rows', 'total', 'page', 'per_page' ] as $key ) {
            $this->assertArrayHasKey( $key, $data['data'], "the list payload carries {$key}" );
        }
        return $data['data'];
    }

    // ---- match executions ------------------------------------------------

    public function test_match_executions_route_is_registered(): void {
        $this->assertArrayHasKey( '/talenttrack/v1/match-executions', rest_get_server()->get_routes() );
    }

    public function test_match_executions_refuses_an_anonymous_caller(): void {
        wp_set_current_user( 0 );
        $status = $this->get( 'match-executions', [] )->get_status();
        $this->assertNotSame( 200, $status );
        $this->assertLessThan( 500, $status );
    }

    public function test_match_executions_lists_an_execution_in_the_window(): void {
        global $wpdb;
        $this->asAdmin();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'List U15', 'age_group' => 'U15' ] );
        $team_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $team_id,
            'title'             => 'League match',
            'session_date'      => gmdate( 'Y-m-d', (int) strtotime( '-10 days' ) ),
            'activity_type_key' => 'game',
            'opponent'          => 'Rivals FC',
        ] );
        $activity_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_match_execution', [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => 1,
            'activity_id'   => $activity_id,
            'match_prep_id' => 0,
            'state'         => 'pending_review',
            'home_score'    => 3,
            'away_score'    => 1,
        ] );

        $data = $this->envelope( $this->get( 'match-executions', [
            'filter'   => [ 'team_id' => $team_id ],
            'page'     => 1,
            'per_page' => 25,
        ] ) );

        $this->assertSame( 1, $data['total'] );
        $row = $data['rows'][0];
        $this->assertSame( $activity_id, $row['activity_id'] );
        $this->assertSame( '3–1', $row['score'] );
        $this->assertSame( 'pending_review', $row['state'] );
        $this->assertStringContainsString( 'tt-mex-chip--review', $row['state_pill_html'] );
        $this->assertStringContainsString( 'match-execution', $row['detail_url'] );

        $filtered = $this->envelope( $this->get( 'match-executions', [
            'filter' => [ 'team_id' => $team_id, 'state' => 'finalized' ],
        ] ) );
        $this->assertSame( 0, $filtered['total'], 'the state filter narrows the list' );
    }

    public function test_match_executions_refuses_a_team_outside_the_callers_scope(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );
        $res = $this->get( 'match-executions', [ 'filter' => [ 'team_id' => 999999 ] ] );
        $this->assertSame( 403, $res->get_status() );
    }

    // ---- test results ----------------------------------------------------

    public function test_measurement_results_list_mode_without_a_test_is_an_empty_list(): void {
        $this->asClubAdmin();
        $data = $this->envelope( $this->get( 'measurement-results', [ 'page' => 1, 'per_page' => 25 ] ) );
        $this->assertSame( [], $data['rows'] );
        $this->assertSame( 0, $data['total'] );
    }

    public function test_measurement_results_without_a_page_keeps_the_old_refusal(): void {
        $this->asClubAdmin();
        $this->assertSame( 400, $this->get( 'measurement-results', [] )->get_status() );
    }

    public function test_measurement_results_list_mode_sorts_pages_and_renders_cells(): void {
        global $wpdb;
        $this->asClubAdmin();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Sprint U13', 'age_group' => 'U13' ] );
        $team_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'     => 1,
            'category_id' => 1,
            'name'        => 'List Sprint 10m',
            'value_type'  => 'numeric',
            'unit'        => 's',
            'frequency'   => 'adhoc',
            'direction'   => 'lower',
            'is_active'   => 1,
        ] );
        $definition_id = (int) $wpdb->insert_id;
        foreach ( [ [ 'Anna', 'Aalders', '1.90' ], [ 'Bram', 'Bakker', '2.10' ] ] as [ $first, $last, $value ] ) {
            $wpdb->insert( $wpdb->prefix . 'tt_players', [
                'club_id' => 1, 'first_name' => $first, 'last_name' => $last, 'team_id' => $team_id, 'status' => 'active',
            ] );
            $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', [
                'club_id'       => 1,
                'definition_id' => $definition_id,
                'player_id'     => (int) $wpdb->insert_id,
                'recorded_date' => '2026-02-01',
                'value_numeric' => $value,
            ] );
        }

        $data = $this->envelope( $this->get( 'measurement-results', [
            'filter'   => [ 'definition_id' => $definition_id, 'team_id' => $team_id ],
            'orderby'  => 'value',
            'order'    => 'desc',
            'page'     => 1,
            'per_page' => 10,
        ] ) );

        $this->assertSame( 2, $data['total'] );
        $this->assertSame( 'Bram Bakker', $data['rows'][0]['name'], 'sorted on the measured value, high first' );
        $this->assertArrayHasKey( 'result_html', $data['rows'][0] );
        $this->assertArrayHasKey( 'trend_html', $data['rows'][0] );
        $this->assertStringContainsString( 'Bram Bakker', $data['rows'][0]['player_html'] );

        $old = $this->get( 'measurement-results', [ 'definition_id' => $definition_id, 'team_id' => $team_id ] );
        $this->assertSame( 200, $old->get_status() );
        $this->assertArrayHasKey( 'rows', $old->get_data(), 'a caller that asks for no page gets the old shape' );
    }

    // ---- attendance leaderboard ------------------------------------------

    public function test_leaderboard_board_mode_answers_in_the_list_envelope(): void {
        $this->asAdmin();
        $data = $this->envelope( $this->get( 'reports/attendance-leaderboard', [
            'filter'   => [ 'board' => 'bottom', 'from' => '2020-01-01', 'to' => '2020-12-31' ],
            'page'     => 1,
            'per_page' => 25,
        ] ) );
        $this->assertSame( 'bottom', $data['board'] );
        $this->assertSame( '2020-01-01', $data['from'] );
    }

    public function test_leaderboard_without_a_board_keeps_top_and_bottom(): void {
        $this->asAdmin();
        $res  = $this->get( 'reports/attendance-leaderboard', [ 'from' => '2020-01-01', 'to' => '2020-12-31' ] );
        $data = $res->get_data();
        $this->assertSame( 200, $res->get_status() );
        $this->assertArrayHasKey( 'top', $data['data'] );
        $this->assertArrayHasKey( 'bottom', $data['data'] );
        $this->assertArrayNotHasKey( 'rows', $data['data'] );
    }
}
