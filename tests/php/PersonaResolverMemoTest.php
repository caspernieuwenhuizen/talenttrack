<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #4731 — `PersonaResolver::personasFor()` answers once per request.
 *
 * Every scoped `MatrixGate::can()` asks it, at four queries for a coach, so
 * a list checking `can()` per team paid that per team. The answer is kept
 * per user until a role change or an assignment flushes it.
 */
final class PersonaResolverMemoTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Personamemo U12' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_repeated_call_runs_no_queries(): void {
        global $wpdb;
        $coach = $this->coachOn( 'head_coach' );

        $before = $wpdb->num_queries;
        $first  = PersonaResolver::personasFor( $coach );
        $this->assertGreaterThan( 0, $wpdb->num_queries - $before, 'precondition: the first answer reads the database' );
        $this->assertSame( [ 'head_coach' ], $first );

        $before = $wpdb->num_queries;
        for ( $i = 0; $i < 10; $i++ ) {
            $this->assertSame( $first, PersonaResolver::personasFor( $coach ) );
        }
        $this->assertSame( 0, $wpdb->num_queries - $before, 'ten more calls, no query' );
    }

    public function test_repeated_scoped_checks_run_the_persona_queries_once(): void {
        $coach = $this->coachOn( 'head_coach' );

        $assignments = 0;
        $schema      = 0;
        $count = static function ( string $sql ) use ( &$assignments, &$schema ): string {
            if ( strpos( $sql, 'AS is_live' ) !== false ) $assignments++;
            if ( strpos( $sql, 'INFORMATION_SCHEMA.COLUMNS' ) !== false && strpos( $sql, 'is_head_coach' ) !== false ) $schema++;
            return $sql;
        };
        add_filter( 'query', $count );

        for ( $i = 0; $i < 10; $i++ ) {
            $this->assertTrue( MatrixGate::can( $coach, 'team', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ) );
        }
        $this->assertSame( 1, $assignments, 'ten checks, the assignments read once' );
        $this->assertLessThanOrEqual( 1, $schema, 'the column check at most once' );

        // A flush forgets the personas, not the schema answer.
        AuthorizationService::flushCache();
        $assignments = 0;
        $schema      = 0;
        MatrixGate::can( $coach, 'team', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team );
        remove_filter( 'query', $count );

        $this->assertSame( 1, $assignments );
        $this->assertSame( 0, $schema );
    }

    public function test_a_role_change_is_seen_by_the_next_call(): void {
        $coach = $this->coachOn( 'head_coach' );
        $this->assertSame( [ 'head_coach' ], PersonaResolver::personasFor( $coach ) );

        ( new \WP_User( $coach ) )->set_role( 'tt_player' );

        $this->assertSame( [ 'player' ], PersonaResolver::personasFor( $coach ) );
    }

    public function test_a_head_coach_flag_change_is_seen_by_the_next_call(): void {
        global $wpdb;
        $coach = $this->coachOn( 'assistant_coach' );
        $this->assertSame( [ 'assistant_coach' ], PersonaResolver::personasFor( $coach ) );

        $assignment = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT tp.id FROM {$wpdb->prefix}tt_team_people tp
               JOIN {$wpdb->prefix}tt_people pe ON pe.id = tp.person_id
              WHERE pe.wp_user_id = %d AND tp.team_id = %d",
            $coach, $this->team
        ) );
        $this->assertTrue( ( new PeopleRepository() )->updateAssignment( $assignment, $this->functionalRoleId( 'head_coach' ) ) );

        $this->assertSame( [ 'head_coach' ], PersonaResolver::personasFor( $coach ) );
    }

    /** A tt_coach user with a staff record, assigned to the team under the role. */
    private function coachOn( string $role_key ): int {
        global $wpdb;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => 1, 'first_name' => 'Personamemo', 'last_name' => 'Coach',
            'wp_user_id' => $coach, 'role_type' => 'staff', 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        ( new PeopleRepository() )->assignToTeam( $this->team, $person, $this->functionalRoleId( $role_key ) );
        AuthorizationService::flushCache();
        return $coach;
    }

    private function functionalRoleId( string $role_key ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = 1 LIMIT 1",
            $role_key
        ) );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [
            'club_id'  => 1,
            'role_key' => $role_key,
            'label'    => ucwords( str_replace( '_', ' ', $role_key ) ),
        ] );
        return (int) $wpdb->insert_id;
    }
}
