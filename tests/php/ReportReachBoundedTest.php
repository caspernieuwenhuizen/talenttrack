<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportReader;

/**
 * #4742 — the bulk forms of the authorization checks, and the reach count
 * built on them.
 *
 * Each bulk form reads the sources once for the whole set and then asks the
 * unchanged single check per account, so the tests hold the two to the same
 * answer for every kind of account the rule distinguishes, and hold the
 * reach count's statements to one number whatever the number of holders.
 *
 * The fixture leaves a scout out of the holders on purpose: the scout links
 * have no bulk read, so a scout among the holders would cost a statement of
 * its own. No scout holds a persona a report is published to.
 */
final class ReportReachBoundedTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private const ENTITIES = [
        [ 'reports', MatrixGate::READ ],
        [ 'analytics', MatrixGate::READ ],
        [ 'report_studio', MatrixGate::READ ],
        [ 'report_studio', MatrixGate::CREATE_DELETE ],
        [ 'players', MatrixGate::READ ],
        [ 'team', MatrixGate::READ ],
        [ 'player_injuries', MatrixGate::READ ],
    ];

    private int $club = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $hod = 0;

    /** @var array<string,int> label => user id */
    private array $users = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        if ( get_role( 'tt_team_manager' ) === null ) add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();
        $this->team_a   = $this->team( 'Reach U12' );
        $this->team_b   = $this->team( 'Reach U15' );
        $this->player_a = $this->player( $this->team_a, 'Anna' );
        $this->player_b = $this->player( $this->team_b, 'Bram' );

        $this->hod   = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->users = [
            'head of development' => $this->hod,
            'academy admin'       => (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] ),
            'head coach A'        => $this->coach( $this->team_a, true ),
            'assistant coach B'   => $this->coach( $this->team_b, false ),
            'team manager A'      => $this->scoped( 'tt_team_manager', 'team_manager', $this->team_a ),
            'staff, Manager on A' => $this->functionalRole( 'tt_staff', 'manager', $this->team_a ),
            'observer'            => (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] ),
            'parent of Anna'      => $this->parentOf( $this->player_a ),
            'player Anna'         => $this->accountFor( $this->player_a ),
            'expired coach B'     => $this->scoped( 'tt_coach', 'head_coach', $this->team_b, '2000-01-01', '2000-12-31' ),
            'future coach B'      => $this->scoped( 'tt_coach', 'head_coach', $this->team_b, '2999-01-01', null ),
            'role scope only A'   => $this->roleScopeOnly( $this->team_a ),
            'functional map only' => $this->functionalMappingOnly( $this->team_a ),
            'other club coach'    => $this->otherClubCoach( $this->team_a ),
        ];
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    public function test_users_who_can_equals_the_single_check_for_global_team_and_self_scope(): void {
        $ids = array_values( $this->users );
        foreach ( self::ENTITIES as [ $entity, $activity ] ) {
            $asks = [
                [ MatrixGate::SCOPE_GLOBAL, 0 ],
                [ MatrixGate::SCOPE_TEAM, $this->team_a ],
                [ MatrixGate::SCOPE_TEAM, $this->team_b ],
                [ MatrixGate::SCOPE_SELF, $this->users['team manager A'] ],
            ];
            foreach ( $asks as [ $scope, $target ] ) {
                $this->flush();
                $bulk = MatrixGate::usersWhoCan( $ids, $entity, $activity, $scope, $target );
                $this->flush();
                $single = $this->filtered( $ids, fn( int $u ): bool => MatrixGate::can( $u, $entity, $activity, $scope, $scope === MatrixGate::SCOPE_GLOBAL ? null : $target ) );
                $this->assertSame( $single, $bulk, "{$entity} {$activity} at {$scope} {$target}: " . $this->names( $bulk, $single ) );
            }

            $this->flush();
            $bulk = MatrixGate::usersWhoCanAnyScope( $ids, $entity, $activity );
            $this->flush();
            $single = $this->filtered( $ids, fn( int $u ): bool => MatrixGate::canAnyScope( $u, $entity, $activity ) );
            $this->assertSame( $single, $bulk, "{$entity} {$activity} at any scope: " . $this->names( $bulk, $single ) );
        }
    }

    public function test_users_who_can_refuses_player_scope(): void {
        $this->expectException( \InvalidArgumentException::class );
        MatrixGate::usersWhoCan( array_values( $this->users ), 'players', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->player_a );
    }

    public function test_users_who_can_view_a_player_equals_the_single_check(): void {
        $ids = array_values( $this->users );

        // The drift gap 2 found: players.view through the older authority only.
        foreach ( [ 'role scope only A', 'functional map only' ] as $who ) {
            $uid = $this->users[ $who ];
            $this->flush();
            $this->assertFalse( MatrixGate::can( $uid, 'players', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_a ), "Precondition: the matrix gives {$who} nothing." );
            $this->assertTrue( AuthorizationService::canViewPlayer( $uid, $this->player_a ), "Precondition: {$who} views Anna through the role scopes." );
        }

        foreach ( [ $this->player_a, $this->player_b ] as $player ) {
            $this->flush();
            $bulk = AuthorizationService::usersWhoCanViewPlayer( $ids, $player );
            $this->flush();
            $single = $this->filtered( $ids, static fn( int $u ): bool => AuthorizationService::canViewPlayer( $u, $player ) );
            $this->assertSame( $single, $bulk, "player {$player}: " . $this->names( $bulk, $single ) );
        }
        $this->assertContains( $this->users['role scope only A'], AuthorizationService::usersWhoCanViewPlayer( $ids, $this->player_a ) );
        $this->assertContains( $this->users['functional map only'], AuthorizationService::usersWhoCanViewPlayer( $ids, $this->player_a ) );
    }

    public function test_readers_among_equals_reads_as_for_every_report_scope(): void {
        $ids  = array_values( $this->users );
        $keys = ReportAccess::PUBLISHABLE_PERSONAS;

        $this->flush();
        $this->assertFalse( ReportReader::for( $this->users['observer'] )->readsStudio(), 'Precondition: the Studio is closed to the observer.' );

        foreach ( $this->reports() as $label => $report ) {
            foreach ( [ $keys, [ 'head_coach' ], [ 'team_manager', 'assistant_coach' ] ] as $set ) {
                $this->flush();
                $bulk = ReportAccess::readersAmong( $ids, $report, $set );
                $this->flush();
                $single = $this->filtered( $ids, static fn( int $u ): bool => ReportAccess::readsAs( ReportReader::for( $u ), $report, $set ) );
                $this->assertSame( $single, $bulk, "{$label} as " . implode( ',', $set ) . ': ' . $this->names( $bulk, $single ) );
                $this->assertNotContains( $this->users['observer'], $bulk, "{$label}: the observer reads no report" );
            }
        }
        // The fixture says something: a team report reaches some holders and not others.
        $team = ReportAccess::readersAmong( $ids, $this->reports()['team A'], $keys );
        $this->assertContains( $this->users['head coach A'], $team );
        $this->assertNotContains( $this->users['assistant coach B'], $team );
    }

    public function test_the_reach_count_runs_the_same_statements_for_three_holders_and_for_sixty(): void {
        // A team report asks each holder the team read; a player report asks
        // the team read on the player's team and `canViewPlayer()`.
        $reports = [
            'team A'   => $this->report( [ 'scope' => 'team', 'team_id' => $this->team_a ] ),
            'player A' => $this->report( [ 'scope' => 'player', 'player_id' => $this->player_a ] ),
        ];
        $keys = 'head_coach,assistant_coach,team_manager';

        // The holders set_up made stay the same throughout; each unit adds
        // three: a head coach, an assistant coach and a team manager of A.
        $this->unit();
        $one = [];
        foreach ( $reports as $label => $id ) $one[ $label ] = $this->reachStatements( $id, $keys );

        for ( $i = 0; $i < 19; $i++ ) $this->unit();
        foreach ( $reports as $label => $id ) {
            [ $three, $count_three, $sql_three ] = $one[ $label ];
            [ $sixty, $count_sixty, $sql_sixty ] = $this->reachStatements( $id, $keys );

            $this->assertSame( $count_three + 57, $count_sixty, "{$label}: Precondition: the nineteen new units add three readers each." );
            $this->assertSame( $three, $sixty, "{$label}: the reach count ran {$three} statements with one unit of holders and {$sixty} with twenty; the statements that grew:\n" . $this->grown( $sql_three, $sql_sixty ) );
        }
    }

    // ---- the reach measurement ----------------------------------------------

    /** @return array{0:int,1:int,2:list<string>} statements, count, their text when SAVEQUERIES keeps it */
    private function reachStatements( int $report_id, string $keys ): array {
        global $wpdb;
        if ( ! defined( 'SAVEQUERIES' ) ) define( 'SAVEQUERIES', true );
        wp_set_current_user( $this->hod );
        // Warm the request-independent caches (the matrix, the module states,
        // the role permissions), then measure one cold request.
        $this->assertSame( 200, $this->reach( $report_id, $keys )->get_status() );
        $this->flush();
        $start = (int) $wpdb->num_queries;
        $kept  = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
        $res   = $this->reach( $report_id, $keys );
        $used  = (int) $wpdb->num_queries - $start;
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $sql = [];
        foreach ( array_slice( is_array( $wpdb->queries ) ? $wpdb->queries : [], $kept ) as $q ) {
            $sql[] = is_array( $q ) ? (string) ( $q[0] ?? '' ) : (string) $q;
        }
        return [ $used, (int) ( $res->get_data()['data']['count'] ?? -1 ), $sql ];
    }

    /**
     * The statement shapes (numbers and IN-lists folded) that ran more often
     * in the second request than in the first.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    private function grown( array $before, array $after ): string {
        $shape = static fn( string $sql ): string => (string) preg_replace( [ '/\s+/', '/\(\s*[\d\s,\']+\)/', '/\d+/' ], [ ' ', '(…)', 'N' ], $sql );
        $count = static function ( array $list ) use ( $shape ): array {
            $out = [];
            foreach ( $list as $sql ) {
                $key         = $shape( $sql );
                $out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
            }
            return $out;
        };
        $a     = $count( $before );
        $lines = [];
        foreach ( $count( $after ) as $key => $n ) {
            if ( $n > ( $a[ $key ] ?? 0 ) ) $lines[] = ( $a[ $key ] ?? 0 ) . ' -> ' . $n . ': ' . substr( $key, 0, 240 );
        }
        return implode( "\n", $lines );
    }

    private function reach( int $report_id, string $keys ): WP_REST_Response {
        $req = new WP_REST_Request( 'GET', "/talenttrack/v1/reports/{$report_id}/reach" );
        $req->set_query_params( [ 'persona_keys' => $keys ] );
        return rest_do_request( $req );
    }

    /** Three holders who read team A: its head coach, an assistant coach and its team manager. */
    private function unit(): void {
        $this->coach( $this->team_a, true );
        $this->coach( $this->team_a, false );
        $this->scoped( 'tt_team_manager', 'team_manager', $this->team_a );
    }

    /**
     * A report bound to its subject, so every holder is asked the subject
     * read: the count does not depend on how the report is shared.
     *
     * @param array<string,mixed> $body
     */
    private function report( array $body ): int {
        wp_set_current_user( $this->hod );
        $this->flush();
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/reports' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body + [ 'name' => 'Reach pack' ] ) );
        $res = rest_do_request( $req );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (int) $res->get_data()['data']['id'];
    }

    // ---- the five report scopes ---------------------------------------------

    /** @return array<string, array<string,mixed>> */
    private function reports(): array {
        $base = [
            'id' => 0, 'owner_user_id' => $this->hod, 'sharing' => 'role', 'published_version_id' => null,
            'archived_at' => null, 'player_id' => null, 'team_id' => null, 'cohort' => null,
        ];
        return [
            'me'       => [ 'scope' => ReportContext::SCOPE_ME ] + $base,
            'player A' => [ 'scope' => ReportContext::SCOPE_PLAYER, 'player_id' => $this->player_a ] + $base,
            'team A'   => [ 'scope' => ReportContext::SCOPE_TEAM, 'team_id' => $this->team_a ] + $base,
            'group'    => [ 'scope' => ReportContext::SCOPE_GROUP, 'cohort' => [ 'team_ids' => [ $this->team_a, $this->team_b ] ] ] + $base,
            'academy'  => [ 'scope' => ReportContext::SCOPE_ACADEMY ] + $base,
        ];
    }

    // ---- fixture accounts ---------------------------------------------------

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function player( int $team, string $first ): int {
        global $wpdb;
        // No account: NULL, as the UNIQUE (club_id, wp_user_id) index needs
        // for a second player without one (the column defaults to 0).
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Reach', 'status' => 'active',
            'wp_user_id' => null,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, "fixture: player {$first} inserted" );
        return $id;
    }

    private function person( int $user, string $role_type, int $club = 0 ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club > 0 ? $club : $this->club, 'first_name' => 'Reach', 'last_name' => 'Person' . $user,
            'role_type' => $role_type, 'wp_user_id' => $user, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function roleScope( int $person, int $team, ?string $start = null, ?string $end = null, int $role_id = 1, int $club = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $club > 0 ? $club : $this->club, 'person_id' => $person, 'role_id' => $role_id,
            'scope_type' => 'team', 'scope_id' => $team, 'start_date' => $start, 'end_date' => $end,
        ] );
    }

    private function coach( int $team, bool $head ): int {
        global $wpdb;
        $uid    = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $person = $this->person( $uid, $head ? 'head_coach' : 'assistant_coach' );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team, 'person_id' => $person,
            'role_in_team' => $head ? 'head_coach' : 'assistant_coach', 'is_head_coach' => $head ? 1 : 0,
        ] );
        $this->roleScope( $person, $team );
        return $uid;
    }

    private function scoped( string $role, string $role_type, int $team, ?string $start = null, ?string $end = null ): int {
        global $wpdb;
        $uid    = (int) self::factory()->user->create( [ 'role' => $role ] );
        $person = $this->person( $uid, $role_type );
        if ( $role === 'tt_coach' ) {
            $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
                'club_id' => $this->club, 'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach',
                'is_head_coach' => 1, 'start_date' => $start, 'end_date' => $end,
            ] );
        }
        $this->roleScope( $person, $team, $start, $end );
        return $uid;
    }

    private function functionalRoleId( string $key ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $key, $this->club
        ) );
        if ( $id <= 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [ 'club_id' => $this->club, 'role_key' => $key, 'label' => ucfirst( $key ) ] );
            $id = (int) $wpdb->insert_id;
        }
        return $id;
    }

    private function functionalRole( string $wp_role, string $key, int $team ): int {
        global $wpdb;
        $uid    = (int) self::factory()->user->create( [ 'role' => $wp_role ] );
        $person = $this->person( $uid, 'staff' );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team, 'person_id' => $person,
            'functional_role_id' => $this->functionalRoleId( $key ), 'role_in_team' => $key,
        ] );
        return $uid;
    }

    private function parentOf( int $player ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [ 'club_id' => $this->club, 'player_id' => $player, 'parent_user_id' => $uid ] );
        return $uid;
    }

    private function accountFor( int $player ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $uid ], [ 'id' => $player ] );
        return $uid;
    }

    /** The id of a `tt_roles` role that grants `players.view`, made when the seed has none. */
    private function playersViewRole(): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT r.id FROM {$wpdb->prefix}tt_roles r
               JOIN {$wpdb->prefix}tt_role_permissions rp ON rp.role_id = r.id AND rp.club_id = r.club_id
              WHERE rp.permission = %s AND r.club_id = %d ORDER BY r.id ASC LIMIT 1",
            'players.view', $this->club
        ) );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$wpdb->prefix}tt_roles", [ 'club_id' => $this->club, 'role_key' => 'reach_viewer', 'label' => 'Reach viewer' ] );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_role_permissions", [ 'club_id' => $this->club, 'role_id' => $id, 'permission' => 'players.view' ] );
        return $id;
    }

    /** An account with no persona, granted `players.view` on a team by a role scope alone. */
    private function roleScopeOnly( int $team ): int {
        $uid = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->roleScope( $this->person( $uid, 'other' ), $team, null, null, $this->playersViewRole() );
        return $uid;
    }

    /** An account with no persona, granted `players.view` through a functional role mapped to an auth role. */
    private function functionalMappingOnly( int $team ): int {
        global $wpdb;
        $fn = $this->functionalRoleId( 'reach_mapped' );
        $wpdb->insert( "{$wpdb->prefix}tt_functional_role_auth_roles", [
            'club_id' => $this->club, 'functional_role_id' => $fn, 'auth_role_id' => $this->playersViewRole(),
        ] );
        $uid    = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $person = $this->person( $uid, 'other' );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team, 'person_id' => $person,
            'functional_role_id' => $fn, 'role_in_team' => 'reach_mapped',
        ] );
        return $uid;
    }

    /** A coach whose person row and assignment belong to another club. */
    private function otherClubCoach( int $team ): int {
        global $wpdb;
        $other  = $this->club + 1000;
        $uid    = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $person = $this->person( $uid, 'head_coach', $other );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $other, 'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $this->roleScope( $person, $team, null, null, 1, $other );
        return $uid;
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * @param list<int>           $ids
     * @param callable(int): bool $check
     * @return list<int>
     */
    private function filtered( array $ids, callable $check ): array {
        $out = array_values( array_filter( $ids, $check ) );
        sort( $out );
        return $out;
    }

    /**
     * Who differs between the two lists, by fixture label.
     *
     * @param list<int> $bulk
     * @param list<int> $single
     */
    private function names( array $bulk, array $single ): string {
        $labels = array_flip( $this->users );
        $name   = static fn( int $id ): string => $labels[ $id ] ?? (string) $id;
        return 'bulk only [' . implode( ', ', array_map( $name, array_values( array_diff( $bulk, $single ) ) ) ) . '], '
            . 'single only [' . implode( ', ', array_map( $name, array_values( array_diff( $single, $bulk ) ) ) ) . ']';
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        PersonaResolver::clearCache();
        AuthorizationService::flushCache();
        wp_cache_flush();
    }
}
