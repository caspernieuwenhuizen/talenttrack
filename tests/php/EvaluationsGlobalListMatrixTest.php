<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AuthorizationModule;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;

/**
 * #4904 — the academy-wide evaluations list follows `evaluations` read at
 * global scope in the authorization matrix, not two persona names. A seat
 * granted that read lists an evaluation on a team it does not hold; the
 * head of development and the academy admin keep the list, with the
 * `user_has_cap` bridge on and off; the read-only observer lists it with
 * the notes masked; a coach keeps the team-scoped list plus what they wrote.
 */
final class EvaluationsGlobalListMatrixTest extends WP_UnitTestCase {

    private const BRIDGE = [ AuthorizationModule::class, 'filterUserHasCap' ];
    private const NOTES  = 'Staff note 4904: talk to the parents first';

    private int $club       = 0;
    private int $team       = 0;
    private int $other_team = 0;
    private int $eval       = 0;
    private int $own_eval   = 0;

    /** @var int|false */
    private $bridge_priority = false;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->bridge_priority = has_filter( 'user_has_cap', self::BRIDGE );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO15-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO17-2' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->other_team, 'first_name' => 'Sem', 'last_name' => 'Kok', 'status' => 'active',
        ] );
        $other_player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id'   => $this->club,
            'player_id' => $other_player,
            'coach_id'  => 0,
            'eval_date' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
            'notes'     => self::NOTES,
        ] );
        $this->eval = (int) $wpdb->insert_id;

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
        if ( $this->bridge_priority !== false ) {
            add_filter( 'user_has_cap', self::BRIDGE, (int) $this->bridge_priority, 4 );
        }
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_a_seat_granted_global_evaluations_read_gets_the_academy_wide_list(): void {
        // A team manager on `$this->team`: the seed's team-scoped evaluations
        // read lets them through the route's gate, and the evaluation sits
        // on the other team.
        $manager = $this->makeTeamManager();
        $this->assertFalse(
            MatrixGate::can( $manager, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ),
            'precondition: the seed gives this seat no global evaluations read'
        );
        $before = $this->listFor( $manager );
        $this->assertSame( 200, $before->get_status(), 'without the grant the seat still reaches the list' );
        $this->assertNotContains( $this->eval, $this->idsOf( $before ), 'and the evaluation on the other team is not listed' );

        ( new MatrixRepository() )->setRow( 'team_manager', 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL, '' );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->assertTrue( MatrixGate::can( $manager, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ), 'the grant is written' );

        $response = $this->listFor( $manager );
        $this->assertSame( 200, $response->get_status(), 'the seat reaches the list' );
        $this->assertContains( $this->eval, $this->idsOf( $response ), 'with it, an evaluation on a team the seat does not hold is listed' );
    }

    public function test_head_of_development_and_academy_admin_keep_the_list_with_the_bridge_on_and_off(): void {
        $hod   = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );

        foreach ( [ 'bridge as installed' => false, 'matrix dormant' => true ] as $label => $dormant ) {
            if ( $dormant ) remove_filter( 'user_has_cap', self::BRIDGE, 10 );
            AuthorizationService::flushCache();
            $this->assertContains( $this->eval, $this->listedIds( $hod ), "{$label}: head of development" );
            $this->assertContains( $this->eval, $this->listedIds( $admin ), "{$label}: academy admin" );
        }
    }

    public function test_the_observer_lists_the_evaluation_with_the_notes_masked(): void {
        $observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );

        $response = $this->listFor( $observer );
        $this->assertSame( 200, $response->get_status() );
        $this->assertContains( $this->eval, $this->idsOf( $response ), 'the observer lists the academy-wide evaluation' );
        $this->assertStringNotContainsString( 'Staff note 4904', (string) wp_json_encode( $response->get_data() ), 'the notes stay masked' );
        $this->assertStringNotContainsString( 'talk to the parents', (string) wp_json_encode( $response->get_data() ), 'the excerpt too' );
    }

    public function test_a_coach_keeps_the_team_scoped_list_plus_their_own(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = $this->makeHeadCoach();

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Daan', 'last_name' => 'Smit', 'status' => 'active',
        ] );
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => (int) $wpdb->insert_id, 'coach_id' => 0, 'eval_date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
        ] );
        $team_eval = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->other_team, 'first_name' => 'Noud', 'last_name' => 'Vos', 'status' => 'active',
        ] );
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => (int) $wpdb->insert_id, 'coach_id' => $coach, 'eval_date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
        ] );
        $this->own_eval = (int) $wpdb->insert_id;

        $ids = $this->listedIds( $coach );
        $this->assertContains( $team_eval, $ids, 'their team' );
        $this->assertContains( $this->own_eval, $ids, 'what they wrote' );
        $this->assertNotContains( $this->eval, $ids, 'not another team' );
    }

    public function test_the_check_compares_no_persona_names(): void {
        $source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/REST/EvaluationsRestController.php' );
        $start  = (int) strpos( $source, 'private static function hasGlobalEvaluationsAccess' );
        $body   = substr( $source, $start, 700 );
        $this->assertStringContainsString( 'MatrixGate::can(', $body );
        $this->assertStringNotContainsString( 'PersonaResolver', $body );
        $this->assertStringNotContainsString( "'head_of_development'", $body );
    }

    /** @return list<int> */
    private function listedIds( int $user_id ): array {
        return $this->idsOf( $this->listFor( $user_id ) );
    }

    private function listFor( int $user_id ): WP_REST_Response {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations' );
        $request->set_param( 'per_page', 100 );
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }

    /** @return list<int> */
    private function idsOf( WP_REST_Response $response ): array {
        $data = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $ids  = [];
        foreach ( (array) ( $data['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( ( (array) $row )['id'] ?? 0 );
        }
        return $ids;
    }

    /** A team manager on `$this->team`, assigned the way production assigns one. */
    private function makeTeamManager(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Team', 'last_name' => 'Manager', 'role_type' => 'team_manager', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        AuthorizationService::flushCache();
        $this->assertTrue(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ),
            'precondition: the seat reads evaluations on its own team'
        );
        return $uid;
    }

    /** A head coach of `$this->team`, built the way production builds one. */
    private function makeHeadCoach(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id );

        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Hoofd', 'last_name' => 'Trainer', 'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $this->team, 'person_id' => $person_id, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person_id, 'role_id' => $role_id, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }
}
