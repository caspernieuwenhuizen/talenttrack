<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Search\SearchRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #4671 — a small academy for the search tests: two teams (A, an O14, and
 * B, an O16), players on each, an activity on each, and the six personas
 * the refusal tests run over, as real accounts: a role, a `tt_people` row and
 * the team scope where the persona has one. Nothing is granted with
 * `add_cap`; the matrix is active, so the auth bridge decides.
 */
trait SearchFixtures {

    private string $search_matrix_was = '';

    private int $team_a = 0;
    private int $team_b = 0;

    /** @var array<string,int> name => player id */
    private array $players = [];

    /** @var array<string,int> name => activity id */
    private array $activities = [];

    /** @var array<string,int> persona => user id */
    private array $personas = [];

    private function searchSetUp(): void {
        global $wp_rest_server;
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $config                  = new ConfigService();
        $this->search_matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetSearchCaches();

        $this->team_a = $this->team( 'Searchside O14-1', 'O14' );
        $this->team_b = $this->team( 'Farside O16-1', 'O16' );

        $this->players['jose']   = $this->player( $this->team_a, 'José', 'Pérez', 7 );
        $this->players['boot']   = $this->player( $this->team_a, 'Jesse', 'Boot', 9 );
        $this->players['besten'] = $this->player( $this->team_a, 'Daan', 'den Besten', 4 );
        $this->players['far']    = $this->player( $this->team_b, 'Bram', 'Farplayer', 7 );

        $this->activities['a'] = $this->activity( $this->team_a, 'Searchside training', '2020-01-15' );
        $this->activities['b'] = $this->activity( $this->team_b, 'Farside training', '2020-01-15' );

        $this->personas['admin']     = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->personas['hod']       = $this->persona( 'tt_head_dev', 'head_of_development', 0, '' );
        $this->personas['coach']     = $this->persona( 'tt_coach', 'head_coach', $this->team_a, 'head_coach' );
        $this->personas['assistant'] = $this->persona( 'tt_coach', 'assistant_coach', $this->team_a, 'assistant_coach' );

        $this->personas['parent'] = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new ParentAccountService() )->linkToPlayer( $this->players['jose'], $this->personas['parent'] );

        $this->personas['player'] = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $this->personas['player'] ], [ 'id' => $this->players['boot'] ] );

        $wp_rest_server = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        $this->resetSearchCaches();
    }

    private function searchTearDown(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        ( new ConfigService() )->set( 'tt_authorization_active', $this->search_matrix_was );
        $this->resetSearchCaches();
        wp_set_current_user( 0 );
    }

    private function team( string $name, string $age_group ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => $name, 'age_group' => $age_group ] );
        $id = (int) $wpdb->insert_id;
        \TT\Infrastructure\Search\SearchText::refresh( 'team', $id );
        return $id;
    }

    private function player( int $team, string $first, string $last, int $shirt ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => $last,
            'jersey_number' => $shirt, 'status' => 'active', 'wp_user_id' => null,
        ] );
        $id = (int) $wpdb->insert_id;
        \TT\Infrastructure\Search\SearchText::refresh( 'player', $id );
        return $id;
    }

    private function activity( int $team, string $title, string $date, string $type = 'training' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team, 'title' => $title, 'session_date' => $date,
            'location' => 'Sportpark', 'activity_type_key' => $type,
        ] );
        $id = (int) $wpdb->insert_id;
        \TT\Infrastructure\Search\SearchText::refresh( 'activity', $id );
        return $id;
    }

    private function persona( string $role, string $role_type, int $team, string $role_in_team ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Staff', 'last_name' => ucfirst( $role_type ) . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        if ( $team > 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
                'club_id' => $club, 'team_id' => $team, 'person_id' => $person,
                'role_in_team' => $role_in_team, 'is_head_coach' => $role_in_team === 'head_coach' ? 1 : 0,
            ] );
            $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
                'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
            ] );
        }
        return $uid;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function searchRequest( int $user_id, array $params ): WP_REST_Response {
        wp_set_current_user( $user_id );
        $this->resetSearchCaches();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/search' );
        $request->set_query_params( $params );
        return rest_do_request( $request );
    }

    /**
     * The paged answer's data for a user.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function paged( int $user_id, string $q, array $params = [] ): array {
        $res = $this->searchRequest( $user_id, array_merge( [ 'q' => $q, 'page' => '1', 'per_page' => '50' ], $params ) );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (array) ( $res->get_data()['data'] ?? [] );
    }

    /**
     * Ids of one type in a paged answer.
     *
     * @param array<string,mixed> $data
     * @return list<int>
     */
    private function idsOf( array $data, string $type ): array {
        $ids = [];
        foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
            if ( ( $item['type'] ?? '' ) === $type ) $ids[] = (int) $item['id'];
        }
        return $ids;
    }

    private function resetSearchCaches(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        PlayerVisibility::flush();
        SearchRegistry::reset();
        wp_cache_flush();
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
