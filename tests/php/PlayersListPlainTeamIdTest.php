<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3856 — `GET players?team_id=73` narrows to that squad.
 *
 * The plain name was neither applied nor refused: WP REST drops a query
 * parameter no route declared, silently, so an administrator asking for one
 * team got every player she may read back. Each row carried a real team
 * name, which is what made the answer look deliberate rather than broken —
 * four age groups' worth of children read as one squad.
 *
 * Same precedence as #3584, #3607, #3668, #3765 and #3790: the nested
 * spelling wins, and a filter that is sent but cannot be read is refused
 * rather than dropped, because dropping it is the widening.
 */
final class PlayersListPlainTeamIdTest extends WP_UnitTestCase {

    private int $teamA   = 0;
    private int $teamB   = 0;
    private int $playerA = 0;
    private int $playerB = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Squad JO12-1', 'age_group' => 'U12' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Squad JO17-1', 'age_group' => 'U17' ] );
        $this->teamB = (int) $wpdb->insert_id;

        $this->playerA = $this->makePlayer( 'Twaalf', $this->teamA );
        $this->playerB = $this->makePlayer( 'Zeventien', $this->teamB );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** The fixture is only a fixture once both squads actually come back. */
    public function test_the_fixture_is_visible_to_the_caller(): void {
        $ids = $this->listIds( [] );

        $this->assertContains( $this->playerA, $ids );
        $this->assertContains( $this->playerB, $ids );
    }

    public function test_the_plain_team_id_narrows_to_that_squad(): void {
        $ids = $this->listIds( [ 'team_id' => $this->teamA ] );

        $this->assertContains( $this->playerA, $ids );
        $this->assertNotContains( $this->playerB, $ids, 'a caller asking for one squad must not get the academy' );
    }

    public function test_every_returned_row_carries_the_team_that_was_asked_for(): void {
        $rows = $this->listRows( [ 'team_id' => $this->teamA ] );

        $this->assertNotSame( [], $rows );
        foreach ( $rows as $row ) {
            $this->assertSame( $this->teamA, (int) ( (array) $row )['team_id'] );
        }
    }

    public function test_the_nested_spelling_still_returns_the_same_rows(): void {
        $this->assertSame(
            $this->listIds( [ 'filter' => [ 'team_id' => $this->teamA ] ] ),
            $this->listIds( [ 'team_id' => $this->teamA ] ),
            'both spellings answer identically'
        );
    }

    public function test_the_nested_spelling_wins_when_both_are_sent(): void {
        $ids = $this->listIds( [
            'team_id' => $this->teamB,
            'filter'  => [ 'team_id' => $this->teamA ],
        ] );

        $this->assertContains( $this->playerA, $ids );
        $this->assertNotContains( $this->playerB, $ids );
    }

    /** An empty nested value is not a filter, so the plain one is read. */
    public function test_an_empty_nested_value_falls_through_to_the_plain_name(): void {
        $ids = $this->listIds( [
            'team_id' => $this->teamA,
            'filter'  => [ 'team_id' => '' ],
        ] );

        $this->assertContains( $this->playerA, $ids );
        $this->assertNotContains( $this->playerB, $ids );
    }

    public function test_a_team_id_that_cannot_be_read_is_refused_not_dropped(): void {
        foreach ( [ [ 'team_id' => 0 ], [ 'team_id' => 'zeventien' ], [ 'filter' => [ 'team_id' => [ 1, 2 ] ] ] ] as $query ) {
            $response = $this->request( $query );

            $this->assertSame( 400, $response->get_status(), 'an unusable filter is refused, never answered with everybody' );
            $this->assertSame( 'bad_filter', $response->get_data()['errors'][0]['code'] ?? '' );
        }
    }

    public function test_the_route_declares_the_plain_filter_names(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/players'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = $handler['args'] ?? [];
        }

        foreach ( [ 'team_id', 'status', 'age_group', 'media_consent', 'filter' ] as $key ) {
            $this->assertArrayHasKey( $key, $args, "the route publishes {$key}" );
        }
    }

    /** The other plain names fold in the same way. */
    public function test_the_plain_status_name_is_read_too(): void {
        $trial = $this->makePlayer( 'Proef', $this->teamA, 'trial' );

        $ids = $this->listIds( [ 'status' => 'trial' ] );

        $this->assertContains( $trial, $ids );
        $this->assertNotContains( $this->playerA, $ids );
    }

    private function makePlayer( string $last, int $team_id, string $status = 'active' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => CurrentClub::id(),
            'team_id'    => $team_id,
            'first_name' => 'Speler',
            'last_name'  => $last,
            'status'     => $status,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $query */
    private function request( array $query ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players' );
        $request->set_query_params( $query + [ 'per_page' => 100 ] );
        return rest_get_server()->dispatch( $request );
    }

    /**
     * @param array<string,mixed> $query
     * @return list<mixed>
     */
    private function listRows( array $query ): array {
        $response = $this->request( $query );
        $this->assertSame( 200, $response->get_status() );
        return array_values( (array) ( $response->get_data()['data']['rows'] ?? [] ) );
    }

    /**
     * @param array<string,mixed> $query
     * @return list<int>
     */
    private function listIds( array $query ): array {
        $ids = [];
        foreach ( $this->listRows( $query ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }
        sort( $ids );
        return $ids;
    }
}
