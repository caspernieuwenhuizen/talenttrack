<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3818 (slice 4 of #3603) — the recruitment write routes declare what they
 * take: prospects, trial cases and test trainings.
 *
 * Until they did, a key the route had not thought of was dropped on the way
 * in and the caller was told the write had worked. The rule is strict: an
 * undeclared key is `400 unknown_field` naming it, never a warning and never
 * a silent drop.
 *
 * The other half of the contract is that declaring `args` must not turn an
 * omitted field into a cleared one (CLAUDE.md §6). That is the one way this
 * change could destroy data, so every update route here carries a test that
 * an omitted field is left alone.
 */
final class RecruitmentRouteArgsTest extends WP_UnitTestCase {

    private int $admin = 0;
    private int $player = 0;
    private int $track = 0;
    private int $case = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $this->track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'first_name' => 'Proef', 'last_name' => 'Speler', 'status' => 'trial' ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id' => $club, 'player_id' => $this->player, 'track_id' => $this->track,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'open',
            'notes' => 'Opgestart na de testtraining.', 'created_by' => 1,
        ] );
        $this->case = (int) $wpdb->insert_id;

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- every migrated route publishes its fields ---------------------- */

    public function test_the_migrated_routes_declare_their_args(): void {
        $expect = [
            '/talenttrack/v1/prospects/log'                            => [ 'POST', [] ],
            '/talenttrack/v1/test-trainings'                           => [ 'POST', [ 'date', 'location', 'age_group_lookup_id', 'coach_user_id', 'notes' ] ],
            '/talenttrack/v1/trial-cases'                              => [ 'POST', [ 'player_id', 'track_id', 'start_date', 'end_date', 'notes' ] ],
            '/talenttrack/v1/trial-cases/(?P<id>\d+)'                  => [ 'PUT',  [ 'track_id', 'start_date', 'end_date', 'status', 'notes' ] ],
            '/talenttrack/v1/trial-cases/(?P<id>\d+)/extend'           => [ 'POST', [ 'new_end_date', 'justification' ] ],
            '/talenttrack/v1/trial-cases/(?P<id>\d+)/staff'            => [ 'POST', [ 'user_id', 'role_label' ] ],
            '/talenttrack/v1/trial-cases/(?P<id>\d+)/letters'          => [ 'POST', [ 'audience', 'strengths_summary', 'growth_areas' ] ],
            '/talenttrack/v1/trial-cases/(?P<id>\d+)/inputs/release'   => [ 'POST', [] ],
            '/talenttrack/v1/trial-reminders/run'                      => [ 'POST', [] ],
        ];

        $routes = rest_get_server()->get_routes();
        foreach ( $expect as $route => [ $method, $fields ] ) {
            $this->assertArrayHasKey( $route, $routes, "{$route} is not registered" );
            $args = null;
            foreach ( $routes[ $route ] as $handler ) {
                if ( ! empty( $handler['methods'][ $method ] ) ) $args = $handler['args'];
            }
            $this->assertIsArray( $args, "{$route} declares no args for {$method}" );
            foreach ( $fields as $field ) {
                $this->assertArrayHasKey( $field, $args, "{$route} does not declare {$field}" );
            }
        }
    }

    /* ---- prospects ------------------------------------------------------ */

    public function test_the_log_route_takes_no_body(): void {
        [ $data, $status ] = $this->send( 'POST', 'prospects/log', [ 'first_name' => 'Nieuw' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'first_name' ], $data['errors'][0]['details']['fields'] ?? null );
    }

    /* ---- test trainings ------------------------------------------------- */

    public function test_a_test_training_is_created_from_a_declared_body(): void {
        [ $data, $status ] = $this->send( 'POST', 'test-trainings', [
            'date' => '2026-10-03', 'location' => 'Hoofdveld', 'notes' => 'Neem scheenbeschermers mee.',
        ] );

        $this->assertSame( 200, $status );
        $this->assertGreaterThan( 0, (int) ( $data['data']['id'] ?? 0 ) );
    }

    public function test_an_undeclared_test_training_key_is_refused_and_nothing_is_created(): void {
        $before = $this->countTestTrainings();

        [ $data, $status ] = $this->send( 'POST', 'test-trainings', [
            'date' => '2026-10-03', 'age_group' => 'u13',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertContains( 'age_group_lookup_id', $data['errors'][0]['details']['allowed'] ?? [] );
        $this->assertSame( $before, $this->countTestTrainings() );
    }

    public function test_a_test_training_without_a_date_is_refused(): void {
        [ , $status ] = $this->send( 'POST', 'test-trainings', [ 'location' => 'Hoofdveld' ] );
        $this->assertSame( 400, $status );
    }

    /* ---- trial cases ---------------------------------------------------- */

    public function test_an_undeclared_trial_case_key_is_refused_whole(): void {
        $before = $this->countCases();

        [ $data, $status ] = $this->send( 'POST', 'trial-cases', [
            'player_id' => $this->player, 'coach_id' => 7,
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( $before, $this->countCases(), 'the valid half of a refused body must not be written' );
    }

    public function test_a_trial_case_without_a_player_is_refused(): void {
        [ , $status ] = $this->send( 'POST', 'trial-cases', [ 'track_id' => $this->track ] );
        $this->assertSame( 400, $status );
    }

    public function test_a_player_id_of_the_wrong_type_is_refused(): void {
        [ , $status ] = $this->send( 'POST', 'trial-cases', [ 'player_id' => 'de spits' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 1, $this->countCases(), 'only the seeded case exists' );
    }

    public function test_an_undeclared_update_key_leaves_the_case_alone(): void {
        [ $data, $status ] = $this->send( 'PUT', 'trial-cases/' . $this->case, [
            'end_date' => '2026-10-31', 'extended_until' => '2026-10-31',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( '2026-09-30', $this->caseRow()['end_date'] );
    }

    /**
     * The rule declaring `args` must not break: a PUT that carries one field
     * writes that field and nothing else. An update route that rebuilt the
     * row from the request would blank the notes here.
     */
    public function test_an_omitted_field_is_left_alone_on_update(): void {
        [ , $status ] = $this->send( 'PUT', 'trial-cases/' . $this->case, [ 'end_date' => '2026-10-31' ] );
        $this->assertSame( 200, $status );

        $row = $this->caseRow();
        $this->assertSame( '2026-10-31', $row['end_date'] );
        $this->assertSame( 'Opgestart na de testtraining.', $row['notes'] );
        $this->assertSame( '2026-09-01', $row['start_date'] );
        $this->assertSame( $this->track, (int) $row['track_id'] );
    }

    /**
     * The body carries both required keys, so this is the route's own
     * refusal rather than core's missing-parameter one: core checks the
     * declared `required` keys before the callback runs, and a body that is
     * missing one never reaches `checkBody()` to be told about the unknown.
     */
    public function test_an_undeclared_extension_key_is_refused(): void {
        [ $data, $status ] = $this->send( 'POST', 'trial-cases/' . $this->case . '/extend', [
            'new_end_date'  => '2026-10-31',
            'justification' => 'Nog een blok nodig om de keeper te zien.',
            'reason'        => 'Nog een blok nodig.',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertContains( 'justification', $data['errors'][0]['details']['allowed'] ?? [] );
        $this->assertSame( '2026-09-30', $this->caseRow()['end_date'] );
    }

    public function test_an_extension_without_a_justification_is_refused(): void {
        [ , $status ] = $this->send( 'POST', 'trial-cases/' . $this->case . '/extend', [
            'new_end_date' => '2026-10-31',
        ] );
        $this->assertSame( 400, $status );
        $this->assertSame( '2026-09-30', $this->caseRow()['end_date'] );
    }

    public function test_an_undeclared_staff_key_is_refused(): void {
        [ $data, $status ] = $this->send( 'POST', 'trial-cases/' . $this->case . '/staff', [
            'user_id' => $this->admin, 'role' => 'Keepertrainer',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertContains( 'role_label', $data['errors'][0]['details']['allowed'] ?? [] );
    }

    public function test_an_undeclared_letter_key_is_refused(): void {
        [ $data, $status ] = $this->send( 'POST', 'trial-cases/' . $this->case . '/letters', [
            'audience' => 'trial_admittance', 'strengths' => 'Leest het spel vroeg.',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertContains( 'strengths_summary', $data['errors'][0]['details']['allowed'] ?? [] );
    }

    public function test_the_release_and_reminder_routes_take_no_body(): void {
        [ $release, $release_status ] = $this->send( 'POST', 'trial-cases/' . $this->case . '/inputs/release', [ 'notify' => true ] );
        $this->assertSame( 400, $release_status );
        $this->assertSame( 'unknown_field', $release['errors'][0]['code'] ?? null );

        [ $run, $run_status ] = $this->send( 'POST', 'trial-reminders/run', [ 'dry_run' => true ] );
        $this->assertSame( 400, $run_status );
        $this->assertSame( 'unknown_field', $run['errors'][0]['code'] ?? null );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function caseRow(): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT start_date, end_date, track_id, status, notes FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d",
            $this->case
        ), ARRAY_A );
    }

    private function countCases(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_trial_cases" );
    }

    private function countTestTrainings(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_test_trainings" );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body = [] ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }
}
