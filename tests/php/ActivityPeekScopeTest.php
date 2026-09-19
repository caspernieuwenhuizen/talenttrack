<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3688 — the activity peek asks whose activity it is.
 *
 * `GET /activities/{id}/summary` gated on `tt_view_activities` alone. That
 * capability is club-wide, so the head coach of one team could peek every
 * other team's trainings by walking ids, and the matrix hands it to players
 * and parents too. The peek now asks `ActivityAccess::canRead()`, the
 * single-record form of the rule `GET /activities` applies to its rows, and
 * the list asks the same building blocks. Every persona is checked in both
 * directions, and the list is checked to still return what it did.
 */
final class ActivityPeekScopeTest extends WP_UnitTestCase {

    private int $club      = 0;
    private int $teamA     = 0;
    private int $teamB     = 0;
    private int $playerA   = 0;
    private int $activityA = 0;
    private int $activityB = 0;

    private int $coach      = 0;
    private int $hod        = 0;
    private int $parent     = 0;
    private int $playerUser = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // Roles install on activation, which the test bootstrap does not
        // fire; without them `tt_coach` holds no `tt_view_activities` and
        // the refusals below would pass for the wrong reason.
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Peek JO11-1' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Peek JO17-1' ] );
        $this->teamB = (int) $wpdb->insert_id;

        $this->playerUser = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->teamA,
            'first_name' => 'Daan',
            'last_name'  => 'Peek',
            'status'     => 'active',
            'wp_user_id' => $this->playerUser,
        ] );
        $this->playerA = (int) $wpdb->insert_id;

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->playerA,
            'parent_user_id' => $this->parent,
        ] );

        $this->activityA = $this->activity( $this->teamA, 'JO11 training' );
        $this->activityB = $this->activity( $this->teamB, 'JO17 training' );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Peek',
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

        $this->hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
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

    private function peek( int $user_id, int $activity_id ): int {
        wp_set_current_user( $user_id );
        $res = rest_do_request(
            new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $activity_id . '/summary' )
        );
        return $res->get_status();
    }

    /**
     * The ids `GET /activities` returns for this caller.
     *
     * @param array<string,mixed> $filter
     * @return list<int>
     */
    private function listIds( int $user_id, array $filter = [] ): array {
        wp_set_current_user( $user_id );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [ 'filter' => $filter, 'per_page' => 100 ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        $ids = [];
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }
        return $ids;
    }

    // ---------------------------------------------------------------
    // the peek, per persona
    // ---------------------------------------------------------------

    public function test_a_head_coach_peeks_their_own_team_and_not_another(): void {
        $this->assertSame( 200, $this->peek( $this->coach, $this->activityA ) );
        $this->assertSame(
            403,
            $this->peek( $this->coach, $this->activityB ),
            'A club-wide capability is not an answer about one team\'s training.'
        );
    }

    public function test_global_read_peeks_every_team(): void {
        $this->assertSame( 200, $this->peek( $this->hod, $this->activityA ) );
        $this->assertSame( 200, $this->peek( $this->hod, $this->activityB ) );
    }

    public function test_a_parent_peeks_their_childs_team_only(): void {
        $this->assertSame( 200, $this->peek( $this->parent, $this->activityA ) );
        $this->assertSame( 403, $this->peek( $this->parent, $this->activityB ) );
    }

    public function test_a_player_peeks_their_own_team_only(): void {
        $this->assertSame( 200, $this->peek( $this->playerUser, $this->activityA ) );
        $this->assertSame( 403, $this->peek( $this->playerUser, $this->activityB ) );
    }

    /**
     * The list shows a player the activities they are on the register of,
     * a guest appearance for another team included. The peek agrees.
     */
    public function test_a_guest_appearance_is_readable_by_the_player(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'         => $this->club,
            'activity_id'     => $this->activityB,
            'player_id'       => null,
            'guest_player_id' => $this->playerA,
            'is_guest'        => 1,
            'status'          => 'Present',
            'record_type'     => 'actual',
        ] );

        $this->assertSame( 200, $this->peek( $this->playerUser, $this->activityB ) );
        $this->assertContains(
            $this->activityB,
            $this->listIds( $this->playerUser, [ 'player_id' => $this->playerA ] )
        );
    }

    public function test_an_unknown_id_is_a_404_only_for_activity_readers(): void {
        $missing = $this->activityB + 100000;
        $this->assertSame( 404, $this->peek( $this->coach, $missing ) );

        $outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertSame(
            403,
            $this->peek( $outsider, $missing ),
            'A caller who reads no activities learns nothing from the difference between 403 and 404.'
        );
        $this->assertSame( 403, $this->peek( $outsider, $this->activityA ) );
    }

    public function test_the_predicate_refuses_a_logged_out_caller_and_a_missing_row(): void {
        $this->assertFalse(
            ActivityAccess::canRead( 0, (object) [ 'id' => $this->activityA, 'team_id' => $this->teamA ] )
        );
        $this->assertFalse(
            ActivityAccess::canRead( $this->hod, (object) [ 'id' => 0, 'team_id' => $this->teamA ] ),
            'Id 0 is "no activity", never "any activity".'
        );
    }

    // ---------------------------------------------------------------
    // GET /activities — the same rows as before, per persona
    // ---------------------------------------------------------------

    public function test_the_list_still_narrows_a_coach_to_their_teams(): void {
        $ids = $this->listIds( $this->coach );
        $this->assertContains( $this->activityA, $ids );
        $this->assertNotContains( $this->activityB, $ids );
    }

    public function test_the_list_still_shows_global_read_every_team(): void {
        $ids = $this->listIds( $this->hod );
        $this->assertContains( $this->activityA, $ids );
        $this->assertContains( $this->activityB, $ids );
    }

    public function test_the_list_still_scopes_a_parent_to_their_child(): void {
        $ids = $this->listIds( $this->parent, [ 'player_id' => $this->playerA ] );
        $this->assertContains( $this->activityA, $ids );
        $this->assertNotContains( $this->activityB, $ids );
    }

    public function test_the_list_still_scopes_a_player_to_their_team(): void {
        $ids = $this->listIds( $this->playerUser, [ 'player_id' => $this->playerA ] );
        $this->assertContains( $this->activityA, $ids );
        $this->assertNotContains( $this->activityB, $ids );
    }
}
