<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Frontend\FrontendPdpPlanningView;
use TT\Modules\Pdp\Services\PdpPlanningService;

/**
 * #4448 — `GET /pdp-planning` returns the matrix the planning page shows,
 * read from `PdpPlanningService`, behind the page's capability and its team
 * scope: academy-wide PDP read sees every team, a team-scoped coach only
 * their own, whatever `team_ids` they ask for.
 */
final class PdpPlanningRestTest extends WP_UnitTestCase {

    private int $club   = 0;
    private int $season = 0;
    private int $own    = 0;
    private int $other  = 0;
    private int $hod    = 0;
    private int $coach  = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Planning JO13' ] );
        $this->own = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Planning JO15' ] );
        $this->other = (int) $wpdb->insert_id;

        $this->playerWithTalk( $this->own, 'Aerts', '2026-08-01', '2026-09-30', '2026-08-15 18:00:00' );
        $this->playerWithTalk( $this->own, 'Bosman', '2026-08-10', '2026-10-15', null );
        $this->playerWithTalk( $this->other, 'Claes', '2026-09-01', '2026-10-31', null );

        $this->hod    = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->coach  = $this->makeHeadCoach( $this->own );
        $this->player = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_view_defines_neither_matrix_method(): void {
        $this->assertFalse( method_exists( FrontendPdpPlanningView::class, 'buildMatrix' ) );
        $this->assertFalse( method_exists( FrontendPdpPlanningView::class, 'blockWindows' ) );
    }

    public function test_a_user_without_the_planning_capability_is_refused(): void {
        wp_set_current_user( $this->player );
        $this->assertSame( 403, $this->get( [ 'season_id' => $this->season ] )->get_status() );
    }

    public function test_the_head_of_development_gets_every_team_as_the_page_shows_it(): void {
        wp_set_current_user( $this->hod );
        $response = $this->get( [ 'season_id' => $this->season ] );
        $this->assertSame( 200, $response->get_status() );
        $data = $this->data( $response );

        $this->assertEqualsCanonicalizing( [ $this->own, $this->other ], $this->teamIds( $data ) );

        $page = PdpPlanningService::buildMatrix( $this->season, PdpPlanningService::accessibleTeamIds( $this->hod, false ) );
        $this->assertSame( (int) $page['max_blocks'], (int) $data['max_blocks'] );
        foreach ( $data['teams'] as $team ) {
            foreach ( $team['blocks'] as $cell ) {
                $expected = $page['teams'][ $team['team_id'] ]['blocks'][ $cell['block'] ];
                $this->assertSame( (int) $expected['planned'], $cell['planned'] );
                $this->assertSame( (int) $expected['conducted'], $cell['conducted'] );
                $this->assertSame( (int) $expected['expected'], $cell['expected'] );
                $this->assertSame( (string) $expected['window_start'], $cell['window_start'] );
                $this->assertSame( (string) $expected['window_end'], $cell['window_end'] );
            }
        }

        $own_cell = $this->cell( $data, $this->own );
        $this->assertSame( 2, $own_cell['expected'] );
        $this->assertSame( 1, $own_cell['planned'] );
        $this->assertSame( '2026-08-01', $own_cell['window_start'] );
        $this->assertSame( '2026-10-15', $own_cell['window_end'] );

        $windows = array_values( array_filter( $data['windows'], fn( $w ) => $w['team_id'] === $this->own && $w['block'] === 1 ) );
        $this->assertCount( 1, $windows );
        $this->assertSame( '2026-08-01', $windows[0]['start'] );
        $this->assertSame( '2026-10-15', $windows[0]['end'] );
    }

    public function test_team_ids_narrow_the_head_of_developments_matrix(): void {
        wp_set_current_user( $this->hod );
        $data = $this->data( $this->get( [ 'season_id' => $this->season, 'team_ids' => [ $this->other ] ] ) );
        $this->assertSame( [ $this->other ], $this->teamIds( $data ) );
    }

    public function test_a_coach_gets_only_their_own_team(): void {
        wp_set_current_user( $this->coach );
        $response = $this->get( [ 'season_id' => $this->season ] );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ $this->own ], $this->teamIds( $this->data( $response ) ) );
    }

    public function test_a_coach_asking_for_another_team_gets_only_their_own(): void {
        wp_set_current_user( $this->coach );

        $other_only = $this->data( $this->get( [ 'season_id' => $this->season, 'team_ids' => [ $this->other ] ] ) );
        $this->assertSame( [], $this->teamIds( $other_only ) );
        $this->assertSame( [], $other_only['windows'] );

        $both = $this->data( $this->get( [ 'season_id' => $this->season, 'team_ids' => $this->other . ',' . $this->own ] ) );
        $this->assertSame( [ $this->own ], $this->teamIds( $both ) );
    }

    public function test_a_missing_season_is_a_400(): void {
        wp_set_current_user( $this->hod );
        $this->assertSame( 400, $this->get( [] )->get_status() );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $params */
    private function get( array $params ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/pdp-planning' );
        foreach ( $params as $key => $value ) $request->set_param( $key, $value );
        return rest_do_request( $request );
    }

    /** @return array<string,mixed> */
    private function data( \WP_REST_Response $response ): array {
        $body = (array) $response->get_data();
        return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<int>
     */
    private function teamIds( array $data ): array {
        return array_map( static fn( $t ) => (int) $t['team_id'], (array) $data['teams'] );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function cell( array $data, int $team_id ): array {
        foreach ( $data['teams'] as $team ) {
            if ( $team['team_id'] !== $team_id ) continue;
            foreach ( $team['blocks'] as $cell ) {
                if ( $cell['block'] === 1 ) return $cell;
            }
        }
        $this->fail( 'no block 1 cell for team ' . $team_id );
    }

    private function playerWithTalk( int $team_id, string $last_name, string $start, string $end, ?string $scheduled_at ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Speler',
            'last_name'  => $last_name,
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id'   => $this->club,
            'player_id' => $player_id,
            'season_id' => $this->season,
            'status'    => 'open',
        ] );
        $file_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id'               => $this->club,
            'pdp_file_id'           => $file_id,
            'sequence'              => 1,
            'template_key'          => 'start',
            'planning_window_start' => $start,
            'planning_window_end'   => $end,
            'scheduled_at'          => $scheduled_at,
        ] );
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Hoofd', 'last_name' => 'Trainer',
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id' => $team_id, 'person_id' => $person_id, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
