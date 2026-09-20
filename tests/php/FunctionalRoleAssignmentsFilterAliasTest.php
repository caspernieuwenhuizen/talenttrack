<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3765 — `GET functional-roles/assignments` honours the plain filter names.
 *
 * Only `filter[team_id]` was read, so `?team_id=52` was dropped and the list
 * answered with every team's staff. Nothing else narrows this query, so the
 * dropped filter widened the read to the whole academy — an admin checking
 * which role links one person to U11 got the entire staff list back.
 */
final class FunctionalRoleAssignmentsFilterAliasTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $other_team = 0;
    private int $head_coach_role = 0;
    private int $physio_role = 0;
    /** @var array<string,int> */
    private array $assignments = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        foreach ( [ 'team' => 'Ajax U11', 'other_team' => 'Ajax U13' ] as $slot => $name ) {
            $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }
        foreach ( [ 'head_coach_role' => 'staf_head_coach', 'physio_role' => 'staf_physio' ] as $slot => $key ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => $key,
                'label'    => ucfirst( $key ),
            ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }

        // Kees coaches U11, Ingrid is its physio, Joop coaches U13.
        $people = [
            'kees'  => [ $this->team, $this->head_coach_role ],
            'lotte' => [ $this->team, $this->physio_role ],
            'joop'  => [ $this->other_team, $this->head_coach_role ],
        ];
        foreach ( $people as $slot => $pair ) {
            $wpdb->insert( "{$this->p}tt_people", [
                'club_id'    => $this->club,
                'first_name' => ucfirst( $slot ),
                'last_name'  => 'Staf',
            ] );
            $person_id = (int) $wpdb->insert_id;
            $wpdb->insert( "{$this->p}tt_team_people", [
                'club_id'            => $this->club,
                'team_id'            => $pair[0],
                'person_id'          => $person_id,
                'functional_role_id' => $pair[1],
            ] );
            $this->assignments[ $slot ] = (int) $wpdb->insert_id;
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_whole_academy_is_returned_without_a_filter(): void {
        $ids = $this->ids( $this->request( [] ) );
        foreach ( $this->assignments as $slot => $id ) {
            $this->assertContains( $id, $ids, "{$slot} is missing from the unfiltered list" );
        }
    }

    public function test_a_plain_team_id_returns_only_that_team(): void {
        $response = $this->request( [ 'team_id' => $this->team ] );

        $expected = [ $this->assignments['kees'], $this->assignments['lotte'] ];
        sort( $expected );
        $this->assertSame( $expected, $this->ids( $response ) );
        $this->assertSame( 2, $this->total( $response ), 'total still counted the whole academy' );
    }

    public function test_the_nested_form_keeps_working(): void {
        $this->assertSame(
            $this->ids( $this->request( [ 'team_id' => $this->team ] ) ),
            $this->ids( $this->request( [ 'filter' => [ 'team_id' => $this->team ] ] ) )
        );
    }

    public function test_the_nested_form_wins_when_both_are_sent(): void {
        $nested = $this->request( [ 'filter' => [ 'team_id' => $this->other_team ] ] );
        $both   = $this->request( [ 'team_id' => $this->team, 'filter' => [ 'team_id' => $this->other_team ] ] );

        $this->assertSame( [ $this->assignments['joop'] ], $this->ids( $nested ) );
        $this->assertSame( $this->ids( $nested ), $this->ids( $both ) );
    }

    public function test_functional_role_id_filters_plainly_and_nested(): void {
        $plain  = $this->request( [ 'functional_role_id' => $this->physio_role ] );
        $nested = $this->request( [ 'filter' => [ 'functional_role_id' => $this->physio_role ] ] );

        $this->assertSame( [ $this->assignments['lotte'] ], $this->ids( $plain ) );
        $this->assertSame( $this->ids( $nested ), $this->ids( $plain ) );
    }

    public function test_both_filters_narrow_together(): void {
        $response = $this->request( [ 'team_id' => $this->team, 'functional_role_id' => $this->head_coach_role ] );
        $this->assertSame( [ $this->assignments['kees'] ], $this->ids( $response ) );
    }

    public function test_an_unusable_filter_value_is_refused_not_widened(): void {
        foreach ( [ [ 'team_id' => 'U11' ], [ 'team_id' => 0 ], [ 'filter' => [ 'functional_role_id' => 'physio' ] ] ] as $query ) {
            $response = $this->request( $query );
            $this->assertSame( 400, $response->get_status(), 'an unresolvable filter answered with the whole academy' );
        }
    }

    public function test_the_route_declares_its_args(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/functional-roles/assignments'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = (array) $handler['args'];
        }
        foreach ( [ 'team_id', 'functional_role_id', 'filter', 'search', 'orderby', 'order', 'page', 'per_page' ] as $name ) {
            $this->assertArrayHasKey( $name, $args, "{$name} is not declared on GET functional-roles/assignments" );
            $this->assertNotSame( '', (string) ( ( (array) $args[ $name ] )['description'] ?? '' ), "{$name} has no description" );
        }
    }

    /** @param array<string,mixed> $query */
    private function request( array $query ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/functional-roles/assignments' );
        $request->set_query_params( $query + [ 'per_page' => 100 ] );
        return rest_do_request( $request );
    }

    /** @return list<int> */
    private function ids( \WP_REST_Response $response ): array {
        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        $ids  = array_map(
            static fn( $row ): int => (int) ( ( (array) $row )['id'] ?? 0 ),
            (array) ( ( (array) ( $data['data'] ?? [] ) )['rows'] ?? [] )
        );
        sort( $ids );
        return $ids;
    }

    private function total( \WP_REST_Response $response ): int {
        $data = (array) $response->get_data();
        return (int) ( ( (array) ( $data['data'] ?? [] ) )['total'] ?? 0 );
    }
}
