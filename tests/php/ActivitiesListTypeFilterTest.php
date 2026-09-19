<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3687 — `GET activities` narrows to an activity type.
 *
 * The rendered list has taken `?activity_type_key=` since the Type select
 * was added to its filter bar; REST had no equivalent, so a caller asking
 * for matches was answered 200 with a page of trainings and read it as
 * "there are no matches". The filter now exists in both forms, refuses a
 * key the install does not know, and — the part worth a test — can only
 * narrow what a caller already sees.
 */
final class ActivitiesListTypeFilterTest extends WP_UnitTestCase {

    private int $club  = 0;
    private int $teamA = 0;
    private int $teamB = 0;

    private int $playerA = 0;

    private int $trainingA   = 0;
    private int $gameA       = 0;
    private int $tournamentA = 0;
    private int $gameB       = 0;

    private int $reader = 0;
    private int $coach  = 0;
    private int $parent = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $this->seedActivityTypes();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Type JO13-1' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Type JO17-1' ] );
        $this->teamB = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->teamA,
            'first_name' => 'Tycho',
            'last_name'  => 'Type',
            'status'     => 'active',
        ] );
        $this->playerA = (int) $wpdb->insert_id;

        $this->trainingA   = $this->activity( $this->teamA, 'training', 'JO13 training' );
        $this->gameA       = $this->activity( $this->teamA, 'game', 'JO13 v Hedel' );
        $this->tournamentA = $this->activity( $this->teamA, 'tournament', 'JO13 toernooi' );
        $this->gameB       = $this->activity( $this->teamB, 'game', 'JO17 v Ajax' );

        // A global reader: an administrator holds `tt_edit_settings`, which
        // is the shortest route to the global-read branch the observer and
        // the head of development reach through the matrix.
        $this->reader = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Type',
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

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->playerA,
            'parent_user_id' => $this->parent,
        ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_filter_returns_one_type_in_both_forms(): void {
        $nested = $this->listIds( $this->reader, [ 'filter' => [ 'activity_type_key' => 'game' ] ] );

        $this->assertContains( $this->gameA, $nested );
        $this->assertContains( $this->gameB, $nested );
        $this->assertNotContains( $this->trainingA, $nested );
        $this->assertNotContains( $this->tournamentA, $nested );

        $this->assertSame(
            $nested,
            $this->listIds( $this->reader, [ 'activity_type_key' => 'game' ] ),
            'The plain name must answer exactly what the nested one answers.'
        );
    }

    public function test_a_csv_returns_every_type_it_names_and_nothing_else(): void {
        $ids = $this->listIds( $this->reader, [ 'activity_type_key' => 'game,tournament' ] );

        $this->assertContains( $this->gameA, $ids );
        $this->assertContains( $this->tournamentA, $ids );
        $this->assertContains( $this->gameB, $ids );
        $this->assertNotContains( $this->trainingA, $ids );
    }

    public function test_an_unknown_type_is_refused_and_named(): void {
        wp_set_current_user( $this->reader );
        $res = $this->request( [ 'activity_type_key' => 'nonsense' ] );

        $this->assertSame( 400, $res->get_status() );
        $data  = (array) $res->get_data();
        $first = (array) ( ( (array) ( $data['errors'] ?? [] ) )[0] ?? [] );
        $this->assertSame( 'bad_activity_type', (string) ( $first['code'] ?? '' ) );

        $details = (array) ( $first['details'] ?? [] );
        $this->assertSame( 'activity_type_key', $details['param'] ?? '' );
        $this->assertSame( [ 'nonsense' ], array_values( (array) ( $details['unknown'] ?? [] ) ) );
        $this->assertContains( 'game', (array) ( $details['allowed'] ?? [] ) );
    }

    public function test_one_unknown_key_in_a_csv_refuses_the_whole_request(): void {
        wp_set_current_user( $this->reader );
        $res = $this->request( [ 'activity_type_key' => 'game,nonsense' ] );

        $this->assertSame( 400, $res->get_status(), 'A partly-wrong filter must not answer as if it worked.' );
    }

    public function test_the_nested_value_wins_over_the_plain_one(): void {
        $ids = $this->listIds( $this->reader, [
            'activity_type_key' => 'training',
            'filter'            => [ 'activity_type_key' => 'game' ],
        ] );

        $this->assertContains( $this->gameA, $ids );
        $this->assertNotContains( $this->trainingA, $ids );
    }

    public function test_an_empty_filter_leaves_the_list_alone(): void {
        $this->assertSame(
            $this->listIds( $this->reader, [] ),
            $this->listIds( $this->reader, [ 'activity_type_key' => '' ] )
        );
    }

    public function test_the_filter_never_reaches_past_a_coachs_teams(): void {
        $ids = $this->listIds( $this->coach, [ 'activity_type_key' => 'game' ] );

        $this->assertContains( $this->gameA, $ids );
        $this->assertNotContains( $this->gameB, $ids, 'A type filter must not widen a coach scope.' );
        $this->assertNotContains( $this->trainingA, $ids );
    }

    public function test_a_parent_filtering_sees_only_their_childs_matches(): void {
        $ids = $this->listIds( $this->parent, [
            'player_id'         => $this->playerA,
            'activity_type_key' => 'game',
        ] );

        $this->assertContains( $this->gameA, $ids );
        $this->assertNotContains( $this->gameB, $ids );
        $this->assertNotContains( $this->trainingA, $ids );
    }

    public function test_the_route_declares_the_parameter(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()['/talenttrack/v1/activities'] ?? [] as $handler ) {
            if ( ! empty( $handler['methods']['GET'] ) ) $args = $handler['args'] ?? [];
        }
        $this->assertArrayHasKey( 'activity_type_key', $args );
        $this->assertNotSame( '', (string) ( $args['activity_type_key']['description'] ?? '' ) );
    }

    /**
     * The `activity_type` lookup is seeded by migration, but this test
     * asserts on the exact allowed set, so it makes sure the keys it uses
     * are there rather than assuming the migration state.
     */
    private function seedActivityTypes(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $present = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = 'activity_type' AND club_id = %d",
            $this->club
        ) );
        $sort = 0;
        foreach ( [ 'training', 'game', 'tournament', 'other', 'meeting' ] as $name ) {
            $sort++;
            if ( in_array( $name, $present, true ) ) continue;
            $wpdb->insert( "{$p}tt_lookups", [
                'club_id'     => $this->club,
                'lookup_type' => 'activity_type',
                'name'        => $name,
                'sort_order'  => $sort,
            ] );
        }
    }

    private function activity( int $team_id, string $type, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => $title,
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
            'activity_type_key'   => $type,
            'activity_status_key' => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $query
     */
    private function request( array $query ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( $query + [ 'per_page' => 100 ] );
        return rest_do_request( $req );
    }

    /**
     * The ids `GET /activities` returns for this caller, sorted.
     *
     * @param array<string,mixed> $query
     * @return list<int>
     */
    private function listIds( int $user_id, array $query ): array {
        wp_set_current_user( $user_id );
        $res = $this->request( $query );
        $this->assertSame( 200, $res->get_status() );

        $ids = [];
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }
        sort( $ids );
        return $ids;
    }
}
