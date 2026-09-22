<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3607 — `GET goals` honours the plain filter names.
 *
 * Only `filter[player_id]` was read; `?player_id=577` was ignored with no
 * warning and answered with every goal in the caller's scope, which a client
 * shows as one child's goals. Same defect as #3584 on the activities list.
 */
final class GoalsListFilterAliasTest extends WP_UnitTestCase {

    private int $team = 0;
    private int $other_team = 0;
    private int $player = 0;
    private int $other_player = 0;

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
        foreach ( [ 'team', 'other_team' ] as $slot ) {
            $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Doelen ' . $slot ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }
        foreach ( [ 'player' => $this->team, 'other_player' => $this->other_team ] as $slot => $team ) {
            $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'team_id' => $team, 'first_name' => 'Doel', 'last_name' => $slot, 'status' => 'active' ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }
        foreach ( [ $this->player, $this->player, $this->other_player ] as $i => $pid ) {
            $wpdb->insert( "{$p}tt_goals", [ 'club_id' => $club, 'player_id' => $pid, 'title' => 'Doel ' . $i, 'status' => 'pending', 'created_by' => 1 ] );
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_player_id_filters_like_the_nested_form(): void {
        $plain  = $this->ids( [ 'player_id' => $this->player ] );
        $nested = $this->ids( [ 'filter' => [ 'player_id' => $this->player ] ] );

        $this->assertCount( 2, $plain );
        $this->assertSame( $nested, $plain );
    }

    public function test_team_id_filters_like_the_nested_form(): void {
        $this->assertSame(
            $this->ids( [ 'filter' => [ 'team_id' => $this->other_team ] ] ),
            $this->ids( [ 'team_id' => $this->other_team ] )
        );
        $this->assertCount( 1, $this->ids( [ 'team_id' => $this->other_team ] ) );
    }

    public function test_the_nested_form_wins(): void {
        $this->assertSame(
            $this->ids( [ 'filter' => [ 'player_id' => $this->other_player ] ] ),
            $this->ids( [ 'player_id' => $this->player, 'filter' => [ 'player_id' => $this->other_player ] ] )
        );
    }

    public function test_a_hidden_section_is_private_through_either_form(): void {
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new ParentAccountService() )->linkToPlayer( $this->player, $parent );
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'goals', false );
        MatrixRepository::clearCache();
        wp_set_current_user( $parent );

        $plain  = $this->request( [ 'player_id' => $this->player ] );
        $nested = $this->request( [ 'filter' => [ 'player_id' => $this->player ] ] );

        // `GET goals` is a staff collection scoped to the caller's teams
        // (#3653): a parent holds no team, so either form answers with no
        // rows before the filter is read. What must hold is that both forms
        // answer alike and neither hands over a goal of the hidden section.
        $this->assertSame( $nested->get_status(), $plain->get_status() );
        $this->assertSame( [], $this->rowsOf( $plain ), 'no goal of a hidden section through the plain form' );
        $this->assertSame( [], $this->rowsOf( $nested ), 'no goal of a hidden section through the nested form' );
        $this->assertSame(
            ( (array) $nested->get_data() )['errors'][0]['code'] ?? ( (array) $nested->get_data() )['code'] ?? null,
            ( (array) $plain->get_data() )['errors'][0]['code'] ?? ( (array) $plain->get_data() )['code'] ?? null
        );

        // The parent-facing read of the same goals is where the section
        // preference answers, and it answers as kept private.
        $own = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/goals' ) );
        $this->assertSame( 403, $own->get_status() );
        $this->assertSame( 'section_private', ( (array) $own->get_data() )['errors'][0]['code'] ?? ( (array) $own->get_data() )['code'] ?? null );
    }

    /** @return list<mixed> */
    private function rowsOf( \WP_REST_Response $response ): array {
        $data = (array) $response->get_data();
        return array_values( (array) ( $data['data']['rows'] ?? [] ) );
    }

    public function test_the_route_declares_its_args(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/goals'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = (array) $handler['args'];
        }
        foreach ( [ 'player_id', 'team_id', 'status', 'filter', 'search', 'per_page' ] as $name ) {
            $this->assertArrayHasKey( $name, $args, "{$name} is not declared on GET goals" );
        }
    }

    /** @param array<string,mixed> $query */
    private function request( array $query ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/goals' );
        $request->set_query_params( $query + [ 'per_page' => 100 ] );
        return rest_do_request( $request );
    }

    /**
     * @param array<string,mixed> $query
     * @return list<int>
     */
    private function ids( array $query ): array {
        $response = $this->request( $query );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        $ids  = array_map( static fn( $row ): int => (int) ( ( (array) $row )['id'] ?? 0 ), (array) ( $data['data']['rows'] ?? [] ) );
        sort( $ids );
        return $ids;
    }
}
