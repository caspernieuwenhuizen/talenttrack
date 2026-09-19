<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3666 — `GET /players/{id}/minutes`: a player's own playing time.
 *
 * Both existing minutes routes are staff surfaces by construction — one
 * wants `tt_view_reports` and a coached team, the other is the team's
 * minutes-share table — so a player asking how much they had played got
 * `rest_forbidden` for their own record. This route answers that question
 * and nothing wider: the negative assertions are the important ones, since
 * the failure mode here is a young person being shown their team-mates'
 * playing time.
 */
final class PlayerOwnMinutesRouteTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';
    private const FROM = '2026-01-01';
    private const TO   = '2026-12-31';

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

        $this->team = $this->insertTeam( 'Hedel JO13-1' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_route_is_registered(): void {
        $this->assertArrayHasKey(
            self::BASE . '/players/(?P<id>\d+)/minutes',
            rest_get_server()->get_routes()
        );
    }

    public function test_an_anonymous_caller_is_refused(): void {
        $player = $this->insertPlayer( $this->team, 'Bas', 0 );
        wp_set_current_user( 0 );

        $this->assertContains( $this->status( $player ), [ 401, 403 ] );
    }

    public function test_a_player_reads_their_own_minutes_and_they_match_the_staff_figure(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player  = $this->insertPlayer( $this->team, 'Bas', $account );

        $first  = $this->insertMatch( $this->team, '2026-03-07', 'Ajax away' );
        $second = $this->insertMatch( $this->team, '2026-03-14', 'PSV home' );
        $this->insertMinutes( $first, $player, 45 );
        $this->insertMinutes( $second, $player, 70 );

        wp_set_current_user( $account );
        $data = $this->read( $player );

        $this->assertSame( 115, $data['total_minutes'] );
        $this->assertSame( $this->team, $data['team_id'] );
        $this->assertSame( $player, $data['player_id'] );
        $this->assertCount( 2, $data['matches'] );
        $this->assertSame( [ 45, 70 ], array_column( $data['matches'], 'minutes' ) );

        // The same window through the coach's route must agree to the minute,
        // or the player and their coach are reading two different seasons.
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        $staff = rest_do_request( $this->request(
            self::BASE . '/teams/' . $this->team . '/players/' . $player . '/minutes'
        ) );
        $this->assertSame( 200, $staff->get_status() );
        $this->assertSame( 115, $staff->get_data()['data']['total_minutes'] );
    }

    public function test_the_answer_carries_no_share_and_no_other_players(): void {
        $account  = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player   = $this->insertPlayer( $this->team, 'Bas', $account );
        $teammate = $this->insertPlayer( $this->team, 'Kai', 0 );

        $match = $this->insertMatch( $this->team, '2026-03-07', 'Ajax away' );
        $this->insertMinutes( $match, $player, 45 );
        $this->insertMinutes( $match, $teammate, 70 );

        wp_set_current_user( $account );
        $data = $this->read( $player );

        $this->assertSame( 45, $data['total_minutes'], 'only the caller\'s own minutes are counted' );
        foreach ( [ 'share_pct', 'available_minutes', 'target_pct', 'players', 'rows' ] as $forbidden ) {
            $this->assertArrayNotHasKey( $forbidden, $data );
        }
        foreach ( $data['matches'] as $row ) {
            $this->assertSame(
                [ 'activity_id', 'session_date', 'title', 'type_key', 'minutes', 'record_type' ],
                array_keys( (array) $row ),
                'a match row says what happened in that match, nothing about anyone else'
            );
        }
    }

    public function test_a_player_is_refused_a_teammates_minutes_and_the_team_routes_are_unchanged(): void {
        $account  = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player   = $this->insertPlayer( $this->team, 'Bas', $account );
        $teammate = $this->insertPlayer( $this->team, 'Kai', 0 );

        wp_set_current_user( $account );

        $this->assertSame( 403, $this->status( $teammate ) );
        $this->assertContains(
            rest_do_request( $this->request(
                self::BASE . '/teams/' . $this->team . '/players/' . $player . '/minutes'
            ) )->get_status(),
            [ 401, 403 ],
            'the staff breakdown route stays staff-only'
        );
        $this->assertContains(
            rest_do_request( $this->request(
                self::BASE . '/teams/' . $this->team . '/minutes-share/' . $player
            ) )->get_status(),
            [ 401, 403 ],
            'the team minutes-share route stays staff-only'
        );
    }

    public function test_a_linked_parent_reads_their_own_child_only(): void {
        $parent  = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $child   = $this->insertPlayer( $this->team, 'Bas', 0 );
        $another = $this->insertPlayer( $this->team, 'Kai', 0 );
        $this->linkParent( $parent, $child );

        $match = $this->insertMatch( $this->team, '2026-03-07', 'Ajax away' );
        $this->insertMinutes( $match, $child, 60 );

        wp_set_current_user( $parent );

        $this->assertSame( 60, $this->read( $child )['total_minutes'] );
        $this->assertSame( 403, $this->status( $another ) );
    }

    public function test_a_child_can_keep_their_playing_time_from_a_parent(): void {
        $this->assertContains(
            'minutes',
            PlayerParentVisibilityRepository::SECTIONS,
            'playing time must be a section a player can switch off'
        );

        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $child  = $this->insertPlayer( $this->team, 'Bas', 0 );
        $this->linkParent( $parent, $child );

        $match = $this->insertMatch( $this->team, '2026-03-07', 'Ajax away' );
        $this->insertMinutes( $match, $child, 60 );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $child, 'minutes', false );

        wp_set_current_user( $parent );
        $res = rest_do_request( $this->request( self::BASE . '/players/' . $child . '/minutes' ) );

        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( 'section_private', $res->get_data()['errors'][0]['code'] ?? '' );
    }

    public function test_staff_read_the_player_and_an_unrelated_coach_does_not(): void {
        $player = $this->insertPlayer( $this->team, 'Bas', 0 );
        $match  = $this->insertMatch( $this->team, '2026-03-07', 'Ajax away' );
        $this->insertMinutes( $match, $player, 60 );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_head_dev' ] ) );
        $this->assertSame( 60, $this->read( $player )['total_minutes'] );

        // A coach with no scope over this player — the other team's coach in
        // every academy that has more than one.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );
        $this->assertSame( 403, $this->status( $player ) );
    }

    public function test_a_player_without_a_team_gets_an_empty_answer_not_an_error(): void {
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $player  = $this->insertPlayer( 0, 'Bas', $account );

        wp_set_current_user( $account );
        $data = $this->read( $player );

        $this->assertSame( 0, $data['total_minutes'] );
        $this->assertSame( [], $data['matches'] );
    }

    // ---- fixtures ---------------------------------------------------------

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, int $account ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => 'Willems',
            'status'     => 'active',
            'wp_user_id' => $account > 0 ? $account : null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => $date,
            'activity_type_key' => 'match',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMinutes( int $activity_id, int $player_id, int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
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

    private function request( string $route ): WP_REST_Request {
        $req = new WP_REST_Request( 'GET', $route );
        $req->set_param( 'from', self::FROM );
        $req->set_param( 'to', self::TO );
        return $req;
    }

    /** @return array<string,mixed> */
    private function read( int $player_id ): array {
        $res = rest_do_request( $this->request( self::BASE . '/players/' . $player_id . '/minutes' ) );
        $this->assertSame( 200, $res->get_status() );
        return (array) $res->get_data()['data'];
    }

    private function status( int $player_id ): int {
        return rest_do_request( $this->request( self::BASE . '/players/' . $player_id . '/minutes' ) )->get_status();
    }
}
