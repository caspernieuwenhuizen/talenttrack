<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Search\ParentSearchService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3806 — a parent's search reaches their own children and stops there.
 *
 * The feature exists because a parent told a session was "in TalentTrack
 * now" had no way to find it. The risk it introduces is the opposite one:
 * a search box is the easiest place in a product to leak a record, and
 * these are minors.
 *
 * So the tests that matter are about the boundary. A parent finds both of
 * their own children and neither of anyone else's, and — the part that is
 * easy to get wrong — a query that WOULD match another family returns
 * exactly what a query matching nothing returns. A response that differs
 * between "exists but is not yours" and "does not exist" is a way of
 * discovering that a child exists.
 */
final class ParentSearchScopeTest extends WP_UnitTestCase {

    private int $parent_user = 0;
    private int $team_a      = 0;
    private int $team_b      = 0;
    private int $child_one   = 0;
    private int $child_two   = 0;
    private int $stranger    = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $this->parent_user = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Zoek O11-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Zoek O13-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->child_one = $this->player( $this->team_a, 'Sem' );
        $this->child_two = $this->player( $this->team_b, 'Fien' );
        $this->stranger  = $this->player( $this->team_b, 'Noor' );

        foreach ( [ $this->child_one, $this->child_two ] as $child ) {
            $wpdb->insert( "{$p}tt_player_parents", [
                'club_id'        => $club,
                'player_id'      => $child,
                'parent_user_id' => $this->parent_user,
            ] );
        }

        // One activity per team, both in the future, plus a goal on the
        // stranger's record carrying the same word.
        $this->activity( $this->team_a, 'Training dinsdag', '2099-11-03' );
        $this->activity( $this->team_b, 'Training donderdag', '2099-11-05' );

        $wpdb->insert( "{$p}tt_goals", [
            'club_id'    => $club,
            'player_id'  => $this->stranger,
            'title'      => 'Training harder werken',
            'created_by' => 1,
            'status'     => 'pending',
        ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_future_activity_is_found_which_is_the_whole_complaint(): void {
        $found = ( new ParentSearchService() )->search( $this->parent_user, 'Training dinsdag' );

        $titles = array_column( $found['results'], 'title' );
        $this->assertContains( 'Training dinsdag', $titles );
    }

    public function test_a_parent_with_two_children_searches_across_both_and_only_those(): void {
        $found = ( new ParentSearchService() )->search( $this->parent_user, 'Training' );

        $titles = array_column( $found['results'], 'title' );
        $this->assertContains( 'Training dinsdag', $titles );
        $this->assertContains( 'Training donderdag', $titles );

        // The stranger's goal carries the same word and belongs to a child
        // on a team this parent's own child is also on. Team membership is
        // not guardianship.
        $this->assertNotContains( 'Training harder werken', $titles );
    }

    public function test_a_match_on_another_family_reads_exactly_like_no_match_at_all(): void {
        $service = new ParentSearchService();

        $another_family = $service->search( $this->parent_user, 'harder werken' );
        $nothing_at_all = $service->search( $this->parent_user, 'zxqwvy' );

        $this->assertSame( [], $another_family['results'] );
        $this->assertSame( 0, $another_family['total'] );
        $this->assertSame( $nothing_at_all['results'], $another_family['results'] );
        $this->assertSame( $nothing_at_all['total'], $another_family['total'] );
    }

    public function test_a_user_with_no_linked_child_finds_nothing(): void {
        $stranger_user = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        $found = ( new ParentSearchService() )->search( $stranger_user, 'Training' );

        $this->assertSame( [], $found['results'] );
        $this->assertSame( [], $found['children'] );
    }

    public function test_one_character_is_not_a_search(): void {
        $found = ( new ParentSearchService() )->search( $this->parent_user, 'T' );

        $this->assertSame( [], $found['results'] );
    }

    public function test_a_date_query_is_read_as_a_date_and_a_word_is_not(): void {
        $this->assertSame( '2026-11-03', ParentSearchService::asDate( '2026-11-03' ) );
        // No digit, so `strtotime()` never gets to read it as "now" — which
        // would make every text search also match today.
        $this->assertSame( '', ParentSearchService::asDate( 'training' ) );
        $this->assertSame( '', ParentSearchService::asDate( 'now' ) );
    }

    public function test_the_rest_route_answers_the_caller_and_nobody_else(): void {
        wp_set_current_user( $this->parent_user );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/me/search' );
        $request->set_query_params( [ 'q' => 'Training' ] );
        $response = rest_do_request( $request );

        $this->assertSame( 200, $response->get_status() );
        $data    = (array) $response->get_data();
        $payload = (array) ( $data['data'] ?? [] );

        $titles = array_column( (array) $payload['results'], 'title' );
        $this->assertContains( 'Training dinsdag', $titles );
        $this->assertNotContains( 'Training harder werken', $titles );
        $this->assertCount( 2, (array) $payload['children'] );
    }

    // ---- fixtures ---------------------------------------------------------

    private function player( int $team, string $first_name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'team_id'    => $team,
            'first_name' => $first_name,
            'last_name'  => 'Zoeker',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function activity( int $team, string $title, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'      => (int) CurrentClub::id(),
            'team_id'      => $team,
            'title'        => $title,
            'session_date' => $date,
            'location'     => 'Trainingsveld',
        ] );
        return (int) $wpdb->insert_id;
    }
}
