<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3653 — a player reads their own goals and journey over REST.
 *
 * `My goals` and `My journey` rendered the rows, but the API refused them:
 * `players/{id}/timeline` and `players/{id}/transitions` carried the staff
 * capability `tt_view_players` on the route, and `GET goals` is a staff
 * collection a player holds no capability for. A non-WordPress front end
 * could not draw either screen.
 *
 * The journey routes now gate per player in their handlers, which they
 * already did; the goals half gets the per-player route
 * `players/{id}/goals`, next to `players/{id}/evaluations` (#3478). The
 * staff collection is unchanged — #3568 decided collections stay staff
 * surfaces.
 */
final class PlayerSelfGoalsAndJourneyRestTest extends WP_UnitTestCase {

    private int $team        = 0;
    private int $player      = 0;
    private int $teammate    = 0;
    private int $player_user = 0;
    private int $parent_user = 0;

    public function set_up(): void {
        parent::set_up();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'JO15-1 zelf' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->player_user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'team_id'    => $this->team,
            'first_name' => 'Bas',
            'last_name'  => 'Willems',
            'status'     => 'active',
            'wp_user_id' => $this->player_user,
        ] );
        $this->player = (int) $wpdb->insert_id;

        // Same team on purpose: a refusal has to come from the self /
        // guardian link, not from the teammate happening to sit elsewhere.
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'team_id'    => $this->team,
            'first_name' => 'Sem',
            'last_name'  => 'Bakker',
            'status'     => 'active',
        ] );
        $this->teammate = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_goals", [
            'club_id' => $club, 'player_id' => $this->player,
            'title'   => 'Eerste aanname verbeteren', 'status' => 'pending', 'created_by' => 1,
        ] );
        $wpdb->insert( "{$p}tt_goals", [
            'club_id' => $club, 'player_id' => $this->player,
            'title'   => 'Links trappen', 'status' => 'pending', 'created_by' => 1,
        ] );
        $wpdb->insert( "{$p}tt_goals", [
            'club_id' => $club, 'player_id' => $this->teammate,
            'title'   => 'Kopduels', 'status' => 'pending', 'created_by' => 1,
        ] );

        $this->event( $this->player, 'public', 'joined_academy', 1 );
        $this->event( $this->player, 'coaching_staff', 'evaluation_completed', 2 );
        $this->event( $this->player, 'safeguarding', 'note_added', 3 );
        $this->event( $this->teammate, 'public', 'joined_academy', 4 );

        $this->parent_user = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new ParentAccountService() )->linkToPlayer( $this->player, $this->parent_user );
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_player_reads_their_own_goals(): void {
        wp_set_current_user( $this->player_user );

        $response = $this->get( "/talenttrack/v1/players/{$this->player}/goals" );
        $this->assertSame( 200, $response->get_status() );

        $data = $this->payload( $response );
        $this->assertSame( $this->player, $data['player_id'] );
        $this->assertSame( 2, $data['total'] );

        $expected = array_map(
            static fn( $g ): int => (int) $g->id,
            ( new GoalsRepository() )->listForPlayer( $this->player )
        );
        $this->assertSame(
            $expected,
            array_map( static fn( array $row ): int => $row['id'], $data['rows'] ),
            'The route must return the rows My goals renders.'
        );
    }

    public function test_a_player_reading_their_own_goals_gets_links_they_can_open(): void {
        wp_set_current_user( $this->player_user );

        $data = $this->payload( $this->get( "/talenttrack/v1/players/{$this->player}/goals" ) );
        $row  = $data['rows'][0];

        $this->assertStringContainsString( 'tt_view=my-goals', $row['detail_url'] );
        $this->assertStringNotContainsString( 'player_id=', $row['detail_url'] );
        $this->assertSame( '', $row['player_link_html'] );
    }

    public function test_a_parent_reading_their_child_gets_the_child_scoped_link(): void {
        wp_set_current_user( $this->parent_user );

        $data = $this->payload( $this->get( "/talenttrack/v1/players/{$this->player}/goals" ) );
        $this->assertStringContainsString( 'player_id=' . $this->player, $data['rows'][0]['detail_url'] );
    }

    public function test_a_player_is_refused_a_teammates_goals(): void {
        wp_set_current_user( $this->player_user );

        $this->assertSame( 403, $this->get( "/talenttrack/v1/players/{$this->teammate}/goals" )->get_status() );
    }

    public function test_a_parent_reads_their_child_and_not_another(): void {
        wp_set_current_user( $this->parent_user );

        $this->assertSame( 200, $this->get( "/talenttrack/v1/players/{$this->player}/goals" )->get_status() );
        $this->assertSame( 403, $this->get( "/talenttrack/v1/players/{$this->teammate}/goals" )->get_status() );
    }

    public function test_a_hidden_section_answers_section_private(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'goals', false );
        MatrixRepository::clearCache();
        wp_set_current_user( $this->parent_user );

        $response = $this->get( "/talenttrack/v1/players/{$this->player}/goals" );
        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'section_private', $this->errorCode( $response ) );
    }

    public function test_the_staff_goals_collection_is_unchanged(): void {
        wp_set_current_user( $this->player_user );
        $this->assertSame(
            403,
            $this->get( '/talenttrack/v1/goals', [ 'player_id' => $this->player ] )->get_status(),
            'GET goals stays a staff collection (#3568).'
        );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->assertSame( 200, $this->get( '/talenttrack/v1/goals' )->get_status() );
    }

    public function test_a_player_reads_their_own_timeline_and_transitions(): void {
        wp_set_current_user( $this->player_user );

        $this->assertSame( 200, $this->get( "/talenttrack/v1/players/{$this->player}/timeline" )->get_status() );
        $this->assertSame( 200, $this->get( "/talenttrack/v1/players/{$this->player}/transitions" )->get_status() );
    }

    public function test_a_player_is_refused_a_teammates_journey(): void {
        wp_set_current_user( $this->player_user );

        $this->assertSame( 403, $this->get( "/talenttrack/v1/players/{$this->teammate}/timeline" )->get_status() );
        $this->assertSame( 403, $this->get( "/talenttrack/v1/players/{$this->teammate}/transitions" )->get_status() );
    }

    public function test_a_parent_reads_their_childs_timeline_and_not_another(): void {
        wp_set_current_user( $this->parent_user );

        $this->assertSame( 200, $this->get( "/talenttrack/v1/players/{$this->player}/timeline" )->get_status() );
        $this->assertSame( 403, $this->get( "/talenttrack/v1/players/{$this->teammate}/timeline" )->get_status() );
    }

    public function test_a_hidden_journey_answers_section_private(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'journey', false );
        MatrixRepository::clearCache();
        wp_set_current_user( $this->parent_user );

        $response = $this->get( "/talenttrack/v1/players/{$this->player}/timeline" );
        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'section_private', $this->errorCode( $response ) );
    }

    public function test_staff_only_entries_stay_hidden_from_the_player(): void {
        wp_set_current_user( $this->player_user );

        $data = $this->payload( $this->get( "/talenttrack/v1/players/{$this->player}/timeline" ) );

        $visibilities = array_map( static fn( array $e ): string => $e['visibility'], $data['events'] );
        $this->assertSame( [ 'public' ], array_values( array_unique( $visibilities ) ) );
        $this->assertSame( 2, $data['hidden_count'], 'The coaching-staff and safeguarding entries are counted, not shown.' );
    }

    public function test_the_player_goals_route_declares_its_id(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/players/(?P<id>\d+)/goals'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = (array) $handler['args'];
        }
        $this->assertArrayHasKey( 'id', $args );
    }

    private function event( int $player_id, string $visibility, string $type, int $source_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
            'club_id'            => (int) CurrentClub::id(),
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $player_id,
            'event_type'         => $type,
            'event_date'         => '2026-09-01 10:00:00',
            'summary'            => 'Reis ' . $source_id,
            'visibility'         => $visibility,
            'source_module'      => 'tests',
            'source_entity_type' => 'test_event',
            'source_entity_id'   => $source_id,
        ] );
    }

    /** @param array<string,mixed> $query */
    private function get( string $route, array $query = [] ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', $route );
        if ( $query ) $request->set_query_params( $query );
        return rest_do_request( $request );
    }

    /** @return array<string,mixed> */
    private function payload( \WP_REST_Response $response ): array {
        $body = (array) $response->get_data();
        return (array) ( $body['data'] ?? [] );
    }

    private function errorCode( \WP_REST_Response $response ): string {
        $body = (array) $response->get_data();
        if ( ! empty( $body['errors'][0]['code'] ) ) return (string) $body['errors'][0]['code'];
        return (string) ( $body['code'] ?? '' );
    }
}
