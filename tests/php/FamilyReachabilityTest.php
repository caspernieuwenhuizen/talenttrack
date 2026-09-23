<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Alerts\Services\FamilyReachability;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Players\Services\DossierCompletenessService;

/**
 * #4014 — the combined "family reachable" count.
 *
 * The reported case is the whole test: on 18 Apr the U7 squad had guardian
 * fields filled for 0 of 21 players and parent accounts linked for 3, and
 * three other squads had neither. A board member called four per-team
 * reports, added the columns up by hand, asked an administrator to confirm
 * it, and minuted "3 of 82 families reachable".
 *
 * Two properties matter beyond the arithmetic:
 *
 *  - reachable is **derived**, never a replacement. The six dossier checks
 *    still report the guardian columns and the parent-account link
 *    separately, because an account is how a parent reads their child's
 *    record and the columns are how the club phones somebody on a Saturday.
 *  - the club-wide answer **names nobody**. Counts and team names only.
 */
final class FamilyReachabilityTest extends WP_UnitTestCase {

    private string $p = '';

    private int $club = 1;

    /** @var array<string,int> */
    private array $teams = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        // DELETE, not TRUNCATE: TRUNCATE commits and breaks the rollback the
        // test case relies on. The census counts every player on the books,
        // so a row another test left behind would move the total.
        $wpdb->query( "DELETE FROM {$this->p}tt_player_parents" );
        $wpdb->query( "DELETE FROM {$this->p}tt_players" );

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        // The 18 Apr baseline, to the player: 21 + 21 + 20 + 20 = 82, of
        // which exactly three U7 players have a linked parent account and
        // nobody anywhere has a guardian e-mail address or phone number.
        $this->teams = [
            'U7'  => $this->insertTeam( 'JO7-1' ),
            'U12' => $this->insertTeam( 'JO12-1' ),
            'U17' => $this->insertTeam( 'JO17-1' ),
            'U23' => $this->insertTeam( 'JO23-1' ),
        ];

        $this->fill( $this->teams['U7'], 21, 3 );
        $this->fill( $this->teams['U12'], 21, 0 );
        $this->fill( $this->teams['U17'], 20, 0 );
        $this->fill( $this->teams['U23'], 20, 0 );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_board_question_is_answered_in_one_call(): void {
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $census = FamilyReachability::forUser( $hod );

        $this->assertSame( 82, $census['total'] );
        $this->assertSame( 3, $census['reachable'] );
        $this->assertSame( 79, $census['unreachable'] );
        $this->assertSame( 'club', $census['scope'] );
    }

    public function test_the_per_team_breakdown_matches_the_reported_numbers(): void {
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $by = [];
        foreach ( FamilyReachability::forUser( $hod )['teams'] as $team ) {
            $by[ (int) $team['team_id'] ] = $team;
        }

        $this->assertSame( 21, $by[ $this->teams['U7'] ]['total'] );
        $this->assertSame( 3, $by[ $this->teams['U7'] ]['reachable'] );
        $this->assertSame( 0, $by[ $this->teams['U12'] ]['reachable'] );
        $this->assertSame( 0, $by[ $this->teams['U17'] ]['reachable'] );
        $this->assertSame( 0, $by[ $this->teams['U23'] ]['reachable'] );
    }

    /**
     * A guardian phone with no e-mail address and no account is still a way
     * to reach somebody on a Saturday morning. Reachability is a union of
     * three routes, not the guardian e-mail column under a friendlier name.
     */
    public function test_any_one_of_the_three_routes_makes_a_family_reachable(): void {
        $team = $this->insertTeam( 'JO15-1' );

        $this->player( $team, [ 'guardian_email' => 'mum@example.test' ] );
        $this->player( $team, [ 'guardian_phone' => '0612345678' ] );
        $account = $this->player( $team, [] );
        $this->linkParent( $account );
        $this->player( $team, [] );

        $this->assertSame(
            [ 'total' => 4, 'reachable' => 3, 'unreachable' => 1 ],
            FamilyReachability::forTeam( $team )
        );
    }

    /**
     * The rescope's load-bearing constraint. If "reachable" ever starts
     * standing in for the guardian checks, a report will tell an
     * administrator a file is complete when there is still nobody to call.
     */
    public function test_the_derived_signal_does_not_replace_the_six_checks(): void {
        $report = ( new DossierCompletenessService() )->forTeam( $this->teams['U7'] );

        $keys = array_column( $report['checks'], 'key' );
        $this->assertSame( DossierCompletenessService::checkKeys(), $keys );

        $by = [];
        foreach ( $report['checks'] as $check ) $by[ (string) $check['key'] ] = $check;

        // The two facts the administrator read as disagreeing: both are
        // still reported, and separately.
        $this->assertSame( 0, (int) $by[ DossierCompletenessService::GUARDIAN_EMAIL ]['complete'] );
        $this->assertSame( 3, (int) $by[ DossierCompletenessService::PARENT_ACCOUNT ]['complete'] );

        // And what they add up to, now said out loud.
        $this->assertSame( 21, $report['family_reachable']['total'] );
        $this->assertSame( 3, $report['family_reachable']['reachable'] );
    }

    /**
     * One rule, two call sites. The dossier report computes reachability
     * from the roster it already read rather than asking the service again,
     * so this is the assertion that keeps them from drifting.
     */
    public function test_the_team_report_and_the_census_agree(): void {
        foreach ( $this->teams as $team_id ) {
            $report = ( new DossierCompletenessService() )->forTeam( $team_id );
            $this->assertSame(
                FamilyReachability::forTeam( $team_id ),
                $report['family_reachable']
            );
        }
    }

    public function test_the_club_wide_payload_names_no_family(): void {
        $hod    = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $census = FamilyReachability::forUser( $hod );

        $json = (string) wp_json_encode( $census );

        $this->assertStringNotContainsString( 'Reachable', $json, 'no player name' );
        $this->assertStringNotContainsString( 'example.test', $json, 'no contact detail' );
        $this->assertStringNotContainsString( 'player_id', $json );
        $this->assertStringNotContainsString( 'guardian', $json );
    }

    /**
     * A coach assigned to one squad gets their squad's numbers, and totals
     * over their squads only. The scope is computed in the service, so there
     * is no parameter that could widen it.
     */
    public function test_a_team_scoped_coach_sees_only_their_own_squads(): void {
        $coach = $this->coachOf( $this->teams['U12'] );

        $census = FamilyReachability::forUser( $coach );

        $this->assertSame( 'teams', $census['scope'] );
        $this->assertCount( 1, $census['teams'] );
        $this->assertSame( $this->teams['U12'], (int) $census['teams'][0]['team_id'] );
        $this->assertSame( 21, $census['total'] );
    }

    public function test_the_route_answers_the_read_only_observer(): void {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        wp_set_current_user( $observer );

        $response = rest_do_request(
            new WP_REST_Request( 'GET', '/talenttrack/v1/alerts/family-reachability' )
        );

        $this->assertSame( 200, $response->get_status() );

        $data    = $response->get_data();
        $payload = is_array( $data ) && isset( $data['data'] ) ? $data['data'] : $data;

        $this->assertIsArray( $payload );
        $this->assertSame( 82, (int) $payload['total'] );
        $this->assertSame( 3, (int) $payload['reachable'] );
    }

    public function test_the_route_refuses_a_caller_with_no_people_read(): void {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = rest_do_request(
            new WP_REST_Request( 'GET', '/talenttrack/v1/alerts/family-reachability' )
        );

        $this->assertContains( $response->get_status(), [ 401, 403 ] );
    }

    // Helpers

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    /** `$withAccount` players get a linked parent account; nobody gets guardian fields. */
    private function fill( int $team_id, int $size, int $withAccount ): void {
        for ( $i = 0; $i < $size; $i++ ) {
            $player_id = $this->player( $team_id, [] );
            if ( $i < $withAccount ) $this->linkParent( $player_id );
        }
    }

    /** @param array<string,mixed> $extra */
    private function player( int $team_id, array $extra ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", array_merge( [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Reachable',
            'last_name'  => 'Test',
            'status'     => 'active',
        ], $extra ) );
        return (int) $wpdb->insert_id;
    }

    private function linkParent( int $player_id ): void {
        global $wpdb;
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'club_id'   => $this->club,
            'player_id'      => $player_id,
            'parent_user_id' => $parent,
            'is_primary'     => 1,
        ] );
    }

    private function coachOf( int $team_id ): int {
        global $wpdb;
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Reach',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        return $coach;
    }
}
