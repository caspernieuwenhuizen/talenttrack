<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3816 (slice 2 of #3603) — the body contract on the five write routes
 * the Academy HQ run actually tripped over.
 *
 * `POST` / `PUT /activities`, `POST` / `PUT /people/{id}` and
 * `POST /functional-roles/assignments` all read a fixed set of fields and
 * ignored everything else, so a misspelled field name answered 200 over a
 * value nothing had stored. Each now declares its `args` and runs
 * `BaseController::checkBody()`.
 *
 * Two properties are tested per route, because they are the two ways a
 * slice like this goes wrong:
 *
 *   - **strict**: a key outside the declaration is `400 unknown_field`,
 *     naming it and listing what the route does take;
 *   - **still partial**: declaring the fields must not turn an omitted one
 *     into a cleared one. `PUT /people/{id}` failed that before this
 *     change — a body carrying only a phone number erased the name.
 *
 * Core refuses an absent required key before the callback runs, so a body
 * that is both missing a required key and carrying an unknown one answers
 * `missing_fields` rather than `unknown_field`. The tests below keep the
 * two cases apart on purpose.
 */
final class WriteRouteArgsTest extends WP_UnitTestCase {

    /** @var int */
    private $admin;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $path, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $path );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( (object) $body ) );

        $response = rest_get_server()->dispatch( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }

    /** @param array<string,mixed> $data */
    private function firstError( array $data ): array {
        return (array) ( $data['errors'][0] ?? [] );
    }

    private function makeTeam(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => CurrentClub::id(),
            'name'    => 'JO17-1',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePerson(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => CurrentClub::id(),
            'first_name' => 'Ingrid',
            'last_name'  => 'de Vries',
            'email'      => 'ingrid@example.test',
            'phone'      => '0612345678',
            'role_type'  => 'head_coach',
            'status'     => 'active',
        ] );

        return (int) $wpdb->insert_id;
    }

    /** @return array<string,mixed> */
    private function personRow( int $id ): array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_people WHERE id = %d",
            $id
        ), ARRAY_A );

        return is_array( $row ) ? $row : [];
    }

    // ---- POST /activities --------------------------------------------------

    public function test_creating_an_activity_refuses_a_field_it_does_not_take(): void {
        [ $data, $status ] = $this->send( 'POST', 'activities', [
            'title'        => 'Training JO17-1',
            'session_date' => '2026-10-01',
            'titel'        => 'typo',
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'unknown_field', $error['code'] ?? null );
        $this->assertSame( [ 'titel' ], $error['details']['fields'] ?? null );
        $this->assertContains( 'title', (array) ( $error['details']['allowed'] ?? [] ) );
    }

    public function test_creating_an_activity_with_an_empty_body_names_what_is_missing(): void {
        [ $data, $status ] = $this->send( 'POST', 'activities', [] );

        $this->assertSame( 400, $status );

        $error  = $this->firstError( $data );
        $fields = (array) ( $error['details']['fields'] ?? [] );
        $this->assertSame( 'missing_fields', $error['code'] ?? null );
        sort( $fields );
        $this->assertSame( [ 'session_date', 'title' ], $fields );
    }

    public function test_a_declared_activity_body_still_creates(): void {
        $team_id = $this->makeTeam();

        [ $data, $status ] = $this->send( 'POST', 'activities', [
            'title'             => 'Training JO17-1',
            'session_date'      => '2026-10-01',
            'team_id'           => $team_id,
            'start_time'        => '18:30',
            'end_time'          => '20:00',
            'activity_type_key' => 'training',
            'location'          => 'Veld 2',
            'notes'             => 'Positiespel.',
        ] );

        $this->assertSame( 200, $status );
        $this->assertGreaterThan( 0, (int) ( $data['data']['id'] ?? 0 ) );
    }

    // ---- PUT /activities/{id} ----------------------------------------------

    public function test_updating_an_activity_refuses_a_field_it_does_not_take(): void {
        $activity_id = $this->createActivity();

        [ $data, $status ] = $this->send( 'PUT', 'activities/' . $activity_id, [
            'locatie' => 'Veld 3',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $this->firstError( $data )['code'] ?? null );
    }

    /**
     * The partial-update contract (CLAUDE.md §6). The edit form posts the
     * whole record, but the planner, an integration and a future per-panel
     * save all send a slice, and none of them may clear what they leave
     * out.
     */
    public function test_updating_an_activity_leaves_an_omitted_field_alone(): void {
        global $wpdb;

        $activity_id = $this->createActivity();

        [ , $status ] = $this->send( 'PUT', 'activities/' . $activity_id, [
            'location' => 'Veld 3',
        ] );
        $this->assertSame( 200, $status );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_activities WHERE id = %d",
            $activity_id
        ), ARRAY_A );

        $this->assertSame( 'Veld 3', (string) ( $row['location'] ?? '' ) );
        $this->assertSame( 'Training JO17-1', (string) ( $row['title'] ?? '' ), 'the title survived' );
        $this->assertSame( '2026-10-01', (string) ( $row['session_date'] ?? '' ), 'the date survived' );
        $this->assertSame( 'Positiespel.', (string) ( $row['notes'] ?? '' ), 'the notes survived' );
    }

    private function createActivity(): int {
        $team_id = $this->makeTeam();

        [ $data ] = $this->send( 'POST', 'activities', [
            'title'             => 'Training JO17-1',
            'session_date'      => '2026-10-01',
            'team_id'           => $team_id,
            'activity_type_key' => 'training',
            'location'          => 'Veld 2',
            'notes'             => 'Positiespel.',
        ] );

        return (int) ( $data['data']['id'] ?? 0 );
    }

    // ---- POST /people ------------------------------------------------------

    public function test_creating_a_person_refuses_a_field_it_does_not_take(): void {
        [ $data, $status ] = $this->send( 'POST', 'people', [
            'first_name' => 'Ingrid',
            'last_name'  => 'de Vries',
            'telephone'  => '0612345678',
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'unknown_field', $error['code'] ?? null );
        $this->assertSame( [ 'telephone' ], $error['details']['fields'] ?? null );
        $this->assertContains( 'phone', (array) ( $error['details']['allowed'] ?? [] ) );
    }

    public function test_creating_a_person_with_an_empty_body_names_what_is_missing(): void {
        [ $data, $status ] = $this->send( 'POST', 'people', [] );

        $this->assertSame( 400, $status );

        $error  = $this->firstError( $data );
        $fields = (array) ( $error['details']['fields'] ?? [] );
        $this->assertSame( 'missing_fields', $error['code'] ?? null );
        sort( $fields );
        $this->assertSame( [ 'first_name', 'last_name' ], $fields );
    }

    public function test_a_declared_person_body_still_creates(): void {
        [ $data, $status ] = $this->send( 'POST', 'people', [
            'first_name' => 'Ingrid',
            'last_name'  => 'de Vries',
            'email'      => 'ingrid@example.test',
            'phone'      => '0612345678',
        ] );

        $this->assertSame( 200, $status );
        $this->assertGreaterThan( 0, (int) ( $data['data']['id'] ?? 0 ) );
    }

    // ---- PUT /people/{id} --------------------------------------------------

    public function test_updating_a_person_refuses_a_field_it_does_not_take(): void {
        $person_id = $this->makePerson();

        [ $data, $status ] = $this->send( 'PUT', 'people/' . $person_id, [
            'telephone' => '0687654321',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $this->firstError( $data )['code'] ?? null );
    }

    /**
     * The one this slice could have destroyed data with. `extract()` wrote
     * both name columns on every call, defaulted to `''`, so a `PUT`
     * carrying only a phone number **erased the person's name**.
     */
    public function test_updating_a_person_leaves_an_omitted_name_alone(): void {
        $person_id = $this->makePerson();

        [ , $status ] = $this->send( 'PUT', 'people/' . $person_id, [
            'phone' => '0687654321',
        ] );
        $this->assertSame( 200, $status );

        $row = $this->personRow( $person_id );
        $this->assertSame( '0687654321', (string) ( $row['phone'] ?? '' ) );
        $this->assertSame( 'Ingrid',    (string) ( $row['first_name'] ?? '' ), 'the first name survived' );
        $this->assertSame( 'de Vries',  (string) ( $row['last_name'] ?? '' ),  'the last name survived' );
    }

    public function test_updating_a_person_still_writes_what_it_is_sent(): void {
        $person_id = $this->makePerson();

        [ , $status ] = $this->send( 'PUT', 'people/' . $person_id, [
            'first_name' => 'Ingrid-Marie',
            'email'      => 'im@example.test',
        ] );
        $this->assertSame( 200, $status );

        $row = $this->personRow( $person_id );
        $this->assertSame( 'Ingrid-Marie',    (string) ( $row['first_name'] ?? '' ) );
        $this->assertSame( 'im@example.test', (string) ( $row['email'] ?? '' ) );
        $this->assertSame( 'de Vries',        (string) ( $row['last_name'] ?? '' ) );
    }

    // ---- the trap this slice nearly walked into ----------------------------

    /**
     * Declaring a field `required` in a route's `args` moves the refusal
     * **ahead of the permission callback**: core checks required params in
     * `WP_REST_Request::has_valid_params()`, which `dispatch()` runs before
     * `respond_to_request()` gets to the capability gate. An anonymous
     * `POST /activities` then answers `400` naming the body's fields
     * instead of the `401` it owes — an authorization answer decided by
     * the shape of the body.
     *
     * So none of these routes declares `required`; their handlers name the
     * missing fields behind the gate. `RestSmokeTest` covers the same
     * ground across every route, and caught this; this test says why the
     * declarations look the way they do.
     *
     * @dataProvider provideWriteRoutes
     */
    public function test_an_anonymous_write_is_denied_before_the_body_is_read( string $method, string $path ): void {
        wp_set_current_user( 0 );

        [ , $status ] = $this->send( $method, $path, [] );

        $this->assertContains( $status, [ 401, 403 ], "$method $path must answer the auth question first" );
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function provideWriteRoutes(): array {
        return [
            'POST /activities'                     => [ 'POST', 'activities' ],
            'PUT /activities/{id}'                 => [ 'PUT',  'activities/1' ],
            'POST /people'                         => [ 'POST', 'people' ],
            'PUT /people/{id}'                     => [ 'PUT',  'people/1' ],
            'POST /functional-roles/assignments'   => [ 'POST', 'functional-roles/assignments' ],
        ];
    }

    // ---- POST /functional-roles/assignments --------------------------------

    public function test_creating_an_assignment_with_an_empty_body_names_all_three_ids(): void {
        [ $data, $status ] = $this->send( 'POST', 'functional-roles/assignments', [] );

        $this->assertSame( 400, $status );

        $error  = $this->firstError( $data );
        $fields = (array) ( $error['details']['fields'] ?? [] );
        $this->assertSame( 'missing_fields', $error['code'] ?? null );
        sort( $fields );
        $this->assertSame( [ 'functional_role_id', 'person_id', 'team_id' ], $fields );
    }

    public function test_creating_an_assignment_refuses_a_field_it_does_not_take(): void {
        [ $data, $status ] = $this->send( 'POST', 'functional-roles/assignments', [
            'team_id'            => 1,
            'person_id'          => 1,
            'functional_role_id' => 1,
            'role_in_team'       => 'assistant',
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'unknown_field', $error['code'] ?? null );
        $this->assertSame( [ 'role_in_team' ], $error['details']['fields'] ?? null );
        $this->assertContains( 'start_date', (array) ( $error['details']['allowed'] ?? [] ) );
    }
}
