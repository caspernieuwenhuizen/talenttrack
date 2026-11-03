<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * The coach role follows the person's current assignment on that team.
 *
 * A coach is a head coach where they hold a head-coach assignment that is
 * live today, and an assistant where they hold an assistant one. An ended or
 * future head-coach assignment, or one on another team, does not give the
 * head-coach rows on a team the person assists. Assignments go through
 * `PeopleRepository::assignToTeam()`, the way the Staff section writes them,
 * against the seeded matrix.
 *
 * `player_injuries` is the probe: head coaches hold it at team scope and
 * assistant coaches hold no row for it at all, so a yes on it is the head-coach
 * persona. `team` is held by both, so it shows the team scope itself is intact.
 */
final class CoachPersonaPerTeamTest extends WP_UnitTestCase {

    private int $teamX = 0;
    private int $teamY = 0;
    private int $head_role      = 0;
    private int $assistant_role = 0;

    private string $matrix_was = '';
    private string $yesterday  = '';
    private string $today      = '';
    private string $tomorrow   = '';

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );

        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Coachteam X' ] );
        $this->teamX = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Coachteam Y' ] );
        $this->teamY = (int) $wpdb->insert_id;

        $this->head_role      = $this->functionalRole( 'head_coach' );
        $this->assistant_role = $this->functionalRole( 'assistant_coach' );

        $now             = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
        $this->today     = $now->format( 'Y-m-d' );
        $this->yesterday = $now->modify( '-1 day' )->format( 'Y-m-d' );
        $this->tomorrow  = $now->modify( '+1 day' )->format( 'Y-m-d' );
        $this->flush();
    }

    public function tear_down(): void {
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_future_head_coach_assignment_elsewhere_gives_no_head_coach_rows_here(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $this->assertTrue( $repo->assignToTeam( $this->teamX, $person, $this->assistant_role, '2020-08-01', null ) );
        $this->assertTrue( $repo->assignToTeam( $this->teamY, $person, $this->head_role, $this->tomorrow, null ) );
        $this->flush();

        $this->assertSame( [ 'assistant_coach' ], PersonaResolver::personasFor( $uid ) );
        $this->assertTrue( $this->can( $uid, 'team', $this->teamX ), 'the assistant still reads the team they assist' );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamX ), 'no head-coach rows on the team they assist' );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamY ), 'and none on the team they start on tomorrow' );
    }

    public function test_an_ended_head_coach_assignment_on_the_same_team_leaves_the_assistant_only(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $this->assertTrue( $repo->assignToTeam( $this->teamX, $person, $this->head_role, '2020-08-01', $this->yesterday ) );
        $this->assertTrue( $repo->assignToTeam( $this->teamX, $person, $this->assistant_role, $this->today, null ) );
        $this->flush();

        $this->assertSame( [ 'assistant_coach' ], PersonaResolver::personasFor( $uid ) );
        $this->assertTrue( $this->can( $uid, 'team', $this->teamX ) );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamX ) );
        $this->assertFalse( MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->teamX ) );
    }

    public function test_a_head_coach_of_one_team_and_assistant_of_another_holds_each_on_its_own_team(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $this->assertTrue( $repo->assignToTeam( $this->teamY, $person, $this->head_role, '2020-08-01', null ) );
        $this->assertTrue( $repo->assignToTeam( $this->teamX, $person, $this->assistant_role, '2020-08-01', null ) );
        $this->flush();

        $personas = PersonaResolver::personasFor( $uid );
        sort( $personas );
        $this->assertSame( [ 'assistant_coach', 'head_coach' ], $personas );

        $this->assertTrue( $this->can( $uid, 'player_injuries', $this->teamY ), 'head coach on the team they head-coach' );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamX ), 'assistant on the team they assist' );
        $this->assertTrue( $this->can( $uid, 'team', $this->teamX ) );
        $this->assertTrue( $this->can( $uid, 'team', $this->teamY ) );
        $this->assertSame( [ $this->teamY ], MatrixGate::teamIdsFor( $uid, 'player_injuries', MatrixGate::READ ) );
    }

    public function test_a_current_head_coach_keeps_the_head_coach_rows(): void {
        [ $uid, $person ] = $this->coach();
        $this->assertTrue( ( new PeopleRepository() )->assignToTeam( $this->teamX, $person, $this->head_role, '2020-08-01', null ) );
        $this->flush();

        $this->assertSame( [ 'head_coach' ], PersonaResolver::personasFor( $uid ) );
        $this->assertTrue( $this->can( $uid, 'player_injuries', $this->teamX ) );
        $this->assertTrue( MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->teamX ) );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamY ), 'another team stays outside the scope' );
    }

    public function test_a_head_coach_assignment_ending_today_still_counts(): void {
        [ $uid, $person ] = $this->coach();
        $this->assertTrue( ( new PeopleRepository() )->assignToTeam( $this->teamX, $person, $this->head_role, '2020-08-01', $this->today ) );
        $this->flush();

        $this->assertSame( [ 'head_coach' ], PersonaResolver::personasFor( $uid ) );
        $this->assertTrue( $this->can( $uid, 'player_injuries', $this->teamX ) );
    }

    public function test_a_former_assistant_does_not_gain_the_head_coach_global_rows(): void {
        [ $uid, $person ] = $this->coach();
        $this->assertTrue( ( new PeopleRepository() )->assignToTeam( $this->teamX, $person, $this->assistant_role, '2020-08-01', $this->yesterday ) );
        $this->flush();

        $this->assertSame( [ 'assistant_coach' ], PersonaResolver::personasFor( $uid ), 'read from the ended assignments, not the no-assignments default' );
        // `measurement_definitions` is held at global scope by the head coach
        // and not by the assistant.
        $this->assertFalse( MatrixGate::can( $uid, 'measurement_definitions', MatrixGate::READ ) );
        $this->assertFalse( $this->can( $uid, 'team', $this->teamX ), 'and the ended team is out of scope' );
    }

    public function test_a_team_scope_an_operator_granted_is_unaffected(): void {
        [ $uid, $person ] = $this->coach();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id'    => (int) CurrentClub::id(),
            'person_id'  => $person,
            'role_id'    => 5,
            'scope_type' => 'team',
            'scope_id'   => $this->teamX,
        ] );
        $this->flush();

        $this->assertSame( [ 'head_coach' ], PersonaResolver::personasFor( $uid ), 'no assignments: the default persona' );
        $this->assertTrue( $this->can( $uid, 'player_injuries', $this->teamX ), 'the granted scope answers alone' );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamY ) );
    }

    public function test_an_assistant_only_is_an_assistant_on_their_team(): void {
        [ $uid, $person ] = $this->coach();
        $this->assertTrue( ( new PeopleRepository() )->assignToTeam( $this->teamX, $person, $this->assistant_role, '2020-08-01', null ) );
        $this->flush();

        $this->assertSame( [ 'assistant_coach' ], PersonaResolver::personasFor( $uid ) );
        $this->assertTrue( $this->can( $uid, 'team', $this->teamX ) );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamX ) );
        $this->assertFalse( $this->can( $uid, 'team', $this->teamY ), 'another team stays outside the scope' );
    }

    public function test_an_ended_head_coach_assignment_on_another_team_gives_no_head_coach_rows(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $this->assertTrue( $repo->assignToTeam( $this->teamY, $person, $this->head_role, '2020-08-01', $this->yesterday ) );
        $this->assertTrue( $repo->assignToTeam( $this->teamX, $person, $this->assistant_role, '2020-08-01', null ) );
        $this->flush();

        $this->assertSame( [ 'assistant_coach' ], PersonaResolver::personasFor( $uid ) );
        $this->assertTrue( $this->can( $uid, 'team', $this->teamX ) );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamX ), 'no head-coach rows on the team they assist' );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $this->teamY ), 'and none on the team they used to head-coach' );
        $this->assertFalse( MatrixGate::can( $uid, 'measurement_definitions', MatrixGate::READ ), 'nor the head coach\'s global rows' );
    }

    public function test_with_no_live_assignment_the_most_recent_ended_one_decides(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $this->assertTrue( $repo->assignToTeam( $this->teamX, $person, $this->head_role, '2020-08-01', $this->yesterday ) );
        $this->assertTrue( $repo->assignToTeam( $this->teamX, $person, $this->assistant_role, $this->tomorrow, null ) );
        $this->flush();

        $this->assertSame( [ 'head_coach' ], PersonaResolver::personasFor( $uid ), 'the ended assignment, not the one still to start' );
        $this->assertFalse( $this->can( $uid, 'team', $this->teamX ), 'and no team is in scope today' );
    }

    /**
     * A coach with an assignment gets the coach role's team access from the
     * assignments. A role granted under Roles & Permissions on another team
     * does not carry it there; assigning the person to that team does.
     */
    public function test_a_role_granted_on_another_team_does_not_carry_the_coach_rows_for_an_assigned_coach(): void {
        [ $uid, $person ] = $this->coach();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Coachteam Z' ] );
        $teamZ = (int) $wpdb->insert_id;

        $this->assertTrue( ( new PeopleRepository() )->assignToTeam( $this->teamX, $person, $this->assistant_role, '2020-08-01', null ) );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id'    => (int) CurrentClub::id(),
            'person_id'  => $person,
            'role_id'    => 5,
            'scope_type' => 'team',
            'scope_id'   => $teamZ,
        ] );
        $this->flush();

        $this->assertTrue( $this->can( $uid, 'team', $this->teamX ), 'the assigned team is still allowed' );
        $this->assertFalse( $this->can( $uid, 'team', $teamZ ), 'the granted team gets no coach-role team rows' );
        $this->assertFalse( $this->can( $uid, 'player_injuries', $teamZ ) );

        $this->assertTrue( ( new PeopleRepository() )->assignToTeam( $teamZ, $person, $this->assistant_role, '2020-08-01', null ) );
        $this->flush();
        $this->assertTrue( $this->can( $uid, 'team', $teamZ ), 'assigning the person to the team is the way in' );
    }

    public function test_the_team_check_adds_no_query_per_call(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $repo->assignToTeam( $this->teamY, $person, $this->head_role, '2020-08-01', null );
        $repo->assignToTeam( $this->teamX, $person, $this->assistant_role, '2020-08-01', null );
        $this->flush();

        $reads = 0;
        $count = static function ( string $sql ) use ( &$reads ): string {
            if ( strpos( $sql, 'AS is_live' ) !== false ) $reads++;
            return $sql;
        };
        add_filter( 'query', $count );
        for ( $i = 0; $i < 10; $i++ ) {
            $this->can( $uid, 'player_injuries', $this->teamX );
            $this->can( $uid, 'player_injuries', $this->teamY );
        }
        remove_filter( 'query', $count );

        $this->assertSame( 1, $reads, 'the assignments are read once per request' );
    }

    // ---- helpers ------------------------------------------------------------------

    private function can( int $uid, string $entity, int $team ): bool {
        return MatrixGate::can( $uid, $entity, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $team );
    }

    /** @return array{0:int,1:int} user id, person id */
    private function coach(): array {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Persona', 'last_name' => 'Coach' . $uid,
            'role_type' => 'staff', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        return [ $uid, (int) $wpdb->insert_id ];
    }

    private function functionalRole( string $key ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $id   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $key, $club
        ) );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [ 'club_id' => $club, 'role_key' => $key, 'label' => $key ] );
        return (int) $wpdb->insert_id;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_cache_flush();
    }
}
