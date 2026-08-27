<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ReportsRestController;

/**
 * #2893 — the team row and its drill-down have to agree about who may
 * read a team.
 *
 * The reported symptom was a row saying "12 activities, 92.9% present"
 * above a drill-down saying "No player attendance in this window." Two
 * separate defects produced it, and both are asserted here:
 *
 *   1. Render trusted `tt_edit_settings`; REST did not. An admin without
 *      a persona carrying global read on `activities` therefore saw every
 *      team listed and was blocked on every expansion.
 *   2. The blocked branch returned 200 with an empty array, which the
 *      client cannot tell from "this window really is empty" — so a
 *      permissions problem rendered as a false claim about the data.
 *
 * The second is the one worth the most care: an endpoint that answers a
 * refusal with a success is a screen that lies, and no amount of client
 * work can recover the distinction once it is gone.
 */
final class AttendanceReportScopeTest extends WP_UnitTestCase {

    /** @var int */
    private $team_id;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O14-1' ] );
        $this->team_id = (int) $wpdb->insert_id;

        ReportsRestController::register();
        do_action( 'rest_api_init' );
    }

    private function get( string $route ): \WP_REST_Response {
        $req = new WP_REST_Request( 'GET', $route );
        $req->set_param( 'team_id', $this->team_id );
        return rest_get_server()->dispatch( $req );
    }

    public function test_an_admin_holding_tt_edit_settings_is_not_blocked(): void {
        // The exact user in the report: holds tt_edit_settings, has no
        // persona carrying activities/read/global. Before the fix the
        // render listed every team for them and REST refused every one.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $response = $this->get( '/talenttrack/v1/reports/attendance' );

        $this->assertNotSame(
            403,
            $response->get_status(),
            'an admin who can see the team row was refused its drill-down'
        );
    }

    public function test_a_user_with_no_team_scope_gets_a_refusal_not_an_empty_list(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = $this->get( '/talenttrack/v1/reports/attendance' );

        // Either the capability gate (401/403) or the scope block (403) —
        // what must NOT happen is a 200 carrying an empty players array,
        // because that is indistinguishable from "no attendance".
        $this->assertNotSame( 200, $response->get_status() );
    }

    public function test_the_leaderboard_and_at_risk_readers_refuse_the_same_way(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        foreach ( [
            '/talenttrack/v1/reports/attendance-leaderboard',
            '/talenttrack/v1/reports/attendance-at-risk',
        ] as $route ) {
            $this->assertNotSame(
                200,
                $this->get( $route )->get_status(),
                "$route still answers a refusal with a success"
            );
        }
    }

    public function test_no_attendance_reader_returns_success_with_an_empty_payload_when_blocked(): void {
        // Belt and braces on the property that actually matters: whatever
        // the status, a blocked read must not hand back the empty shape
        // the client renders as "No player attendance in this window."
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = $this->get( '/talenttrack/v1/reports/attendance' );
        $data     = $response->get_data();

        if ( $response->get_status() === 200 ) {
            $this->fail( 'a blocked attendance read returned 200; the client will print "no attendance"' );
        }

        $this->assertIsArray( $data );
    }
}
