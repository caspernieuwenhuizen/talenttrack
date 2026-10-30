<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\InjuryStatus;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Journey\JourneyModule;

/**
 * #4288 — `GET /player-injuries`, the squad overview over REST.
 *
 * Medical data about minors, so most of this is about who gets which rows:
 * the head coach their own team, the physio the team they are the physio
 * of, the assistant coach and the parent nothing, and a team outside the
 * caller's scope an empty set rather than its rows. Every refusal sits
 * beside the grant next to it, so a fixture that refused everyone could
 * not pass.
 */
final class InjuriesCollectionRestTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $other_team = 0;
    private int $player = 0;
    private int $other_player = 0;

    public function set_up(): void {
        parent::set_up();
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        JourneyModule::ensureCapabilities();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->team         = $this->insertTeam( 'JO11-1' );
        $this->other_team   = $this->insertTeam( 'JO11-2' );
        $this->player       = $this->insertPlayer( $this->team );
        $this->other_player = $this->insertPlayer( $this->other_team );

        $repo = new InjuryRepository();
        // Started today, so it stays `open` whatever day the suite runs on
        // (a dated one turns `incomplete` after 14 days).
        $repo->create( [ 'player_id' => $this->player, 'started_on' => AttendanceDateRule::today() ] );
        $repo->create( [ 'player_id' => $this->player, 'started_on' => '2026-08-01', 'actual_return' => '2026-08-20' ] );
        $repo->create( [ 'player_id' => $this->other_player, 'started_on' => '2026-10-02' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    public function test_a_head_coach_gets_only_their_own_team(): void {
        wp_set_current_user( $this->makeCoach( $this->team, true ) );
        [ $status, $data ] = $this->get( [] );

        $this->assertSame( 200, $status );
        $this->assertSame( [ $this->player ], $this->playerIds( $data ), 'the open injury of their own player, nobody else\'s' );
        $this->assertSame( InjuryStatus::OPEN, (string) $data['injuries'][0]['status'] );
        $this->assertArrayHasKey( 'days_out', $data['injuries'][0] );
    }

    public function test_only_the_fields_the_overview_shows_are_returned(): void {
        ( new InjuryRepository() )->create( [
            'player_id'             => $this->player,
            'started_on'            => AttendanceDateRule::today(),
            'notes'                 => 'Physio note: not for the squad list',
            'injury_type_lookup_id' => 1,
        ] );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        [ $status, $data ] = $this->get( [ 'team_id' => $this->team ] );

        $this->assertSame( 200, $status );
        $this->assertNotEmpty( $data['injuries'] );
        $expected = [
            'id', 'player_id', 'first_name', 'last_name', 'team_id', 'team_name',
            'body_part_lookup_id', 'severity_lookup_id',
            'started_on', 'expected_return', 'actual_return', 'status', 'days_out',
        ];
        sort( $expected );
        foreach ( $data['injuries'] as $item ) {
            $keys = array_keys( (array) $item );
            sort( $keys );
            $this->assertSame( $expected, $keys, 'a fixed field list, nothing else' );
            foreach ( [ 'notes', 'injury_type_lookup_id', 'created_by', 'club_id', 'archived_at', 'is_recovery_logged' ] as $hidden ) {
                $this->assertArrayNotHasKey( $hidden, (array) $item, "{$hidden} stays out of the squad list" );
            }
        }
        $this->assertStringNotContainsString( 'Physio note', (string) wp_json_encode( $data ) );
    }

    public function test_a_physio_gets_the_team_they_are_the_physio_of(): void {
        wp_set_current_user( $this->makePhysio( $this->other_team ) );
        [ $status, $data ] = $this->get( [] );

        $this->assertSame( 200, $status );
        $this->assertSame( [ $this->other_player ], $this->playerIds( $data ) );
    }

    public function test_an_assistant_coach_and_a_parent_are_refused(): void {
        wp_set_current_user( $this->makeCoach( $this->team, false ) );
        $this->assertSame( 403, $this->get( [] )[0], 'medical data stays out of the assistant coach\'s scope' );

        global $wpdb;
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->player,
            'parent_user_id' => $parent,
            'is_primary'     => 1,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $parent );
        $this->assertSame( 403, $this->get( [] )[0], 'a parent reads their child on the player file, not the squad list' );
    }

    public function test_a_team_outside_the_callers_scope_answers_an_empty_set(): void {
        wp_set_current_user( $this->makeCoach( $this->team, true ) );
        [ $status, $data ] = $this->get( [ 'team_id' => $this->other_team ] );

        $this->assertSame( 200, $status );
        $this->assertSame( [], $data['injuries'] );
        $this->assertSame( 0, (int) $data['total'] );
    }

    public function test_the_status_filter_and_pagination(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        [ , $all ] = $this->get( [ 'status' => 'all', 'team_id' => $this->team ] );
        $this->assertSame( 2, (int) $all['total'] );

        [ , $recovered ] = $this->get( [ 'status' => 'recovered', 'team_id' => $this->team ] );
        $this->assertSame( 1, (int) $recovered['total'] );
        $this->assertSame( InjuryStatus::CLOSED, (string) $recovered['injuries'][0]['status'] );

        // Fifteen more open injuries on the team: two pages of ten.
        $repo = new InjuryRepository();
        for ( $i = 0; $i < 15; $i++ ) {
            $repo->create( [ 'player_id' => $this->player, 'started_on' => '2026-09-' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) ] );
        }
        [ , $page1 ] = $this->get( [ 'team_id' => $this->team, 'per_page' => 10, 'page' => 1 ] );
        [ , $page2 ] = $this->get( [ 'team_id' => $this->team, 'per_page' => 10, 'page' => 2 ] );
        $this->assertSame( 16, (int) $page1['total'] );
        $this->assertCount( 10, $page1['injuries'] );
        $this->assertCount( 6, $page2['injuries'] );
        $this->assertSame( 10, (int) $page1['per_page'] );

        $ids = array_merge(
            array_map( static fn ( $r ) => (int) $r['id'], $page1['injuries'] ),
            array_map( static fn ( $r ) => (int) $r['id'], $page2['injuries'] )
        );
        $this->assertCount( 16, array_unique( $ids ), 'the pages do not overlap' );
    }

    public function test_a_read_is_audited_with_the_players_it_returned(): void {
        wp_set_current_user( $this->makeCoach( $this->team, true ) );

        global $wpdb;
        $count = static function () use ( $wpdb ): int {
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed'"
            );
        };
        $before = $count();
        $this->get( [] );
        $this->assertSame( $before + 1, $count(), 'one audit row per read' );

        $payload = json_decode( (string) $wpdb->get_var(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed' ORDER BY id DESC LIMIT 1"
        ), true );
        $this->assertIsArray( $payload );
        $this->assertSame( 'rest_injuries_collection', $payload['surface'] );
        $this->assertSame( [ $this->player ], $payload['player_ids'] );
    }

    // ---- fixtures ------------------------------------------------------

    /**
     * @param array<string, int|string> $query
     * @return array{0:int, 1:array<string, mixed>}
     */
    private function get( array $query ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/player-injuries' );
        foreach ( $query as $k => $v ) $request->set_param( $k, $v );
        $response = rest_do_request( $request );
        $body     = (array) $response->get_data();
        $data     = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
        return [ (int) $response->get_status(), $data ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<int>
     */
    private function playerIds( array $data ): array {
        $ids = array_map( static fn ( $r ) => (int) ( (array) $r )['player_id'], (array) ( $data['injuries'] ?? [] ) );
        $ids = array_values( array_unique( $ids ) );
        sort( $ids );
        return $ids;
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Squad',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    /** A coach on one team, built the way production builds one (see PlayerSuspensionTest). */
    private function makeCoach( int $team_id, bool $head ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $head ? 'head_coach' : 'assistant_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => $head ? 'Head' : 'Assistant',
            'last_name'  => 'Coach',
            'role_type'  => $head ? 'head_coach' : 'assistant_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => $head ? 'head_coach' : 'assistant_coach',
            'is_head_coach' => $head ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }

    /** A staff member who is the physio of one team (see FunctionalRoleAccessTest). */
    private function makePhysio( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Team',
            'last_name'  => 'Physio',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            'physio', $this->club
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'     => $this->club,
                'role_key'    => 'physio',
                'label'       => 'Physio',
                'description' => 'Created by InjuriesCollectionRestTest.',
                'is_system'   => 1,
                'sort_order'  => 90,
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
            'role_in_team'       => 'physio',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        return $uid;
    }
}
