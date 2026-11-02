<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\StaffDevelopment\Rest\StaffDevelopmentRestController;
use TT\Modules\StaffDevelopment\StaffDevelopmentAccess;

/**
 * Staff development records are read by the person they describe, or by a
 * global `staff_development` grant. A team grant (the head coach's) or a
 * self grant (the other coaching seats) never reaches a colleague's record.
 *
 * Every fixture is a WordPress role resolving to a persona, plus the
 * `tt_people` / `tt_team_people` / `tt_user_role_scopes` rows that persona
 * needs. Nobody is handed a capability directly: the matrix seed decides.
 */
final class StaffDevelopmentAccessTest extends WP_UnitTestCase {

    private const GET_RESOURCES = [ 'goals', 'evaluations', 'certifications', 'pdp', 'mentorships' ];

    private int $club = 0;
    private int $team = 0;

    /** A colleague's person id: a coach nobody in the test is. */
    private int $colleague = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        $this->flush();

        global $wpdb, $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Staff O13-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $other_coach     = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->colleague = $this->person( $other_coach, 'Colleague' );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $this->team, 'person_id' => $this->colleague, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
        ] );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- (a) coaching seats: own record yes, a colleague's no ------------

    /** @return array<string, array{0:string}> */
    public static function ownRecordSeats(): array {
        return [
            'assistant coach' => [ 'assistant_coach' ],
            'team manager'    => [ 'team_manager' ],
            'scout'           => [ 'scout' ],
            'head coach'      => [ 'head_coach' ],
        ];
    }

    /** @dataProvider ownRecordSeats */
    public function test_a_seat_reads_its_own_record_and_not_a_colleagues( string $persona ): void {
        [ $uid, $own ] = $this->seat( $persona );
        wp_set_current_user( $uid );

        foreach ( self::GET_RESOURCES as $resource ) {
            $this->assertSame( 200, $this->get( $own, $resource ), "{$persona} reads their own {$resource}" );
            $this->assertSame( 403, $this->get( $this->colleague, $resource ), "{$persona} is refused a colleague's {$resource}" );
        }
    }

    // ---- (b) and (c) global readers ---------------------------------------

    /** @return array<string, array{0:string}> */
    public static function globalSeats(): array {
        return [
            'head of development' => [ 'head_of_development' ],
            'academy admin'       => [ 'academy_admin' ],
            'wp administrator'    => [ 'administrator' ],
        ];
    }

    /** @dataProvider globalSeats */
    public function test_a_global_reader_reads_any_record( string $persona ): void {
        [ $uid ] = $this->seat( $persona );
        wp_set_current_user( $uid );

        foreach ( self::GET_RESOURCES as $resource ) {
            $this->assertSame( 200, $this->get( $this->colleague, $resource ), "{$persona} reads anyone's {$resource}" );
        }
    }

    // ---- (d) seats without a staff development row ------------------------

    /** @return array<string, array{0:string}> */
    public static function noGrantSeats(): array {
        return [
            'staff'             => [ 'staff' ],
            'readonly observer' => [ 'readonly_observer' ],
        ];
    }

    /** @dataProvider noGrantSeats */
    public function test_a_seat_without_the_grant_reads_nothing( string $persona ): void {
        [ $uid, $own ] = $this->seat( $persona );
        wp_set_current_user( $uid );

        foreach ( self::GET_RESOURCES as $resource ) {
            $this->assertSame( 403, $this->get( $own, $resource ), "{$persona} is refused their own {$resource}" );
            $this->assertSame( 403, $this->get( $this->colleague, $resource ), "{$persona} is refused a colleague's {$resource}" );
        }
    }

    // ---- (e) another club's row is not self -------------------------------

    public function test_the_same_user_in_another_club_is_not_self(): void {
        global $wpdb;
        [ $uid, $own ] = $this->seat( 'assistant_coach' );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club + 1, 'first_name' => 'Elsewhere', 'last_name' => 'Coach',
            'role_type' => 'coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $elsewhere = (int) $wpdb->insert_id;
        $this->flush();

        $this->assertTrue( StaffDevelopmentAccess::isSelf( $uid, $own ) );
        $this->assertFalse( StaffDevelopmentAccess::isSelf( $uid, $elsewhere ), 'a row in another club is not this user here' );

        wp_set_current_user( $uid );
        foreach ( self::GET_RESOURCES as $resource ) {
            $this->assertSame( 403, $this->get( $elsewhere, $resource ), "another club's {$resource} is refused" );
        }
    }

    // ---- (f) writing the PDP ----------------------------------------------

    public function test_a_coach_writes_their_own_pdp_and_not_a_colleagues(): void {
        [ $uid, $own ] = $this->seat( 'assistant_coach' );
        wp_set_current_user( $uid );

        $this->assertSame( 200, $this->putPdp( $own ) );
        $this->assertSame( 403, $this->putPdp( $this->colleague ) );
    }

    public function test_the_readonly_observer_cannot_write_their_own_pdp(): void {
        [ $uid, $own ] = $this->seat( 'readonly_observer' );
        wp_set_current_user( $uid );

        $this->assertSame( 403, $this->putPdp( $own ) );
    }

    // ---- (g) no such person -----------------------------------------------

    public function test_a_missing_person_is_refused(): void {
        [ $coach ] = $this->seat( 'assistant_coach' );
        wp_set_current_user( $coach );
        foreach ( self::GET_RESOURCES as $resource ) {
            $this->assertSame( 403, $this->get( 999999, $resource ), "an unknown person's {$resource} is refused" );
        }

        [ $hod ] = $this->seat( 'head_of_development' );
        wp_set_current_user( $hod );
        $this->assertFalse( StaffDevelopmentAccess::canRead( $hod, 0 ) );
        $this->assertFalse( StaffDevelopmentAccess::canWrite( $hod, 0 ) );
        $this->assertFalse(
            StaffDevelopmentRestController::can_view( new WP_REST_Request( 'GET', '/talenttrack/v1/staff/0/goals' ) ),
            'a request naming no person is refused, even for a global reader'
        );
    }

    // ---- fixtures ---------------------------------------------------------

    /**
     * A user resolving to `$persona`, with their own `tt_people` row.
     *
     * @return array{0:int,1:int} [ wp user id, person id ]
     */
    private function seat( string $persona ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $role_for = [
            'assistant_coach'     => 'tt_coach',
            'head_coach'          => 'tt_coach',
            'team_manager'        => 'tt_team_manager',
            'scout'               => 'tt_scout',
            'staff'               => 'tt_staff',
            'readonly_observer'   => 'tt_readonly_observer',
            'head_of_development' => 'tt_head_dev',
            'academy_admin'       => 'tt_club_admin',
            'administrator'       => 'administrator',
        ];

        $uid    = (int) self::factory()->user->create( [ 'role' => $role_for[ $persona ] ] );
        $person = $this->person( $uid, ucfirst( str_replace( '_', ' ', $persona ) ) );

        if ( $persona === 'assistant_coach' ) {
            $wpdb->insert( "{$p}tt_team_people", [
                'team_id' => $this->team, 'person_id' => $person, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
            ] );
        }
        if ( $persona === 'head_coach' ) {
            // The head coach's grant is at team scope, so it needs a team.
            $wpdb->insert( "{$p}tt_team_people", [
                'team_id' => $this->team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
            ] );
            $wpdb->insert( "{$p}tt_user_role_scopes", [
                'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
            ] );
        }
        $this->flush();

        $expected = $persona === 'administrator' ? 'academy_admin' : $persona;
        $this->assertContains(
            $expected,
            PersonaResolver::personasFor( $uid ),
            "the {$persona} fixture must resolve to {$expected}"
        );
        if ( $persona === 'assistant_coach' ) {
            $this->assertNotContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        }

        return [ $uid, $person ];
    }

    private function person( int $uid, string $first_name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => $first_name, 'last_name' => 'Staff',
            'role_type' => 'coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function get( int $person_id, string $resource ): int {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/staff/' . $person_id . '/' . $resource );
        return (int) rest_do_request( $request )->get_status();
    }

    private function putPdp( int $person_id ): int {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/staff/' . $person_id . '/pdp' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'strengths' => 'Clear session plans.' ] ) );
        return (int) rest_do_request( $request )->get_status();
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
    }
}
