<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\ReportStudio\Domain\ReportReader;

/**
 * #4684 — `MatrixGate::teamIdsFor()` is `can( …, SCOPE_TEAM, $team )` as a
 * list.
 *
 * The team lists behind the report list, analytics team scope and Report
 * Studio's builder were built from the teams a user coaches, so a team
 * granted only through a functional role (a team manager's `reports` read)
 * opened directly and was missing from the list.
 */
final class MatrixGateTeamIdsForTest extends WP_UnitTestCase {

    private const ENTITIES = [
        [ 'reports', MatrixGate::READ ],
        [ 'analytics', MatrixGate::READ ],
        [ 'report_studio', MatrixGate::CREATE_DELETE ],
        [ 'player_injuries', MatrixGate::READ ],
        [ 'measurements', MatrixGate::READ ],
        [ 'evaluations', MatrixGate::READ ],
        [ 'activities', MatrixGate::CHANGE ],
    ];

    private string $p = '';
    private int $club = 0;

    /** @var array{assigned:int, functional:int, other:int} */
    private array $teams = [ 'assigned' => 0, 'functional' => 0, 'other' => 0 ];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->hide_errors();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        $this->flush();

        foreach ( array_keys( $this->teams ) as $key ) {
            $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Teamids ' . $key ] );
            $this->teams[ $key ] = (int) $wpdb->insert_id;
        }
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** For every seeded persona, the list holds exactly the teams the single-team check admits. */
    public function test_the_list_equals_the_single_team_check_for_every_persona(): void {
        $roles = array_values( array_filter( array_keys( wp_roles()->roles ), static fn( string $r ): bool => strpos( $r, 'tt_' ) === 0 ) );
        $this->assertNotEmpty( $roles, 'fixture: the seeded roles are installed' );

        foreach ( $roles as $role ) {
            $user = $this->userWith( $role, true );
            foreach ( self::ENTITIES as [ $entity, $activity ] ) {
                $expected = [];
                foreach ( $this->teams as $team_id ) {
                    if ( MatrixGate::can( $user, $entity, $activity, MatrixGate::SCOPE_TEAM, $team_id ) ) $expected[] = $team_id;
                }
                sort( $expected );
                $this->assertSame( $expected, MatrixGate::teamIdsFor( $user, $entity, $activity ), "{$role}: {$entity} {$activity}" );
            }
        }
    }

    /** A team manager by functional role sees the team in the report list and in analytics. */
    public function test_a_functional_role_grant_reaches_the_lists(): void {
        $user = $this->userWith( 'tt_staff', true );
        $team = $this->teams['functional'];

        $this->assertTrue( TeamReportAccess::canRead( $user, $team ), 'precondition: the team opens directly' );
        $this->assertContains( $team, (array) TeamReportAccess::readableTeamIds( $user ), 'the report list' );

        [ $kind, $teams ] = FactScope::forUser( $user )->access( 'analytics' );
        $this->assertSame( FactScope::TEAMS, $kind );
        $this->assertContains( $team, $teams, 'analytics team scope' );

        $this->assertNotContains( $this->teams['other'], (array) TeamReportAccess::readableTeamIds( $user ), 'and nothing past the grant' );
    }

    /** Report Studio's builder teams are the same list for its own entity. */
    public function test_the_builder_teams_are_the_list(): void {
        foreach ( [ 'tt_coach', 'tt_staff', 'tt_head_dev' ] as $role ) {
            $user     = $this->userWith( $role, true );
            $teams    = ReportReader::for( $user )->builderTeams();
            $global   = MatrixGate::can( $user, 'report_studio', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_GLOBAL );
            $this->assertSame(
                $global ? null : MatrixGate::teamIdsFor( $user, 'report_studio', MatrixGate::CREATE_DELETE ),
                $teams,
                $role
            );
        }
    }

    /**
     * A user of `$role` with a person row, a live team assignment on the
     * `assigned` team and, when asked, the `manager` functional role on the
     * `functional` team.
     */
    private function userWith( string $role, bool $functional ): int {
        global $wpdb;
        $user = self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id' => $this->club, 'wp_user_id' => $user, 'first_name' => 'Teamids', 'last_name' => $role,
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 0,
            'scope_type' => 'team', 'scope_id' => $this->teams['assigned'],
        ] );
        if ( $functional ) {
            $wpdb->insert( "{$this->p}tt_team_people", [
                'club_id' => $this->club, 'team_id' => $this->teams['functional'],
                'person_id' => $person, 'functional_role_id' => $this->functionalRole( 'manager' ),
            ] );
        }
        $this->flush();
        return $user;
    }

    private function functionalRole( string $role_key ): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            $role_key
        ) );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$this->p}tt_functional_roles", [ 'club_id' => $this->club, 'role_key' => $role_key, 'label' => ucfirst( $role_key ) ] );
        return (int) $wpdb->insert_id;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        FactScope::flush();
    }
}
