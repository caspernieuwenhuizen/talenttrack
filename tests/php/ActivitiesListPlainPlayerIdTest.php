<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3642 — `GET activities` honours a plain `player_id`.
 *
 * #3584 folded the plain `team_id` and date names into the filter but left
 * `player_id` out. A parent asking `?player_id=<child>` got 200 with an
 * empty list, which reads as "my child has nothing planned", while
 * `filter[player_id]=<child>` returned the team schedule. The plain name is
 * now folded like the others, and it goes through the same player / parent
 * scoping, so another player's id still returns nothing.
 */
final class ActivitiesListPlainPlayerIdTest extends WP_UnitTestCase {

    private int $club      = 0;
    private int $teamA     = 0;
    private int $teamB     = 0;
    private int $playerA   = 0;
    private int $playerB   = 0;
    private int $activityA = 0;
    private int $activityB = 0;

    private int $coach      = 0;
    private int $parent     = 0;
    private int $playerUser = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Plain JO11-1' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Plain JO15-1' ] );
        $this->teamB = (int) $wpdb->insert_id;

        $this->playerUser = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->teamA,
            'first_name' => 'Sem',
            'last_name'  => 'Plain',
            'status'     => 'active',
            'wp_user_id' => $this->playerUser,
        ] );
        $this->playerA = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->teamB,
            'first_name' => 'Lars',
            'last_name'  => 'Other',
            'status'     => 'active',
        ] );
        $this->playerB = (int) $wpdb->insert_id;

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->playerA,
            'parent_user_id' => $this->parent,
        ] );

        $this->activityA = $this->activity( $this->teamA, 'JO11 training' );
        $this->activityB = $this->activity( $this->teamB, 'JO15 training' );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Plain',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->teamA,
        ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_parent_gets_their_childs_activities_with_the_plain_name(): void {
        $plain  = $this->listIds( $this->parent, [ 'player_id' => $this->playerA ] );
        $nested = $this->listIds( $this->parent, [ 'filter' => [ 'player_id' => $this->playerA ] ] );

        $this->assertContains( $this->activityA, $plain );
        $this->assertNotContains( $this->activityB, $plain );
        $this->assertSame( $nested, $plain );
    }

    public function test_a_parent_asking_for_another_player_sees_nothing_of_theirs(): void {
        $this->assertDoesNotSee(
            $this->parent,
            [ 'player_id' => $this->playerB ],
            $this->activityB,
            'The plain name must not widen what a parent sees.'
        );
    }

    public function test_a_player_asking_for_another_player_does_not_see_their_team(): void {
        $this->assertDoesNotSee(
            $this->playerUser,
            [ 'player_id' => $this->playerB ],
            $this->activityB
        );
    }

    public function test_the_nested_player_id_wins_over_the_plain_one(): void {
        $ids = $this->listIds( $this->parent, [
            'player_id' => $this->playerB,
            'filter'    => [ 'player_id' => $this->playerA ],
        ] );
        $this->assertContains( $this->activityA, $ids );

        $this->assertDoesNotSee(
            $this->parent,
            [ 'player_id' => $this->playerA, 'filter' => [ 'player_id' => $this->playerB ] ],
            $this->activityB,
            'A plain child id must not rescue a nested id the parent may not read.'
        );
    }

    public function test_a_coachs_results_are_unchanged(): void {
        $all = $this->listIds( $this->coach, [] );
        $this->assertContains( $this->activityA, $all );
        $this->assertNotContains( $this->activityB, $all );

        $this->assertSame(
            $this->listIds( $this->coach, [ 'filter' => [ 'player_id' => $this->playerA ] ] ),
            $this->listIds( $this->coach, [ 'player_id' => $this->playerA ] )
        );
        $this->assertNotContains(
            $this->activityB,
            $this->listIds( $this->coach, [ 'player_id' => $this->playerB ] ),
            'A player on a team the coach does not coach stays out.'
        );
    }

    public function test_the_route_declares_player_id(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/activities'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = $handler['args'] ?? [];
        }
        $this->assertArrayHasKey( 'player_id', $args );
    }

    /**
     * A caller asking for a player they may not read learns nothing about
     * them: the request is refused, or it answers without that player's
     * activities. Which of the two depends on whether the caller also holds
     * the staff activities capability, and neither is a leak.
     *
     * @param array<string,mixed> $query
     */
    private function assertDoesNotSee( int $user_id, array $query, int $activity_id, string $message = '' ): void {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( $query + [ 'per_page' => 100 ] );
        $res = rest_do_request( $req );

        if ( $res->get_status() !== 200 ) {
            $this->assertSame( 403, $res->get_status(), $message );
            return;
        }
        $ids = [];
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }
        $this->assertNotContains( $activity_id, $ids, $message );
    }

    private function activity( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => $title,
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+2 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The ids `GET /activities` returns for this caller, sorted.
     *
     * @param array<string,mixed> $query
     * @return list<int>
     */
    private function listIds( int $user_id, array $query ): array {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( $query + [ 'per_page' => 100 ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        $ids = [];
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }
        sort( $ids );
        return $ids;
    }
}
