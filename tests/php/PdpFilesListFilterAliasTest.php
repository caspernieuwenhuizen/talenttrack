<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3668 — `GET pdp-files` and `GET pdp-files/coverage` honour the plain
 * filter names.
 *
 * Both routes read only `filter[team_id]` (and the list also
 * `filter[player_id]` / `filter[status]`). `?team_id=52` answered 200 with
 * every team's files, which a client shows as one squad's development plans.
 * Same defect as #3584 (activities) and #3607 (goals).
 */
final class PdpFilesListFilterAliasTest extends WP_UnitTestCase {

    private int $season = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $coach_a = 0;
    private int $coach_b = 0;

    /** @var array<string,int> player ids by slot */
    private array $players = [];

    /** @var array<string,int> file ids by slot */
    private array $files = [];

    /** @var list<int> users that hold the PDP read cap in this test */
    private array $pdp_viewers = [];

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // The PDP read cap is handed out explicitly, so the test does not
        // depend on whether the matrix bridge is active in the test install.
        $this->cap_filter = function ( $allcaps, $caps, $args, $user ) {
            $uid = is_object( $user ) ? (int) $user->ID : 0;
            if ( in_array( $uid, $this->pdp_viewers, true ) ) {
                $allcaps['tt_view_pdp'] = true;
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'POP O11-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'POP O14-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->coach_a = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->coach_b = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->pdp_viewers[] = $this->coach_a;
        $this->pdp_viewers[] = $this->coach_b;

        $seed = [
            'a_open'      => [ $this->team_a, $this->coach_a, 'open' ],
            'a_completed' => [ $this->team_a, $this->coach_a, 'completed' ],
            'b_open'      => [ $this->team_b, $this->coach_b, 'open' ],
            'b_second'    => [ $this->team_b, $this->coach_b, 'open' ],
        ];
        foreach ( $seed as $slot => [ $team, $owner, $status ] ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id'    => $club,
                'team_id'    => $team,
                'first_name' => 'Pop',
                'last_name'  => $slot,
                'status'     => 'active',
            ] );
            $this->players[ $slot ] = (int) $wpdb->insert_id;
            $wpdb->insert( "{$p}tt_pdp_files", [
                'club_id'        => $club,
                'player_id'      => $this->players[ $slot ],
                'season_id'      => $this->season,
                'owner_coach_id' => $owner,
                'status'         => $status,
            ] );
            $this->files[ $slot ] = (int) $wpdb->insert_id;
        }

        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->pdp_viewers[] = $admin;
        wp_set_current_user( $admin );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_plain_team_id_returns_only_that_teams_files(): void {
        $data = $this->listData( [ 'team_id' => $this->team_a ] );

        $this->assertSame( [ $this->files['a_open'], $this->files['a_completed'] ], $this->sorted( $this->ids( $data ) ) );
        $this->assertSame( 2, (int) $data['total'] );
        foreach ( (array) $data['rows'] as $row ) {
            $this->assertSame( $this->team_a, (int) ( (array) $row )['team_id'] );
        }
        $this->assertSame(
            $this->ids( $this->listData( [ 'filter' => [ 'team_id' => $this->team_a ] ] ) ),
            $this->ids( $data )
        );
    }

    public function test_plain_player_id_and_status_filter_like_the_nested_form(): void {
        $this->assertSame(
            [ $this->files['b_second'] ],
            $this->ids( $this->listData( [ 'player_id' => $this->players['b_second'] ] ) )
        );
        $this->assertSame(
            $this->ids( $this->listData( [ 'filter' => [ 'player_id' => $this->players['b_second'] ] ] ) ),
            $this->ids( $this->listData( [ 'player_id' => $this->players['b_second'] ] ) )
        );

        $this->assertSame(
            [ $this->files['a_completed'] ],
            $this->ids( $this->listData( [ 'status' => 'completed' ] ) )
        );
        $this->assertSame(
            $this->ids( $this->listData( [ 'filter' => [ 'status' => 'completed' ] ] ) ),
            $this->ids( $this->listData( [ 'status' => 'completed' ] ) )
        );
    }

    public function test_the_nested_form_wins(): void {
        $this->assertSame(
            $this->ids( $this->listData( [ 'filter' => [ 'team_id' => $this->team_b ] ] ) ),
            $this->ids( $this->listData( [ 'team_id' => $this->team_a, 'filter' => [ 'team_id' => $this->team_b ] ] ) )
        );
        $this->assertSame(
            [ $this->files['b_open'], $this->files['b_second'] ],
            $this->sorted( $this->ids( $this->listData( [ 'team_id' => $this->team_a, 'filter' => [ 'team_id' => $this->team_b ] ] ) ) )
        );
    }

    public function test_a_coach_passing_another_coachs_team_still_sees_only_their_own_files(): void {
        wp_set_current_user( $this->coach_a );

        $this->assertSame( [], $this->ids( $this->listData( [ 'team_id' => $this->team_b ] ) ) );
        $this->assertSame(
            [ $this->files['a_open'], $this->files['a_completed'] ],
            $this->sorted( $this->ids( $this->listData( [ 'team_id' => $this->team_a ] ) ) )
        );
    }

    public function test_coverage_honours_a_plain_team_id(): void {
        $plain  = $this->coverageData( [ 'team_id' => $this->team_a ] );
        $nested = $this->coverageData( [ 'filter' => [ 'team_id' => $this->team_a ] ] );

        $player_ids = static function ( array $data ): array {
            $ids = array_map( static fn( $row ): int => (int) ( ( (array) $row )['player_id'] ?? 0 ), (array) ( $data['rows'] ?? [] ) );
            sort( $ids );
            return $ids;
        };

        $expected = [ $this->players['a_open'], $this->players['a_completed'] ];
        sort( $expected );
        $this->assertSame( $expected, $player_ids( $plain ) );
        $this->assertSame( $player_ids( $nested ), $player_ids( $plain ) );
        $this->assertSame( 2, (int) $plain['total'] );
        foreach ( (array) $plain['rows'] as $row ) {
            $this->assertSame( $this->team_a, (int) ( (array) $row )['team_id'] );
        }
    }

    public function test_the_routes_declare_their_args(): void {
        $list = $this->argsFor( '/talenttrack/v1/pdp-files' );
        foreach ( [ 'team_id', 'player_id', 'status', 'season_id', 'page', 'per_page', 'orderby', 'order', 'search', 'include_archived', 'filter' ] as $name ) {
            $this->assertArrayHasKey( $name, $list, "{$name} is not declared on GET pdp-files" );
        }
        $this->assertStringContainsString( '10, 25, 50 or 100', (string) ( $list['per_page']['description'] ?? '' ) );

        $coverage = $this->argsFor( '/talenttrack/v1/pdp-files/coverage' );
        foreach ( [ 'team_id', 'season_id', 'only_missing', 'archived', 'filter', 'per_page' ] as $name ) {
            $this->assertArrayHasKey( $name, $coverage, "{$name} is not declared on GET pdp-files/coverage" );
        }
    }

    /** @return array<string,mixed> */
    private function argsFor( string $route ): array {
        $args = [];
        foreach ( rest_get_server()->get_routes()[ $route ] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = (array) ( $handler['args'] ?? [] );
        }
        return $args;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function listData( array $query ): array {
        return $this->get( '/talenttrack/v1/pdp-files', $query );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function coverageData( array $query ): array {
        return $this->get( '/talenttrack/v1/pdp-files/coverage', $query );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function get( string $route, array $query ): array {
        $request = new WP_REST_Request( 'GET', $route );
        $request->set_query_params( $query + [ 'season_id' => $this->season, 'per_page' => 100 ] );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        return (array) ( $data['data'] ?? [] );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<int>
     */
    private function ids( array $data ): array {
        return array_values( array_map(
            static fn( $row ): int => (int) ( ( (array) $row )['id'] ?? 0 ),
            (array) ( $data['rows'] ?? [] )
        ) );
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted( array $ids ): array {
        sort( $ids );
        return $ids;
    }
}
