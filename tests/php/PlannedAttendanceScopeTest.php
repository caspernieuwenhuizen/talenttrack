<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * `GET activities/{id}/planned-attendance` reads on the terms of the
 * activity it belongs to.
 *
 * The roster carries names, the coach's notes and availability. The route
 * asked only `can_view()`, which answers "may you read activities" and,
 * for a parent, "is this your child" about the `player_id` in the query,
 * not about the activity in the URL. Staff now get the roster for the
 * teams they cover, a parent or player only their own child's row on an
 * activity that child is part of, and anyone else a 403.
 */
final class PlannedAttendanceScopeTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $teamA = 0;
    private int $teamB = 0;
    private int $child = 0;
    private int $teammate = 0;
    private int $otherPlayer = 0;
    private int $activityA = 0;
    private int $activityB = 0;
    private int $parent = 0;
    private int $coachA = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Roster JO11-1' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Roster JO15-1' ] );
        $this->teamB = (int) $wpdb->insert_id;

        $this->child       = $this->player( 'Child', $this->teamA );
        $this->teammate    = $this->player( 'Teammate', $this->teamA );
        $this->otherPlayer = $this->player( 'Other', $this->teamB );

        $this->activityA = $this->activity( $this->teamA );
        $this->activityB = $this->activity( $this->teamB );
        $this->plan( $this->activityA, $this->child );
        $this->plan( $this->activityA, $this->teammate );
        $this->plan( $this->activityB, $this->otherPlayer );

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->child,
            'parent_user_id' => $this->parent,
        ] );

        $this->coachA = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Roster',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $this->coachA,
            'status'     => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $this->teamA,
            'person_id'     => $person,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->teamA,
        ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_coach_reads_their_own_teams_roster_and_not_another_teams(): void {
        wp_set_current_user( $this->coachA );

        $this->assertSame( [ $this->child, $this->teammate ], $this->rosterIds( $this->activityA ) );
        $this->assertSame( 403, $this->get( $this->activityB )->get_status() );
    }

    public function test_a_parent_reads_only_their_own_childs_row(): void {
        wp_set_current_user( $this->parent );

        $this->assertSame( [ $this->child ], $this->rosterIds( $this->activityA, $this->child ), 'the teammate on the same plan is not theirs to read' );
    }

    public function test_a_parent_is_refused_on_another_teams_activity(): void {
        wp_set_current_user( $this->parent );

        $this->assertSame( 403, $this->get( $this->activityB, $this->child )->get_status() );
    }

    public function test_an_administrator_reads_every_roster(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->assertSame( [ $this->otherPlayer ], $this->rosterIds( $this->activityB ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function get( int $activity_id, int $player_id = 0 ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $activity_id . '/planned-attendance' );
        if ( $player_id > 0 ) {
            $req->set_query_params( [ 'player_id' => $player_id ] );
        }
        return rest_do_request( $req );
    }

    /** @return list<int> */
    private function rosterIds( int $activity_id, int $player_id = 0 ): array {
        $res = $this->get( $activity_id, $player_id );
        $this->assertSame( 200, $res->get_status() );
        $ids = array_map(
            static fn( $row ): int => (int) ( (array) $row )['player_id'],
            array_values( (array) ( $res->get_data()['data']['roster'] ?? [] ) )
        );
        sort( $ids );
        return $ids;
    }

    private function player( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Roster',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function activity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+4 days' ) ),
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function plan( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'is_guest'    => 0,
            'status'      => 'Present',
            'notes'       => 'note on ' . $player_id,
            'record_type' => 'expected',
        ] );
    }
}
