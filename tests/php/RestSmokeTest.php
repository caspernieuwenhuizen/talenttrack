<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * Tier 2 (#1388) — REST smoke suite.
 *
 * Two complementary checks over the live route table (every plugin route
 * registered on `rest_api_init`):
 *
 *   (a) Denial-path matrix — the historically buggy direction. For ~20
 *       high-risk routes, an UNAUTHENTICATED caller must be denied
 *       (401/403) — never 200 (silent data leak) and never >=500 (a
 *       crashing permission_callback / dispatcher). A route that 200s or
 *       500s unauthenticated is the exact bug class this suite guards.
 *
 *   (b) Targeted happy-path + envelope shape — with an authorized
 *       administrator, exercise the player-injuries CRUD (the sensitive
 *       medical surface), the player delete-archive contract, and an
 *       evaluations insert. Assertions are on STATUS CODE + ENVELOPE
 *       SHAPE (`success` / `data` / `errors`), not full content.
 *
 * The administrator role holds the tt_* caps after the bootstrap
 * migrations seed them, and `AuthorizationService::userHasPermission`
 * short-circuits true for `administrator` — so an admin passes both the
 * cap-level permission_callbacks and the per-player `canEditPlayer`
 * entity checks the injuries/evaluations handlers run.
 */
final class RestSmokeTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        // Grant the administrator role the tt_* caps. The plugin grants
        // these via ensureCapabilities() on activation / admin_init, neither
        // of which fires in the wp-env test bootstrap (it runs migrations
        // only). Without this the happy-path admin would fail the cap-level
        // permission_callbacks. Idempotent.
        ( new RolesService() )->ensureCapabilities();

        // Rebuild the REST server so every plugin route registers freshly
        // for this test (WP_UnitTestCase otherwise shares one across the
        // process and may have registered before the plugin booted).
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /**
     * High-risk routes that MUST deny an unauthenticated caller.
     *
     * Curated from `grep -rn register_rest_route src/` to cover the
     * sensitive + write + cross-cutting surfaces: players (read/write/
     * delete), evaluations (insert), player injuries (the medical CRUD),
     * teams, activities, goals, config (POST), audit-log, cohort-board,
     * data-browser, translations, custom-fields. Path params use a
     * concrete id (e.g. /players/1).
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public function provideDenialRoutes(): array {
        return [
            // Players — read / write / delete / lifecycle.
            'GET /players'                  => [ 'GET',    '/talenttrack/v1/players' ],
            'POST /players'                 => [ 'POST',   '/talenttrack/v1/players' ],
            'GET /players/1'                => [ 'GET',    '/talenttrack/v1/players/1' ],
            'PUT /players/1'                => [ 'PUT',    '/talenttrack/v1/players/1' ],
            'DELETE /players/1'             => [ 'DELETE', '/talenttrack/v1/players/1' ],
            'POST /players/import'          => [ 'POST',   '/talenttrack/v1/players/import' ],
            'GET /players/1/timeline'       => [ 'GET',    '/talenttrack/v1/players/1/timeline' ],

            // Player injuries — minors' medical records, the most sensitive class.
            'GET /players/1/injuries'       => [ 'GET',    '/talenttrack/v1/players/1/injuries' ],
            'POST /players/1/injuries'      => [ 'POST',   '/talenttrack/v1/players/1/injuries' ],
            'PUT /player-injuries/1'        => [ 'PUT',    '/talenttrack/v1/player-injuries/1' ],
            'DELETE /player-injuries/1'     => [ 'DELETE', '/talenttrack/v1/player-injuries/1' ],

            // Evaluations — insert (development data).
            'GET /evaluations'              => [ 'GET',    '/talenttrack/v1/evaluations' ],
            'POST /evaluations'             => [ 'POST',   '/talenttrack/v1/evaluations' ],

            // Teams.
            'GET /teams'                    => [ 'GET',    '/talenttrack/v1/teams' ],
            'POST /teams'                   => [ 'POST',   '/talenttrack/v1/teams' ],

            // Activities + goals (write surfaces).
            'POST /activities'              => [ 'POST',   '/talenttrack/v1/activities' ],
            'POST /goals'                   => [ 'POST',   '/talenttrack/v1/goals' ],

            // Cross-cutting / admin surfaces.
            'POST /config'                  => [ 'POST',   '/talenttrack/v1/config' ],
            'GET /audit-log'                => [ 'GET',    '/talenttrack/v1/audit-log' ],
            'GET /cohort-board'             => [ 'GET',    '/talenttrack/v1/cohort-board' ],
            'GET /data-browser/tables'      => [ 'GET',    '/talenttrack/v1/data-browser/tables' ],
            'POST /translations/settings'   => [ 'POST',   '/talenttrack/v1/translations/settings' ],
            'GET /custom-fields'            => [ 'GET',    '/talenttrack/v1/custom-fields' ],
            'POST /custom-fields'           => [ 'POST',   '/talenttrack/v1/custom-fields' ],
        ];
    }

    /**
     * @dataProvider provideDenialRoutes
     */
    public function test_unauthenticated_request_is_denied( string $method, string $route ): void {
        wp_set_current_user( 0 );

        $response = rest_do_request( new WP_REST_Request( $method, $route ) );
        $status   = $response->get_status();

        $label = "$method $route";

        // The bug class: a route that answers 200 to an anonymous caller
        // (silent leak) or 500s (a crashing/guessing permission_callback).
        $this->assertNotSame( 200, $status, "$label must NOT return 200 to an unauthenticated caller" );
        $this->assertLessThan( 500, $status, "$label must NOT 500 for an unauthenticated caller (got $status)" );
        $this->assertContains(
            $status,
            [ 401, 403 ],
            "$label must deny an unauthenticated caller with 401 or 403 (got $status)"
        );
    }

    /**
     * Player injuries CRUD round-trip as an administrator (#1388 Tier 2).
     * The injuries surface is the sensitive medical class — create → list
     * → update → archive, asserting status + envelope each step.
     */
    public function test_player_injuries_crud_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $player_id = self::createPlayer();

        // CREATE.
        $create = new WP_REST_Request( 'POST', '/talenttrack/v1/players/' . $player_id . '/injuries' );
        $create->set_header( 'Content-Type', 'application/json' );
        $create->set_body( wp_json_encode( [
            'started_on' => '2026-01-15',
            'notes'      => 'Smoke-test injury record.',
        ] ) );
        $create_res = rest_do_request( $create );

        $this->assertContains( $create_res->get_status(), [ 200, 201 ], 'injury create succeeds' );
        $body = $create_res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $injury_id = (int) ( $body['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $injury_id, 'create returns a new injury id' );

        // LIST.
        $list_res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/injuries' ) );
        $this->assertSame( 200, $list_res->get_status() );
        $list_body = $list_res->get_data();
        $this->assertEnvelopeSuccess( $list_body );
        $this->assertArrayHasKey( 'injuries', $list_body['data'] );

        // UPDATE.
        $update = new WP_REST_Request( 'PUT', '/talenttrack/v1/player-injuries/' . $injury_id );
        $update->set_header( 'Content-Type', 'application/json' );
        $update->set_body( wp_json_encode( [ 'notes' => 'Updated note.' ] ) );
        $update_res = rest_do_request( $update );
        $this->assertSame( 200, $update_res->get_status(), 'injury update succeeds' );
        $this->assertEnvelopeSuccess( $update_res->get_data() );

        // DELETE (archive).
        $delete_res = rest_do_request( new WP_REST_Request( 'DELETE', '/talenttrack/v1/player-injuries/' . $injury_id ) );
        $this->assertSame( 200, $delete_res->get_status(), 'injury archive succeeds' );
        $delete_body = $delete_res->get_data();
        $this->assertEnvelopeSuccess( $delete_body );
        $this->assertTrue( (bool) ( $delete_body['data']['archived'] ?? false ), 'delete archives the injury' );
    }

    /**
     * Player delete contract: the controller SOFT-ARCHIVES (sets
     * `archived_at`) rather than hard-deleting, and cascades a soft-archive
     * to the player's note thread messages (#0085). Assert the documented
     * archive behaviour + the cascade, not a row removal.
     */
    public function test_player_delete_archives_and_cascades(): void {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $player_id = self::createPlayer();

        // A dependent row: a note thread message attached to the player.
        $wpdb->insert( $wpdb->prefix . 'tt_thread_messages', [
            'club_id'        => 1,
            'thread_type'    => 'player',
            'thread_id'      => $player_id,
            'body'           => 'Dependent note for cascade test.',
            'author_user_id' => $uid,
            'created_at'     => current_time( 'mysql' ),
        ] );

        $delete_res = rest_do_request( new WP_REST_Request( 'DELETE', '/talenttrack/v1/players/' . $player_id ) );
        $this->assertSame( 200, $delete_res->get_status() );
        $body = $delete_res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $this->assertTrue( (bool) ( $body['data']['archived'] ?? false ), 'delete archives the player (soft-delete contract)' );

        // The player row still exists but is archived.
        $archived_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT archived_at FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        $this->assertNotNull( $archived_at, 'player row is archived, not hard-deleted' );

        // The dependent note thread message is soft-archived (deleted_at set).
        $msg_deleted_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT deleted_at FROM {$wpdb->prefix}tt_thread_messages
              WHERE thread_type = 'player' AND thread_id = %d",
            $player_id
        ) );
        $this->assertNotNull( $msg_deleted_at, 'dependent note thread message is cascade-archived' );
    }

    /**
     * Evaluations insert — minimal valid payload as an administrator.
     * Admin bypasses the coach_owns_player scope check, so this exercises
     * the insert path + envelope shape directly.
     */
    public function test_evaluation_insert_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $player_id = self::createPlayer();

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/evaluations' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'player_id' => $player_id,
            'eval_date' => '2026-02-01',
            'notes'     => 'Smoke-test evaluation.',
        ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status(), 'evaluation insert succeeds' );
        $body = $res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $this->assertGreaterThan( 0, (int) ( $body['data']['id'] ?? 0 ), 'insert returns the new evaluation id' );
    }

    /**
     * A malformed authorized call returns a 4xx with the error envelope,
     * not a 500 or a silent success. Evaluations insert without the
     * required player_id / eval_date is the cheapest malformed case.
     */
    public function test_evaluation_insert_malformed_is_bad_request(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/evaluations' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'notes' => 'No player, no date.' ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 400, $res->get_status(), 'missing required fields is a 400' );
        $body = $res->get_data();
        $this->assertIsArray( $body );
        $this->assertFalse( (bool) ( $body['success'] ?? true ), 'malformed call is not a success' );
        $this->assertNotEmpty( $body['errors'] ?? [], 'malformed call carries an error entry' );
    }

    /**
     * Assert the TalentTrack success envelope shape: `success === true`,
     * a `data` key, and an empty `errors` array.
     *
     * @param mixed $body
     */
    private function assertEnvelopeSuccess( $body ): void {
        $this->assertIsArray( $body, 'response body is the envelope array' );
        $this->assertArrayHasKey( 'success', $body );
        $this->assertArrayHasKey( 'data', $body );
        $this->assertArrayHasKey( 'errors', $body );
        $this->assertTrue( (bool) $body['success'], 'envelope reports success' );
        $this->assertSame( [], $body['errors'], 'success envelope carries no errors' );
    }

    /** Create a minimal active player in the current club and return its id. */
    private static function createPlayer(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Smoke',
            'last_name'  => 'Tester',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }
}
