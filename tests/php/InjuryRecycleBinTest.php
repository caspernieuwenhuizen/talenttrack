<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Journey\JourneyModule;

/**
 * #5125 — injury reads honour the recycle bin, like every other entity. An
 * injury with `trashed_at` set comes back from no read in any archive state;
 * an archived injury still shows where archived rows are shown. Every
 * `InjuryRepository` read goes through `ArchiveRepository::listViewClause()`,
 * and the callers (the injuries routes, the profile, the coach week and the
 * monthly report) read through the repository.
 */
final class InjuryRecycleBinTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $player = 0;
    private int $live = 0;
    private int $archived = 0;
    private int $trashed = 0;
    private int $archived_trashed = 0;

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

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Bin JO12-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Bin', 'last_name' => 'Player', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $repo  = new InjuryRepository();
        $today = AttendanceDateRule::today();
        $this->live             = $repo->create( [ 'player_id' => $this->player, 'started_on' => $today ] );
        $this->archived         = $repo->create( [ 'player_id' => $this->player, 'started_on' => $today ] );
        $this->trashed          = $repo->create( [ 'player_id' => $this->player, 'started_on' => $today ] );
        $this->archived_trashed = $repo->create( [ 'player_id' => $this->player, 'started_on' => $today ] );
        $now = current_time( 'mysql' );
        $t   = "{$wpdb->prefix}tt_player_injuries";
        $wpdb->update( $t, [ 'archived_at' => $now ], [ 'id' => $this->archived ] );
        $wpdb->update( $t, [ 'trashed_at' => $now ], [ 'id' => $this->trashed ] );
        $wpdb->update( $t, [ 'archived_at' => $now, 'trashed_at' => $now ], [ 'id' => $this->archived_trashed ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    public function test_no_repository_read_returns_a_binned_injury(): void {
        $repo = new InjuryRepository();
        $this->assertNull( $repo->find( $this->trashed ) );
        $this->assertNull( $repo->find( $this->archived_trashed ) );
        $this->assertNotNull( $repo->find( $this->archived ), 'an archived injury is still found' );
        $this->assertNotNull( $repo->find( $this->live ) );

        $active = $this->ids( $repo->listForPlayer( $this->player ) );
        $all    = $this->ids( $repo->listForPlayer( $this->player, true ) );
        $this->assertSame( [ $this->live ], $active );
        $this->assertEqualsCanonicalizing( [ $this->live, $this->archived ], $all, 'archived rows still show where they do' );

        foreach ( [ 'open', 'recovered', 'all' ] as $status ) {
            $listed = $this->ids( $repo->listForTeams( [ $this->team ], [ 'status' => $status ] ) );
            $this->assertNotContains( $this->trashed, $listed, $status );
            $this->assertNotContains( $this->archived_trashed, $listed, $status );
        }
        $this->assertSame( 1, $repo->countForTeams( [ $this->team ], [ 'status' => 'all' ] ) );
        $this->assertSame( [ $this->player ], array_keys( $repo->coveredSet( [ $this->player ], AttendanceDateRule::today() ) ), 'the live injury covers today' );
    }

    public function test_a_binned_injury_alone_covers_nothing(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_player_injuries", [ 'trashed_at' => current_time( 'mysql' ) ], [ 'id' => $this->live ] );
        $repo  = new InjuryRepository();
        $today = AttendanceDateRule::today();
        $this->assertSame( [], $repo->coveredSet( [ $this->player ], $today ) );
        $this->assertSame( [], $repo->coveredSetByDay( [ $today => [ $this->player ] ] )[ $today ] );
        $this->assertSame( 0, $repo->countForTeams( [ $this->team ], [ 'status' => 'all' ] ) );
    }

    public function test_the_injuries_routes_leave_out_a_binned_injury(): void {
        wp_set_current_user( $this->makeHeadCoach() );

        $collection = $this->get( '/player-injuries', [ 'team_id' => $this->team, 'status' => 'all' ] );
        $this->assertSame( 200, $collection[0], (string) wp_json_encode( $collection[1] ) );
        $this->assertSame( 1, (int) ( $collection[1]['total'] ?? -1 ), 'the squad overview counts the live injury only' );
        $this->assertCount( 1, (array) ( $collection[1]['injuries'] ?? [] ) );

        foreach ( [ false => [ $this->live ], true => [ $this->live, $this->archived ] ] as $include_archived => $expected ) {
            $player = $this->get( "/players/{$this->player}/injuries", $include_archived ? [ 'include_archived' => '1' ] : [] );
            $this->assertSame( 200, $player[0], (string) wp_json_encode( $player[1] ) );
            $ids = array_map( static fn( $r ): int => (int) ( (array) $r )['id'], (array) ( $player[1]['injuries'] ?? [] ) );
            $this->assertEqualsCanonicalizing( $expected, $ids, 'the player\'s injuries, include_archived=' . ( $include_archived ? '1' : '0' ) );
        }
    }

    /** No read of `tt_player_injuries` in the repository goes without the lifecycle clause. */
    public function test_every_repository_read_carries_the_lifecycle_clause(): void {
        $file = (string) ( new \ReflectionClass( InjuryRepository::class ) )->getFileName();
        $code = (string) file_get_contents( $file );
        $bare = [];
        foreach ( preg_split( '/\n\s*(?:public|private|protected)\s+(?:static\s+)?function\s+/', $code ) ?: [] as $i => $method ) {
            if ( $i === 0 ) continue;
            $name = (string) strtok( $method, '(' );
            $reads_injuries = preg_match( '/SELECT[\s\S]*?FROM\s+(?:\{\$this->table\}|\{\$p\}tt_player_injuries|\{\$wpdb->prefix\}tt_player_injuries)/', $method ) === 1;
            if ( $reads_injuries && strpos( $method, 'listViewClause(' ) === false && ! in_array( $name, [ 'listForTeams', 'countForTeams' ], true ) ) {
                $bare[] = $name;
            }
        }
        $this->assertSame( [], $bare, 'every read goes through ArchiveRepository::listViewClause() (listForTeams / countForTeams through teamsWhere())' );
        $this->assertStringContainsString( "ArchiveRepository::listViewClause( 'active', 'i' ) ];", $code, 'teamsWhere() carries the clause' );
    }

    /** @param list<object> $rows @return list<int> */
    private function ids( array $rows ): array {
        return array_map( static fn( $r ): int => (int) ( (array) $r )['id'], $rows );
    }

    /**
     * @param array<string,mixed> $query
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function get( string $route, array $query ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1' . $route );
        foreach ( $query as $k => $v ) $request->set_param( $k, $v );
        $response = rest_do_request( $request );
        $body     = (array) $response->get_data();
        $data     = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
        return [ (int) $response->get_status(), $data ];
    }

    /** The head coach of the team, built the way InjuriesCollectionRestTest builds one. */
    private function makeHeadCoach(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach', $this->club
        ) );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Head', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $this->team, 'person_id' => $person_id,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person_id, 'role_id' => $role_id,
            'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
