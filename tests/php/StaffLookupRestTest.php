<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\People\StaffDirectory;
use TT\Infrastructure\People\StaffPersonProvisioner;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\TrialsModule;

/**
 * #4043 — a staff member's name turns into the ids the trial panel takes.
 *
 * `POST trial-cases/{id}/staff` needed a WordPress account id, and nothing a
 * trial manager could reach produced one. `GET staff?search=` is the lookup;
 * the panel is now assigned by `person_id`, and a person who has no login is
 * refused, because they could never give an input.
 */
final class StaffLookupRestTest extends WP_UnitTestCase {

    private int $manager = 0;
    private int $case_id = 0;

    private int $coach_user   = 0;
    private int $coach_person = 0;
    private int $no_login_person = 0;
    private int $account_only = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        TrialsModule::ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $this->manager = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Beheer Proeftraining' ] );

        // A coach entered in People, with a login.
        $this->coach_user = self::factory()->user->create( [ 'role' => 'tt_coach', 'display_name' => 'Karin Keeperstra' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Karin', 'last_name' => 'Keeperstra',
            'role_type' => 'coach', 'wp_user_id' => $this->coach_user, 'status' => 'active',
        ] );
        $this->coach_person = (int) $wpdb->insert_id;

        // A coach entered in People who never got a login.
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Pieter', 'last_name' => 'Zonderlogin',
            'role_type' => 'coach', 'status' => 'active',
        ] );
        $this->no_login_person = (int) $wpdb->insert_id;

        // A parent in People: never staff.
        $parent_user = self::factory()->user->create( [ 'role' => 'tt_parent', 'display_name' => 'Olga Oudervelt' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Olga', 'last_name' => 'Oudervelt',
            'role_type' => 'parent', 'wp_user_id' => $parent_user, 'status' => 'active',
        ] );

        // A player's login, even one that carries a staff role: never staff.
        $player_user = self::factory()->user->create( [ 'role' => 'tt_coach', 'display_name' => 'Sem Spelersma' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'first_name' => 'Sem', 'last_name' => 'Spelersma',
            'status' => 'active', 'wp_user_id' => $player_user,
        ] );

        // A coach who was never entered in People. The suite holds the role
        // hook (tests/php/bootstrap.php), so this account has no person
        // record until a test runs the backfill.
        $this->account_only = self::factory()->user->create( [ 'role' => 'tt_coach', 'display_name' => 'Anouk Alleenaccount' ] );

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'first_name' => 'Proef', 'last_name' => 'Speler', 'status' => 'trial' ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id' => $club, 'player_id' => $player, 'track_id' => $track,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'open',
            'uuid' => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        wp_set_current_user( $this->manager );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- the lookup ---------------------------------------------------- */

    public function test_a_trial_manager_finds_a_staff_member_by_name(): void {
        [ $data, $status ] = $this->send( 'GET', 'staff', [ 'search' => 'Karin' ] );

        $this->assertSame( 200, $status );
        $rows = (array) ( $data['data']['staff'] ?? [] );
        $this->assertCount( 1, $rows );
        $this->assertSame( $this->coach_person, $rows[0]['person_id'] );
        $this->assertSame( $this->coach_user, $rows[0]['user_id'] );
        $this->assertSame( 'Karin Keeperstra', $rows[0]['display_name'] );
        $this->assertArrayNotHasKey( 'email', $rows[0], 'the directory must not hand out e-mail addresses' );
    }

    public function test_a_person_without_a_login_is_found_with_no_account_id(): void {
        $rows = $this->search( 'Zonderlogin' );

        $this->assertCount( 1, $rows );
        $this->assertSame( $this->no_login_person, $rows[0]['person_id'] );
        $this->assertNull( $rows[0]['user_id'] );
    }

    /**
     * #4091 — a staff account never entered in People used to come back
     * with `person_id` null. It is found only once it has a person record,
     * which the backfill (migration 0296) gives it.
     */
    public function test_staff_never_entered_in_people_are_found_once_backfilled(): void {
        $this->assertSame( [], $this->search( 'Alleenaccount' ), 'the directory reads People alone' );

        ( new StaffPersonProvisioner() )->backfill();

        $rows = $this->search( 'Alleenaccount' );
        $this->assertCount( 1, $rows );
        $this->assertIsInt( $rows[0]['person_id'] );
        $this->assertGreaterThan( 0, $rows[0]['person_id'] );
        $this->assertSame( $this->account_only, $rows[0]['user_id'] );
    }

    public function test_the_directory_never_returns_a_null_person_id(): void {
        ( new StaffPersonProvisioner() )->backfill();

        $all = ( new StaffDirectory() )->all();
        $this->assertNotEmpty( $all );
        foreach ( $all as $row ) {
            $this->assertIsInt( $row['person_id'], 'a staff row came back without a person record' );
            $this->assertGreaterThan( 0, $row['person_id'] );
        }
    }

    public function test_parents_and_players_never_appear(): void {
        $this->assertSame( [], $this->search( 'Oudervelt' ), 'a parent came back as staff' );
        $this->assertSame( [], $this->search( 'Spelersma' ), 'a player login came back as staff' );
    }

    public function test_a_search_shorter_than_two_characters_is_refused(): void {
        [ $data, $status ] = $this->send( 'GET', 'staff', [ 'search' => 'K' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_search', $data['errors'][0]['code'] ?? null );
    }

    public function test_one_search_returns_at_most_twenty(): void {
        global $wpdb;
        for ( $i = 1; $i <= 25; $i++ ) {
            $wpdb->insert( "{$wpdb->prefix}tt_people", [
                'club_id' => (int) CurrentClub::id(), 'first_name' => 'Veelstaf', 'last_name' => 'Nummer ' . $i,
                'role_type' => 'staff', 'status' => 'active',
            ] );
        }

        $this->assertCount( 20, $this->search( 'Veelstaf' ) );
    }

    public function test_a_scout_who_only_sits_on_panels_is_refused(): void {
        $scout = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $scout );

        [ , $status ] = $this->send( 'GET', 'staff', [ 'search' => 'Karin' ] );
        $this->assertSame( 403, $status );
    }

    /* ---- assigning the panel by person --------------------------------- */

    public function test_a_panellist_is_assigned_by_person_id(): void {
        [ $data, $status ] = $this->send( 'POST', 'trial-cases/' . $this->case_id . '/staff', [
            'person_id' => $this->coach_person, 'role_label' => 'Keepertrainer',
        ] );

        $this->assertSame( 200, $status );
        $this->assertSame( $this->coach_user, $data['data']['user_id'] ?? null );
        $this->assertTrue( ( new TrialCaseStaffRepository() )->isAssigned( $this->case_id, $this->coach_user ) );
    }

    public function test_a_person_without_an_account_is_refused_with_a_reason(): void {
        [ $data, $status ] = $this->send( 'POST', 'trial-cases/' . $this->case_id . '/staff', [
            'person_id' => $this->no_login_person,
        ] );

        $this->assertSame( 422, $status );
        $this->assertSame( 'no_account', $data['errors'][0]['code'] ?? null );
        $this->assertNotSame( '', (string) ( $data['errors'][0]['message'] ?? '' ) );
        $this->assertSame( [], ( new TrialCaseStaffRepository() )->listForCase( $this->case_id ) );
    }

    /** #4091 — `user_id` had its one deprecated release and is gone. */
    public function test_user_id_alone_is_refused_with_what_replaced_it(): void {
        [ $data, $status ] = $this->send( 'POST', 'trial-cases/' . $this->case_id . '/staff', [
            'user_id' => $this->coach_user,
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'field_removed', $data['errors'][0]['code'] ?? null );
        $this->assertStringContainsString( 'person_id', (string) ( $data['errors'][0]['message'] ?? '' ) );
        $this->assertSame( [], ( new TrialCaseStaffRepository() )->listForCase( $this->case_id ) );
    }

    public function test_user_id_next_to_person_id_is_refused_too(): void {
        [ $data, $status ] = $this->send( 'POST', 'trial-cases/' . $this->case_id . '/staff', [
            'person_id' => $this->coach_person, 'user_id' => $this->coach_user,
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'field_removed', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [], ( new TrialCaseStaffRepository() )->listForCase( $this->case_id ) );
    }

    public function test_neither_id_is_refused(): void {
        [ , $status ] = $this->send( 'POST', 'trial-cases/' . $this->case_id . '/staff', [ 'role_label' => 'Keepertrainer' ] );
        $this->assertSame( 400, $status );
    }

    /* ---- helpers ------------------------------------------------------- */

    /** @return array<int,array<string,mixed>> */
    private function search( string $term ): array {
        [ $data, $status ] = $this->send( 'GET', 'staff', [ 'search' => $term ] );
        $this->assertSame( 200, $status );
        return array_values( (array) ( $data['data']['staff'] ?? [] ) );
    }

    /**
     * @param array<string,mixed> $params
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $params = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $method === 'GET' ) {
            $request->set_query_params( $params );
        } elseif ( $params ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $params ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
