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

        // A smoke test asserts on the response envelope, not on noise a
        // downstream hook subscriber may emit. Suppress wpdb error echo so a
        // secondary query inside a `do_action` listener (e.g. the journey
        // subscriber on `tt_evaluation_saved`) can't flip the test to "risky"
        // via unexpected output.
        global $wpdb;
        $wpdb->hide_errors();

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
            'POST /activities/1/status'     => [ 'POST',   '/talenttrack/v1/activities/1/status' ],
            'POST /goals'                   => [ 'POST',   '/talenttrack/v1/goals' ],
            // #2382 — attendance-grid read + bulk write (tt_edit_activities).
            'GET /activities/attendance-grid' => [ 'GET',  '/talenttrack/v1/activities/attendance-grid' ],
            'POST /attendance/bulk'         => [ 'POST',   '/talenttrack/v1/attendance/bulk' ],
            // #2386 — minutes-grid read + bulk write (tt_edit_activities).
            'GET /activities/minutes-grid'  => [ 'GET',    '/talenttrack/v1/activities/minutes-grid' ],
            'POST /minutes/bulk'            => [ 'POST',   '/talenttrack/v1/minutes/bulk' ],

            // #2592 — media. The byte-serving routes matter most here: on
            // nginx the media directory's own guard does nothing, so an
            // unauthenticated 200 from /file would be a photograph of a
            // child served to anyone who asks.
            'GET /media'                    => [ 'GET',    '/talenttrack/v1/media' ],
            'POST /media'                   => [ 'POST',   '/talenttrack/v1/media' ],
            'GET /media/{uuid}/file'        => [ 'GET',    '/talenttrack/v1/media/00000000-0000-4000-8000-000000000000/file' ],
            'GET /media/{uuid}/thumb'       => [ 'GET',    '/talenttrack/v1/media/00000000-0000-4000-8000-000000000000/thumb' ],
            'DELETE /media/{uuid}'          => [ 'DELETE', '/talenttrack/v1/media/00000000-0000-4000-8000-000000000000' ],
            'GET /players/1/media'          => [ 'GET',    '/talenttrack/v1/players/1/media' ],

            // #2831 — the principles an activity is linked to. Development
            // content about a squad, so an anonymous caller gets nothing.
            'GET /activities/1/principles'  => [ 'GET',    '/talenttrack/v1/activities/1/principles' ],

            // #2835 — minutes share. Per-player development data about a
            // squad; an anonymous caller gets nothing.
            'GET /teams/1/minutes-share'    => [ 'GET',    '/talenttrack/v1/teams/1/minutes-share' ],
            'GET /teams/1/minutes-share/1'  => [ 'GET',    '/talenttrack/v1/teams/1/minutes-share/1' ],

            // #2838 — prospects. A prospect row is a named minor who is not
            // yet a player, carrying a parent's name, email, phone and the
            // family's consent state. An anonymous caller gets nothing, and
            // the PATCH least of all.
            'GET /prospects'                => [ 'GET',    '/talenttrack/v1/prospects' ],
            'GET /prospects/1'              => [ 'GET',    '/talenttrack/v1/prospects/1' ],
            'PATCH /prospects/1'            => [ 'PATCH',  '/talenttrack/v1/prospects/1' ],

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

        $request = new WP_REST_Request( $method, $route );
        // Some routes validate a required arg (e.g. cohort-board's `team_id`)
        // BEFORE the permission_callback runs; without it WP short-circuits to
        // 400 and we never exercise the auth gate we're actually testing. Seed
        // the common required params so every route reaches its
        // permission_callback, where the unauthenticated denial lives. Unused
        // params on the other routes are harmless.
        $request->set_param( 'team_id', 1 );
        $request->set_param( 'id', 1 );

        $response = rest_do_request( $request );
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
     * #2831 — the principles route answers with the envelope and an empty
     * list for an activity with none linked, rather than a 404. "This match
     * is working on nothing yet" is an answer; a 404 would say the activity
     * does not exist, and a client cannot tell the two apart.
     */
    public function test_activity_principles_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $activity_id = self::createActivity();

        $req = new WP_REST_Request( 'GET', "/talenttrack/v1/activities/{$activity_id}/principles" );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status(), 'principles read succeeds' );
        $body = $res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $this->assertSame( $activity_id, (int) ( $body['data']['activity_id'] ?? 0 ) );
        $this->assertSame( 0, (int) ( $body['data']['count'] ?? -1 ), 'no principles linked yet' );
        $this->assertSame( [], $body['data']['principles'] ?? null, 'empty list, not null' );
    }

    /** #2831 — an unknown activity is a 404, not an empty list. */
    public function test_activity_principles_unknown_activity_is_not_found(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities/99999999/principles' );
        $res = rest_do_request( $req );

        $this->assertSame( 404, $res->get_status() );
    }

    /**
     * #2835 — the minutes-share route answers with the envelope, the target
     * the academy configured, and an empty squad for a team that has played
     * nothing. Empty rather than 404: "no minutes to share out yet" is an
     * answer about a team that exists.
     */
    public function test_team_minutes_share_happy_path(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Smoke FC' ] );
        $team_id = (int) $wpdb->insert_id;

        $req = new WP_REST_Request( 'GET', "/talenttrack/v1/teams/{$team_id}/minutes-share" );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status(), 'minutes-share read succeeds' );
        $body = $res->get_data();
        $this->assertEnvelopeSuccess( $body );
        $this->assertSame( $team_id, (int) ( $body['data']['team_id'] ?? 0 ) );
        $this->assertSame( 0, (int) ( $body['data']['available_minutes'] ?? -1 ) );
        $this->assertSame( [], $body['data']['players'] ?? null, 'empty squad, not null' );
        // The target travels with the answer — a client should not have to
        // fetch config separately to know what "below target" means. Asserted
        // as a present, in-range integer rather than a specific number: the
        // value is academy-configurable, and pinning it here would make this
        // suite fail on an install that simply chose 25%.
        $this->assertArrayHasKey( 'target_pct', $body['data'], 'the target travels with the answer' );
        $target = (int) $body['data']['target_pct'];
        $this->assertGreaterThanOrEqual( 0, $target );
        $this->assertLessThanOrEqual( 100, $target );
    }

    /** #2835 — a player with no minutes on that team is a 404, not a zero row. */
    public function test_player_minutes_share_outside_the_squad_is_not_found(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Smoke United' ] );
        $team_id = (int) $wpdb->insert_id;

        $req = new WP_REST_Request( 'GET', "/talenttrack/v1/teams/{$team_id}/minutes-share/99999999" );
        $res = rest_do_request( $req );

        $this->assertSame( 404, $res->get_status() );
    }

    /**
     * Assert the TalentTrack success envelope shape: `success === true`,
     * a `data` key, and an empty `errors` array.
     *
     * @param mixed $body
     */
    /**
     * #2838 — the prospect correction path. Covers the two things the
     * endpoint exists to make possible and one thing it must refuse.
     *
     * The clearing case is the point: `array_key_exists` rather than
     * `isset`, so an empty `consent_given_at` nulls the column instead of
     * being read as "not supplied". Without that, consent could be granted
     * and never withdrawn — the original bug in the opposite direction.
     */
    public function test_prospect_patch_corrects_contact_and_clears_consent(): void {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $prospect_id = ( new \TT\Modules\Prospects\Repositories\ProspectsRepository() )->create( [
            'first_name'       => 'Smoke',
            'last_name'        => 'Prospect',
            'parent_email'     => 'typo@exmaple.com',
            'consent_given_at' => '2026-01-05 00:00:00',
        ] );
        $this->assertGreaterThan( 0, $prospect_id, 'fixture prospect is created' );

        // READ.
        $read = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/prospects/' . $prospect_id ) );
        $this->assertSame( 200, $read->get_status() );
        $read_body = $read->get_data();
        $this->assertEnvelopeSuccess( $read_body );
        $this->assertArrayHasKey( 'prospect', $read_body['data'] );

        // CORRECT the mistyped email.
        $patch = new WP_REST_Request( 'PATCH', '/talenttrack/v1/prospects/' . $prospect_id );
        $patch->set_header( 'Content-Type', 'application/json' );
        $patch->set_body( wp_json_encode( [ 'parent_email' => 'parent@example.com' ] ) );
        $patch_res = rest_do_request( $patch );
        $this->assertSame( 200, $patch_res->get_status(), 'contact correction succeeds' );
        $this->assertEnvelopeSuccess( $patch_res->get_data() );

        $table = $wpdb->prefix . 'tt_prospects';
        $this->assertSame(
            'parent@example.com',
            $wpdb->get_var( $wpdb->prepare( "SELECT parent_email FROM {$table} WHERE id = %d", $prospect_id ) )
        );

        // WITHDRAW consent — an empty value must null the column.
        $clear = new WP_REST_Request( 'PATCH', '/talenttrack/v1/prospects/' . $prospect_id );
        $clear->set_header( 'Content-Type', 'application/json' );
        $clear->set_body( wp_json_encode( [ 'consent_given_at' => '' ] ) );
        $clear_res = rest_do_request( $clear );
        $this->assertSame( 200, $clear_res->get_status(), 'clearing consent succeeds' );
        $this->assertEnvelopeSuccess( $clear_res->get_data() );

        $this->assertNull(
            $wpdb->get_var( $wpdb->prepare( "SELECT consent_given_at FROM {$table} WHERE id = %d", $prospect_id ) ),
            'an empty consent value withdraws consent rather than being ignored'
        );
    }

    /**
     * A consent date the endpoint cannot read is refused, not stored as
     * null. Silently dropping a date somebody typed is the failure mode
     * this endpoint exists to end.
     */
    public function test_prospect_patch_rejects_a_malformed_consent_date(): void {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $prospect_id = ( new \TT\Modules\Prospects\Repositories\ProspectsRepository() )->create( [
            'first_name'       => 'Malformed',
            'last_name'        => 'Consent',
            'consent_given_at' => '2026-01-05 00:00:00',
        ] );

        $bad = new WP_REST_Request( 'PATCH', '/talenttrack/v1/prospects/' . $prospect_id );
        $bad->set_header( 'Content-Type', 'application/json' );
        $bad->set_body( wp_json_encode( [ 'consent_given_at' => '5 January' ] ) );
        $bad_res = rest_do_request( $bad );

        $this->assertSame( 400, $bad_res->get_status(), 'an unreadable consent date is a bad request' );

        $table = $wpdb->prefix . 'tt_prospects';
        $this->assertNotNull(
            $wpdb->get_var( $wpdb->prepare( "SELECT consent_given_at FROM {$table} WHERE id = %d", $prospect_id ) ),
            'a refused write leaves the stored consent untouched'
        );
    }

    /**
     * A prospect id that does not exist answers 404 rather than crashing or
     * reporting a phantom success.
     */
    public function test_prospect_patch_on_a_missing_row_is_not_found(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/prospects/99999' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'parent_name' => 'Nobody' ] ) );

        $this->assertSame( 404, rest_do_request( $req )->get_status() );
    }

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

    /** Create a minimal match activity in the current club and return its id. */
    private static function createActivity(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'title'             => 'Smoke-test match',
            'session_date'      => '2026-02-01',
            'activity_type_key' => 'match',
        ] );
        return (int) $wpdb->insert_id;
    }
}
