<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4038 — `GET /players/{id}/team`: a player's own team.
 *
 * `GET /teams/{id}` is a staff read and stays one — it carries the whole team
 * row and the squad counts — so a player asking about the squad the `My team`
 * screen shows them got `rest_forbidden` for their own team. This route
 * answers that and nothing wider: the negative assertions are the important
 * ones, because the failure mode is a young person's record being handed to
 * someone who should not have it.
 */
final class PlayerOwnTeamRouteTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';

    private string $p = '';
    private int $club = 0;
    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->hide_errors();

        $this->team = $this->insertTeam( 'Hedel JO13-1', 'U13' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        QueryHelpers::set_config( 'tt_player_visible_rank', '0' );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_route_is_registered(): void {
        $this->assertArrayHasKey(
            self::BASE . '/players/(?P<id>\d+)/team',
            rest_get_server()->get_routes()
        );
    }

    public function test_an_anonymous_caller_is_refused(): void {
        $player = $this->insertPlayer( $this->team, 'Bas', 0 );
        wp_set_current_user( 0 );

        $this->assertContains( $this->status( $player ), [ 401, 403 ] );
    }

    public function test_a_player_reads_their_own_team_and_its_roster(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player  = $this->insertPlayer( $this->team, 'Bas', $account );
        $this->insertPlayer( $this->team, 'Kai', 0, 7, '["CB"]' );

        wp_set_current_user( $account );
        $data = $this->read( $player );

        $this->assertSame( $this->team, $data['team']['id'] );
        $this->assertSame( 'Hedel JO13-1', $data['team']['name'] );
        $this->assertSame( 'U13', $data['team']['age_group'] );
        $this->assertArrayHasKey( 'head_coach_name', $data['team'] );

        $this->assertCount( 1, $data['teammates'], 'the caller is not their own teammate' );
        $mate = (array) $data['teammates'][0];
        $this->assertSame( 'Kai Willems', $mate['name'] );
        $this->assertSame( 7, $mate['jersey_number'] );
        $this->assertSame( [ 'CB' ], $mate['positions'] );
    }

    /**
     * The row says who somebody is and where they play. Nothing about how
     * they are rated, how they are doing, or how to reach their family.
     */
    public function test_a_teammate_row_carries_no_ratings_statuses_or_contacts(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player  = $this->insertPlayer( $this->team, 'Bas', $account );
        $this->insertPlayer( $this->team, 'Kai', 0, 7 );

        wp_set_current_user( $account );
        $mate = (array) $this->read( $player )['teammates'][0];

        $this->assertSame(
            [ 'player_id', 'name', 'jersey_number', 'positions', 'position_label' ],
            array_keys( $mate )
        );
        foreach ( [ 'rolling', 'rating', 'status', 'guardian_name', 'guardian_email', 'guardian_phone', 'date_of_birth', 'height_cm', 'weight_kg', 'wp_user_id' ] as $forbidden ) {
            $this->assertArrayNotHasKey( $forbidden, $mate );
        }
    }

    /** #1384 — the team rank is opt-in per academy, and the route honours it. */
    public function test_rank_is_null_unless_the_academy_switches_it_on(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player  = $this->insertPlayer( $this->team, 'Bas', $account );

        wp_set_current_user( $account );
        $this->assertNull( $this->read( $player )['rank'], 'default is off' );

        QueryHelpers::set_config( 'tt_player_visible_rank', '1' );
        $data = $this->read( $player );
        $this->assertArrayHasKey( 'rank', $data );
        $this->assertNull( $data['rank'], 'switched on but not yet rated reads null, not a fabricated rank' );
    }

    public function test_the_staff_team_route_stays_refused_for_a_player_and_a_parent(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player  = $this->insertPlayer( $this->team, 'Bas', $account );
        $parent  = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->linkParent( $parent, $player );

        foreach ( [ $account, $parent ] as $uid ) {
            wp_set_current_user( $uid );
            $this->assertContains(
                rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/teams/' . $this->team ) )->get_status(),
                [ 401, 403 ],
                'GET teams/{id} stays a staff read'
            );
        }
    }

    public function test_a_linked_parent_reads_their_own_child_only(): void {
        $parent  = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $child   = $this->insertPlayer( $this->team, 'Bas', 0 );
        $another = $this->insertPlayer( $this->team, 'Kai', 0 );
        $this->linkParent( $parent, $child );

        wp_set_current_user( $parent );

        $this->assertSame( $this->team, $this->read( $child )['team']['id'] );
        $this->assertSame( 403, $this->status( $another ) );
    }

    public function test_a_player_reading_a_teammates_team_is_refused(): void {
        $account  = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player   = $this->insertPlayer( $this->team, 'Bas', $account );
        $teammate = $this->insertPlayer( $this->team, 'Kai', 0 );
        $this->assertGreaterThan( 0, $player );

        wp_set_current_user( $account );
        $this->assertSame( 403, $this->status( $teammate ) );
    }

    public function test_a_player_without_a_team_gets_an_empty_answer_not_an_error(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player  = $this->insertPlayer( 0, 'Bas', $account );

        wp_set_current_user( $account );
        $data = $this->read( $player );

        $this->assertNull( $data['team'] );
        $this->assertSame( [], $data['teammates'] );
    }

    // ---- fixtures ---------------------------------------------------------

    private function insertTeam( string $name, string $age_group ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name, 'age_group' => $age_group ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, int $account, ?int $jersey = null, string $positions = '' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'first_name'          => $first,
            'last_name'           => 'Willems',
            'status'              => 'active',
            'jersey_number'       => $jersey,
            'preferred_positions' => $positions,
            'wp_user_id'          => $account > 0 ? $account : null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function linkParent( int $parent, int $player ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $player,
            'parent_user_id' => $parent,
        ] );
    }

    // ---- helpers ----------------------------------------------------------

    /** @return array<string,mixed> */
    private function read( int $player_id ): array {
        $res = rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/players/' . $player_id . '/team' ) );
        $this->assertSame( 200, $res->get_status() );
        return (array) $res->get_data()['data'];
    }

    private function status( int $player_id ): int {
        return rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/players/' . $player_id . '/team' ) )->get_status();
    }
}
