<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;

/**
 * #2282 — the activity edit form exposes only start_time ("Begintijd"), but the
 * week-plan print shows "Aftrap" from kickoff_time (which Spond seeds on import).
 * Saving a match / tournament must mirror start_time into kickoff_time so the
 * two never diverge; non-match types leave kickoff_time null.
 */
final class ActivityKickoffTimeSyncTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        // A team for the activity to hang off.
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'id' => 1, 'club_id' => 1, 'name' => 'Test team' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server; $wp_rest_server = null;
        parent::tear_down();
    }

    private function createActivity( string $type, string $start ): int {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/activities' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'title'             => 'KO ' . $type,
            'session_date'      => '2026-07-11',
            'team_id'           => 1,
            'activity_type_key' => $type,
            'start_time'        => $start,
            'end_time'          => '18:30',
        ] ) );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status(), "$type activity created" );
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_activities WHERE title = %s ORDER BY id DESC LIMIT 1",
            'KO ' . $type
        ) );
    }

    private function kickoff( int $id ): ?string {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT kickoff_time FROM {$wpdb->prefix}tt_activities WHERE id = %d", $id ) );
        return $v === null ? null : (string) $v;
    }

    public function test_tournament_mirrors_start_time_into_kickoff(): void {
        $id = $this->createActivity( 'tournament', '12:30' );
        $this->assertSame( '12:30:00', $this->kickoff( $id ) );
    }

    public function test_game_mirrors_start_time_into_kickoff(): void {
        $id = $this->createActivity( 'game', '14:00' );
        $this->assertSame( '14:00:00', $this->kickoff( $id ) );
    }

    public function test_training_leaves_kickoff_null(): void {
        $id = $this->createActivity( 'training', '18:30' );
        $this->assertNull( $this->kickoff( $id ) );
    }

    public function test_editing_start_time_updates_kickoff(): void {
        $id = $this->createActivity( 'tournament', '12:00' );
        $this->assertSame( '12:00:00', $this->kickoff( $id ) );

        // Edit the start time (as the coach does in the form) -> kickoff follows.
        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [
            'title'             => 'KO tournament',
            'session_date'      => '2026-07-11',
            'team_id'           => 1,
            'activity_type_key' => 'tournament',
            'start_time'        => '12:30',
            'end_time'          => '18:30',
        ] ) );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( '12:30:00', $this->kickoff( $id ), 'kickoff follows the edited start time' );
    }
}
