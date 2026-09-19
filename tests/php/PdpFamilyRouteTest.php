<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3645 — `GET players/{id}/pdp`, the family read route.
 *
 * A linked parent could acknowledge a development talk through
 * `PATCH pdp-conversations/{id}` but had no route on which to read the
 * plan the talk belongs to: `GET pdp-files/{id}` answers 403 for them and
 * the list comes back empty. Over the API a parent could sign for
 * something they could not read.
 *
 * The fix is a second route rather than a branch in `PdpAccess`, because
 * that gate also guards the coach manage view, the verdict routes and the
 * unsigned notes on the file payload. These tests pin both halves: the
 * family reaches the projection, and the staff routes have not moved.
 */
final class PdpFamilyRouteTest extends WP_UnitTestCase {

    private string $p      = '';
    private int $club      = 0;
    private int $team      = 0;
    private int $player    = 0;
    private int $teammate  = 0;
    private int $season    = 0;
    private int $file      = 0;
    private int $conv      = 0;
    private int $parent    = 0;

    public function set_up(): void {
        parent::set_up();

        global $wpdb, $wp_rest_server;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->seed();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_linked_parent_reads_the_plan_with_its_dates_and_ack_column(): void {
        wp_set_current_user( $this->linkedParent() );

        [ $data, $status ] = $this->get( $this->player );

        $this->assertSame( 200, $status );
        $this->assertSame( $this->player, $data['data']['player_id'] );
        $this->assertSame( $this->file, $data['data']['file']['id'] );

        $conversations = $data['data']['conversations'];
        $this->assertCount( 1, $conversations );
        $this->assertSame( $this->conv, $conversations[0]['id'] );
        $this->assertSame( '2026-10-01 10:00:00', $conversations[0]['scheduled_at'] );
        $this->assertArrayHasKey( 'parent_ack_at', $conversations[0] );
        $this->assertNull( $conversations[0]['parent_ack_at'] );
        $this->assertSame( 'next', $conversations[0]['state'] );
    }

    public function test_the_player_reads_their_own_plan_and_not_a_teammates(): void {
        wp_set_current_user( $this->linkedPlayerAccount() );

        [ , $own ] = $this->get( $this->player );
        $this->assertSame( 200, $own );

        [ , $other ] = $this->get( $this->teammate );
        $this->assertSame( 403, $other, 'a teammate of the same squad is still someone else' );
    }

    public function test_a_parent_of_another_child_is_refused(): void {
        $stranger = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->linkParent( $stranger, $this->teammate );
        wp_set_current_user( $stranger );

        [ , $status ] = $this->get( $this->player );

        $this->assertSame( 403, $status );
    }

    public function test_a_hidden_section_answers_section_private(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'pdp', false );
        wp_set_current_user( $this->linkedParent() );

        [ $data, $status ] = $this->get( $this->player );

        $this->assertSame( 403, $status );
        $this->assertSame( 'section_private', $data['errors'][0]['code'] );
    }

    public function test_notes_and_actions_stay_null_until_the_coach_signs_off(): void {
        wp_set_current_user( $this->linkedParent() );

        [ $before ] = $this->get( $this->player );
        $this->assertNull( $before['data']['conversations'][0]['notes'] );
        $this->assertNull( $before['data']['conversations'][0]['agreed_actions'] );

        global $wpdb;
        $wpdb->update(
            "{$this->p}tt_pdp_conversations",
            [ 'coach_signoff_at' => '2026-10-02 09:00:00' ],
            [ 'id' => $this->conv ]
        );

        [ $after ] = $this->get( $this->player );
        $this->assertSame( 'What was said.', $after['data']['conversations'][0]['notes'] );
        $this->assertSame( 'Two touches before turning.', $after['data']['conversations'][0]['agreed_actions'] );
        $this->assertSame( 'done', $after['data']['conversations'][0]['state'] );
    }

    public function test_no_coach_preparation_or_agenda_reaches_the_family(): void {
        wp_set_current_user( $this->linkedParent() );

        [ $data ] = $this->get( $this->player );

        foreach ( $data['data']['conversations'] as $conversation ) {
            $this->assertArrayNotHasKey( 'agenda', $conversation );
            $this->assertArrayNotHasKey( 'prep', $conversation );
            $this->assertArrayNotHasKey( 'preparation', $conversation );
        }
    }

    public function test_the_staff_file_route_is_unchanged_for_a_parent(): void {
        wp_set_current_user( $this->linkedParent() );

        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/pdp-files/' . $this->file );
        $response = rest_do_request( $request );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_a_player_without_a_plan_gets_an_explicit_empty_answer(): void {
        wp_set_current_user( $this->linkedPlayerAccount( $this->teammate ) );

        [ $data, $status ] = $this->get( $this->teammate );

        $this->assertSame( 200, $status );
        $this->assertNotNull( $data['data']['season'], 'the season is set on this install' );
        $this->assertNull( $data['data']['file'] );
        $this->assertSame( [], $data['data']['conversations'] );
    }

    /* ---- fixtures ------------------------------------------------------- */

    private function seed(): void {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O15-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Sem',
            'last_name'  => 'de Vries',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Daan',
            'last_name'  => 'Bakker',
            'status'     => 'active',
        ] );
        $this->teammate = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_seasons", [
            'club_id'    => $this->club,
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_pdp_files", [
            'club_id'   => $this->club,
            'player_id' => $this->player,
            'season_id' => $this->season,
            'status'    => 'open',
        ] );
        $this->file = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id'        => $this->club,
            'pdp_file_id'    => $this->file,
            'sequence'       => 1,
            'template_key'   => 'start',
            'scheduled_at'   => '2026-10-01 10:00:00',
            'notes'          => 'What was said.',
            'agreed_actions' => 'Two touches before turning.',
        ] );
        $this->conv = (int) $wpdb->insert_id;
    }

    private function linkedParent(): int {
        if ( $this->parent === 0 ) {
            $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
            $this->linkParent( $this->parent, $this->player );
        }
        return $this->parent;
    }

    private function linkParent( int $user_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'parent_user_id' => $user_id,
        ] );
    }

    private function linkedPlayerAccount( int $player_id = 0 ): int {
        global $wpdb;
        $player_id = $player_id > 0 ? $player_id : $this->player;
        $user      = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $user ], [ 'id' => $player_id ] );
        return $user;
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function get( int $player_id ): array {
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/pdp' );
        $response = rest_do_request( $request );
        $data     = $response->get_data();
        return [ is_array( $data ) ? $data : [], $response->get_status() ];
    }
}
