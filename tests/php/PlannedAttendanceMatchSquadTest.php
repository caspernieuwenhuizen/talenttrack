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
 * #4260 — planned attendance carries the match-day squad the coach picked in
 * match prep, as `match_squad` (`start` / `bench` / `out`) beside the plan.
 *
 * The route used to answer from the creation plan alone, so a player the coach
 * had left out of the squad still came back `expected`: staff arranging kit and
 * transport worked from a different squad than the coach's. The field carries
 * the squad position only, never the prep's status or reason.
 */
final class PlannedAttendanceMatchSquadTest extends WP_UnitTestCase {

    private int $club     = 0;
    private int $team     = 0;
    private int $activity = 0;

    /** @var array<string, int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO9-1', 'football_form' => '6v6' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => 'Hedel - Ammerzoden',
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        foreach ( [ 'starter', 'sub', 'absent' ] as $key ) {
            $this->plannedPlayer( $key );
        }

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_without_a_prep_match_squad_is_null_and_the_plan_is_unchanged(): void {
        foreach ( $this->roster() as $row ) {
            $this->assertArrayHasKey( 'match_squad', $row );
            $this->assertNull( $row['match_squad'] );
            $this->assertSame( 'expected', $row['plan_status'] );
        }
    }

    public function test_the_prep_squad_reaches_planned_attendance(): void {
        $this->savePrep( [
            'availability' => [
                $this->players['starter'] => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['sub']     => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['absent']  => [ 'status' => 'Injured', 'reason' => 'Ankle, physio Thursday' ],
            ],
            'lineup' => [ '1' => [ '1' => $this->players['starter'] ] ],
        ] );

        $by_player = [];
        foreach ( $this->roster() as $row ) {
            $by_player[ (int) $row['player_id'] ] = $row;
        }

        $this->assertSame( 'start', $by_player[ $this->players['starter'] ]['match_squad'] );
        $this->assertSame( 'bench', $by_player[ $this->players['sub'] ]['match_squad'] );
        $this->assertSame( 'out', $by_player[ $this->players['absent'] ]['match_squad'] );

        // Beside the plan, never instead of it.
        foreach ( $by_player as $row ) {
            $this->assertSame( 'expected', $row['plan_status'] );
        }
    }

    public function test_the_response_carries_no_prep_status_or_reason(): void {
        $this->savePrep( [
            'availability' => [
                $this->players['starter'] => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['sub']     => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['absent']  => [ 'status' => 'Injured', 'reason' => 'Ankle, physio Thursday' ],
            ],
        ] );

        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance' ) );
        $body     = (string) wp_json_encode( $response->get_data() );

        $this->assertStringNotContainsString( 'Injured', $body );
        $this->assertStringNotContainsString( 'Ankle', $body );
    }

    /** A player added to the plan after the prep was saved was never left out. */
    public function test_a_player_the_prep_has_no_row_for_gets_null(): void {
        $this->savePrep( $this->squadPayload() );
        $this->plannedPlayer( 'late' );

        $this->assertNull( $this->rowFor( $this->players['late'] )['match_squad'] );
        $this->assertSame( 'start', $this->rowFor( $this->players['starter'] )['match_squad'] );
    }

    /**
     * D-22 — staff readers get the squad position. The precondition is
     * asserted, so a fixture that is not a staff reader cannot pass this for
     * the wrong reason.
     */
    public function test_a_coach_and_a_team_manager_get_the_squad_position(): void {
        $this->savePrep( $this->squadPayload() );

        foreach ( [ 'coach' => $this->makeCoach(), 'team manager' => $this->makeTeamManager() ] as $label => $uid ) {
            $this->assertTrue( AuthorizationService::isStaffForPlayer( $uid, $this->players['starter'] ), $label . ' must be staff for the player' );
            wp_set_current_user( $uid );
            $this->assertSame( 'start', $this->rowFor( $this->players['starter'] )['match_squad'], $label );
            $this->assertSame( 'out', $this->rowFor( $this->players['absent'] )['match_squad'], $label );
        }
    }

    /**
     * D-22 — match prep saves as the coach composes it, so a family must not
     * learn "bench" or "out" from it: a parent and a player reading their own
     * row get null.
     */
    public function test_a_parent_and_a_player_get_null_on_their_own_row(): void {
        global $wpdb;
        $this->savePrep( $this->squadPayload() );

        $player_user = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $player_user ], [ 'id' => $this->players['starter'] ] );

        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->players['starter'],
            'parent_user_id' => $parent,
        ] );
        AuthorizationService::flushCache();

        foreach ( [ 'player' => $player_user, 'parent' => $parent ] as $label => $uid ) {
            $this->assertFalse( AuthorizationService::isStaffForPlayer( $uid, $this->players['starter'] ), $label . ' must not be staff for the player' );
            wp_set_current_user( $uid );
            $row = $this->rowFor( $this->players['starter'], [ 'player_id' => $this->players['starter'] ] );
            $this->assertArrayHasKey( 'match_squad', $row, $label );
            $this->assertNull( $row['match_squad'], $label );
        }
    }

    /**
     * Performance: who is staff is asked for the roster at once, so the
     * route's query count does not grow with the squad (5 rows vs 20).
     */
    public function test_the_query_count_does_not_grow_with_the_roster(): void {
        global $wpdb;
        $this->plannedPlayer( 'extra_1' );
        $this->plannedPlayer( 'extra_2' );
        $this->savePrep( $this->squadPayload() );
        wp_set_current_user( $this->makeCoach() );

        $small = $this->queriesForOneRead();
        for ( $n = 3; $n <= 17; $n++ ) {
            $this->plannedPlayer( 'extra_' . $n );
        }
        $large = $this->queriesForOneRead();

        $this->assertCount( 20, $this->roster() );
        $this->assertSame( $small, $large, 'queries for 5 rows vs 20 rows' );
    }

    private function queriesForOneRead(): int {
        global $wpdb;
        $this->roster(); // warm the per-request caches
        $before = (int) $wpdb->num_queries;
        $this->roster();
        return (int) $wpdb->num_queries - $before;
    }

    /** @return array<string, mixed> */
    private function squadPayload(): array {
        return [
            'availability' => [
                $this->players['starter'] => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['sub']     => [ 'status' => 'Present', 'reason' => '' ],
                $this->players['absent']  => [ 'status' => 'Absent', 'reason' => '' ],
            ],
            'lineup' => [ '1' => [ '1' => $this->players['starter'] ] ],
        ];
    }

    private function makeCoach(): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $this->scopeToTeam( (int) $wpdb->insert_id );
        AuthorizationService::flushCache();
        return $uid;
    }

    /**
     * The `team_manager` persona. The role is added by a migration rather
     * than by `RolesService`, so it is created here when absent.
     */
    private function makeTeamManager(): int {
        global $wpdb;
        if ( get_role( 'tt_team_manager' ) === null ) {
            add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        }
        $uid = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Kees',
            'last_name'  => 'Teammanager',
            'role_type'  => 'team_manager',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $this->scopeToTeam( (int) $wpdb->insert_id );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function scopeToTeam( int $person_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $this->team,
        ] );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rowFor( int $player_id, array $params = [] ): array {
        foreach ( $this->roster( $params ) as $row ) {
            if ( (int) $row['player_id'] === $player_id ) return $row;
        }
        $this->fail( 'no planned row for player ' . $player_id );
    }

    /** @param array<string, mixed> $payload */
    private function savePrep( array $payload ): void {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/match-prep/' . $this->activity );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $payload ) );
        $this->assertSame( 200, rest_do_request( $request )->get_status() );
    }

    private function plannedPlayer( string $key ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $key,
            'status'     => 'active',
        ] );
        $this->players[ $key ] = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $this->activity,
            'player_id'   => $this->players[ $key ],
            'is_guest'    => 0,
            'status'      => 'Present',
            'notes'       => '',
            'record_type' => 'expected',
        ] );
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function roster( array $params = [] ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/activities/' . $this->activity . '/planned-attendance' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return array_values( array_map(
            static fn( $row ): array => (array) $row,
            (array) ( $data['data']['roster'] ?? [] )
        ) );
    }
}
