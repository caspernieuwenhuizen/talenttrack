<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchExecution\Repositories\TrackedEventsRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * Match-execution rebuild — the minutes-authority arbiter, the per-player
 * minute override, and the tracked development-action events.
 */
final class MatchExecutionRebuildTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8090;
    private const HALF_LENGTH  = 35;

    private int $exec_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->seedMatch();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function seedMatch(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Rebuild test match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );
        $prep_repo->replaceAvailability( $prep_id, [
            1 => [ 'status' => 'Present' ], 2 => [ 'status' => 'Present' ],
            3 => [ 'status' => 'Present' ], 4 => [ 'status' => 'Present' ],
            5 => [ 'status' => 'Present' ],
        ] );
        // Player 1 is flagged for tracking (a specific development goal).
        $prep_repo->replacePlayerGoals( $prep_id, [
            1 => [ 'attention_text' => 'Runs in behind', 'is_specific_goal' => true ],
        ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
        // Populate attendance + derived minutes from the lineup.
        $exec_repo->recomputeAttendanceAndMinutes( $this->exec_id );
    }

    private function attendanceIdFor( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_attendance WHERE activity_id = %d AND player_id = %d AND is_guest = 0 LIMIT 1",
            self::ACTIVITY_ID, $player_id
        ) );
    }

    private function rest( string $method, string $path, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( $method, $path );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    // ----- Minutes-authority arbiter -----

    public function test_manual_attendance_minutes_refused_when_execution_exists(): void {
        $att_id = $this->attendanceIdFor( 1 );
        $this->assertGreaterThan( 0, $att_id );
        $res = $this->rest( 'PATCH', '/talenttrack/v1/attendance/' . $att_id, [ 'minutes_played' => 33 ] );
        $this->assertSame( 409, $res->get_status(), 'manual minutes write is refused while an execution owns the activity' );
        $data = $res->get_data();
        $this->assertSame( 'minutes_owned_by_execution', $data['errors'][0]['code'] ?? '' );
    }

    public function test_manual_attendance_minutes_allowed_without_execution(): void {
        // A second activity with an attendance row but NO execution.
        global $wpdb;
        $other = 8091;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id' => 1, 'id' => $other, 'team_id' => 1, 'title' => 'No exec',
            'session_date' => current_time( 'Y-m-d' ), 'activity_type_key' => 'match',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id' => 1, 'activity_id' => $other, 'player_id' => 7,
            'status' => 'Present', 'record_type' => 'actual',
        ] );
        $att_id = (int) $wpdb->insert_id;
        $res = $this->rest( 'PATCH', '/talenttrack/v1/attendance/' . $att_id, [ 'minutes_played' => 33 ] );
        $this->assertSame( 200, $res->get_status(), 'manual minutes still work when no execution owns the activity' );
    }

    // ----- Per-player minute override -----

    public function test_minute_override_wins_and_survives_recompute(): void {
        $repo = new MatchExecutionRepository();
        $this->assertTrue( $repo->setMinuteOverride( self::ACTIVITY_ID, 1, 12 ) );

        $repo->recomputeAttendanceAndMinutes( $this->exec_id ); // must NOT clobber the override
        $rows = $repo->attendanceRowsByActivity( self::ACTIVITY_ID );
        $this->assertSame( 12, $rows[1]['minutes'], 'override is the effective value' );
        $this->assertSame( 12, $rows[1]['minutes_override'] );
        $this->assertSame( self::HALF_LENGTH, $rows[1]['minutes_derived'], 'derived value is preserved separately' );

        $logged = $repo->loggedMinutesByActivity( self::ACTIVITY_ID );
        $this->assertSame( 12, $logged[1], 'reads see the effective override' );

        // Clearing restores the derived value.
        $this->assertTrue( $repo->setMinuteOverride( self::ACTIVITY_ID, 1, null ) );
        $rows2 = $repo->attendanceRowsByActivity( self::ACTIVITY_ID );
        $this->assertSame( self::HALF_LENGTH, $rows2[1]['minutes'] );
        $this->assertNull( $rows2[1]['minutes_override'] );
    }

    public function test_minute_override_route_sets_and_clears(): void {
        $set = $this->rest( 'PATCH', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/minutes', [ 'player_id' => 2, 'minutes' => 20 ] );
        $this->assertSame( 200, $set->get_status() );
        $rows = ( new MatchExecutionRepository() )->attendanceRowsByActivity( self::ACTIVITY_ID );
        $this->assertSame( 20, $rows[2]['minutes_override'] );

        $clear = $this->rest( 'PATCH', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/minutes', [ 'player_id' => 2, 'minutes' => null ] );
        $this->assertSame( 200, $clear->get_status() );
        $rows2 = ( new MatchExecutionRepository() )->attendanceRowsByActivity( self::ACTIVITY_ID );
        $this->assertNull( $rows2[2]['minutes_override'] );
    }

    // ----- Tracked development-action events -----

    public function test_tracked_event_logged_for_flagged_player(): void {
        $uuid = wp_generate_uuid4();
        $res = $this->rest( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/tracked-event', [
            'event_uuid' => $uuid, 'player_id' => 1, 'half' => 1, 'minute' => 10, 'action_label' => 'Runs in behind',
        ] );
        $this->assertSame( 200, $res->get_status() );
        $counts = ( new TrackedEventsRepository() )->countsByPlayer( $this->exec_id );
        $this->assertSame( 1, $counts[1] ?? 0 );

        // Idempotent replay of the same uuid does not double-count.
        $this->rest( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/tracked-event', [
            'event_uuid' => $uuid, 'player_id' => 1, 'half' => 1, 'minute' => 10, 'action_label' => 'Runs in behind',
        ] );
        $counts2 = ( new TrackedEventsRepository() )->countsByPlayer( $this->exec_id );
        $this->assertSame( 1, $counts2[1] ?? 0 );
    }

    public function test_tracked_event_rejected_for_unflagged_player(): void {
        // Player 3 is on the pitch but not flagged for tracking.
        $res = $this->rest( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/tracked-event', [
            'event_uuid' => wp_generate_uuid4(), 'player_id' => 3, 'half' => 1, 'minute' => 10, 'action_label' => 'x',
        ] );
        $this->assertSame( 400, $res->get_status() );
        $data = $res->get_data();
        $this->assertSame( 'player_not_tracked', $data['errors'][0]['code'] ?? '' );
    }

    public function test_tracked_event_delete_reverses(): void {
        $uuid = wp_generate_uuid4();
        $this->rest( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/tracked-event', [
            'event_uuid' => $uuid, 'player_id' => 1, 'half' => 1, 'minute' => 10, 'action_label' => 'Runs in behind',
        ] );
        $del = $this->rest( 'DELETE', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/tracked-event/' . $uuid, [] );
        $this->assertSame( 200, $del->get_status() );
        $counts = ( new TrackedEventsRepository() )->countsByPlayer( $this->exec_id );
        $this->assertSame( 0, $counts[1] ?? 0 );
    }
}
