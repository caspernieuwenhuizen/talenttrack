<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3584 — `GET activities` honours the plain filter names.
 *
 * The list read only `filter[team_id]` / `filter[date_from]` /
 * `filter[date_to]`. `?team_id=52&from=…&to=…` — the vocabulary the grids
 * and the training-plans list accept — answered 200 with every team and
 * every date, and in the Academy HQ simulation a register was entered on the
 * wrong training because of it.
 */
final class ActivitiesListFilterAliasTest extends WP_UnitTestCase {

    private int $coach  = 0;
    private int $team_a = 0;
    private int $team_b = 0;

    /** @var array<string,int> */
    private array $ids = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Hedel O11-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Hedel O12-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->ids = [
            'a_before' => $this->activity( $this->team_a, '2026-09-10' ),
            'a_inside' => $this->activity( $this->team_a, '2026-09-16' ),
            'a_after'  => $this->activity( $this->team_a, '2026-09-25' ),
            'b_inside' => $this->activity( $this->team_b, '2026-09-16' ),
        ];

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $club,
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->team_a,
        ] );
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_plain_params_filter_like_the_nested_ones(): void {
        $plain  = $this->list( [ 'team_id' => $this->team_a, 'from' => '2026-09-14', 'to' => '2026-09-20' ] );
        $nested = $this->list( [ 'filter' => [ 'team_id' => $this->team_a, 'date_from' => '2026-09-14', 'date_to' => '2026-09-20' ] ] );

        $this->assertSame( [ $this->ids['a_inside'] ], $plain );
        $this->assertSame( $nested, $plain );
    }

    public function test_date_from_and_date_to_are_aliases_too(): void {
        $this->assertSame(
            [ $this->ids['a_inside'] ],
            $this->list( [ 'team_id' => $this->team_a, 'date_from' => '2026-09-14', 'date_to' => '2026-09-20' ] )
        );
    }

    public function test_the_nested_form_wins_over_a_conflicting_plain_param(): void {
        $ids = $this->list( [
            'from'   => '2026-09-01',
            'to'     => '2026-09-30',
            'filter' => [ 'team_id' => $this->team_a, 'date_from' => '2026-09-14', 'date_to' => '2026-09-20' ],
        ] );
        $this->assertSame( [ $this->ids['a_inside'] ], $ids );
    }

    public function test_a_malformed_date_is_refused(): void {
        $res = $this->request( [ 'team_id' => $this->team_a, 'from' => '14-09-2026' ] );

        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'bad_date', $res->get_data()['errors'][0]['code'] ?? null );
    }

    public function test_a_team_the_coach_does_not_coach_stays_out(): void {
        $this->assertSame( [], $this->list( [ 'team_id' => $this->team_b, 'from' => '2026-09-14', 'to' => '2026-09-20' ] ) );
    }

    public function test_the_route_declares_its_args(): void {
        $routes = rest_get_server()->get_routes();
        $args   = [];
        foreach ( $routes['/talenttrack/v1/activities'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = $handler['args'] ?? [];
        }

        foreach ( [ 'team_id', 'date_from', 'date_to', 'from', 'to', 'filter', 'per_page' ] as $name ) {
            $this->assertArrayHasKey( $name, $args, "{$name} is not declared on GET activities" );
        }
    }

    private function activity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => (int) CurrentClub::id(),
            'team_id'             => $team_id,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $query */
    private function request( array $query ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( $query + [ 'per_page' => 100 ] );
        return rest_do_request( $req );
    }

    /**
     * @param array<string,mixed> $query
     * @return list<int>
     */
    private function list( array $query ): array {
        $res = $this->request( $query );
        $this->assertSame( 200, $res->get_status() );

        $ids = array_map( static fn( $row ): int => (int) ( $row['id'] ?? 0 ), $res->get_data()['data']['rows'] ?? [] );
        sort( $ids );
        return $ids;
    }
}
