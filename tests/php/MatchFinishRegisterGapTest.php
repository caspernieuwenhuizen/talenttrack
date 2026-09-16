<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Domain\AttendanceRecomputeOutcome;
use TT\Modules\MatchExecution\Domain\MatchRegisterGap;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #3445 (epic #3442) — a match must never reach a completed state while
 * the step that records who played gave up silently.
 *
 * Two coherent end states are allowed and both are asserted here:
 *
 *   - **not completed, and the caller told why** — no match prep at all.
 *     The route refuses at the door (409 `no_prep`) and the activity is
 *     left alone, so there is no completed-but-empty record to find
 *     later.
 *   - **completed, and the gap named** — prep exists but nothing could
 *     be derived from it. The final whistle stands (a coach on a
 *     touchline cannot un-play the match), the response flags the
 *     missing register, and the gap is readable back out of the
 *     database so the screen says the same thing on reload.
 *
 * The third group pins the recompute's early returns directly: each one
 * now answers with a reason, so a future refactor cannot quietly
 * reintroduce a bare `return false`.
 */
final class MatchFinishRegisterGapTest extends WP_UnitTestCase {

    private const PREPPED_ID  = 9441; // prep + availability + line-up
    private const EMPTY_ID    = 9442; // prep row, nobody available
    private const NO_PREP_ID  = 9443; // no prep row at all
    private const HALF_LENGTH = 35;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function seedActivity( int $activity_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'             => 1,
            'id'                  => $activity_id,
            'team_id'             => 1,
            'title'               => 'Register-gap match ' . $activity_id,
            'session_date'        => current_time( 'Y-m-d' ),
            'activity_type_key'   => 'match',
            'activity_status_key' => 'planned',
        ] );
    }

    /** Prep with an availability list and a first-half XI. */
    private function seedPrepped( int $activity_id ): int {
        $this->seedActivity( $activity_id );
        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( $activity_id, self::HALF_LENGTH );
        $prep_repo->replaceAvailability( $prep_id, [
            1 => [ 'status' => 'Present' ],
            2 => [ 'status' => 'Present' ],
            3 => [ 'status' => 'Present' ],
        ] );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3 ] );
        return $prep_id;
    }

    /** Prep row, but nobody on the availability list. */
    private function seedPrepWithoutAvailability( int $activity_id ): int {
        $this->seedActivity( $activity_id );
        return ( new MatchPrepRepository() )->ensureForActivity( $activity_id, self::HALF_LENGTH );
    }

    private function finish( int $activity_id ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . $activity_id . '/finish' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [] ) );
        return rest_do_request( $req );
    }

    private function activityStatus( int $activity_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_status_key FROM {$wpdb->prefix}tt_activities WHERE id = %d AND club_id = 1",
            $activity_id
        ) );
    }

    private function countAttendance( int $activity_id, string $record_type ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND club_id = 1 AND record_type = %s",
            $activity_id,
            $record_type
        ) );
    }

    /** @return array<string,mixed> */
    private function payload( \WP_REST_Response $res ): array {
        $data = (array) $res->get_data();
        $body = $data['data'] ?? $data;
        return is_array( $body ) ? $body : [];
    }

    // -----------------------------------------------------------------
    // 1. No match prep — not completed, and the caller is told why
    // -----------------------------------------------------------------

    public function test_finish_without_prep_refuses_and_leaves_the_activity_alone(): void {
        $this->seedActivity( self::NO_PREP_ID );

        $res = $this->finish( self::NO_PREP_ID );

        $this->assertSame( 409, $res->get_status(), 'the finish is refused, not silently accepted' );
        $envelope = (array) $res->get_data();
        $this->assertSame(
            'no_prep',
            $envelope['errors'][0]['code'] ?? '',
            'the caller is told the cause by name'
        );

        // The state AFTER the route returned is what matters: an activity
        // that never completed leaves nothing reading "Completed" with an
        // empty register behind it.
        $this->assertSame( 'planned', $this->activityStatus( self::NO_PREP_ID ), 'activity is not marked completed' );
        $this->assertSame( 0, $this->countAttendance( self::NO_PREP_ID, 'actual' ), 'no register was written' );
        $this->assertNull(
            ( new MatchExecutionRepository() )->findByActivity( self::NO_PREP_ID ),
            'no execution row is left behind either'
        );
    }

    // -----------------------------------------------------------------
    // 2. Prep but nothing derivable — completed, and the gap is named
    // -----------------------------------------------------------------

    public function test_finish_with_prep_but_no_availability_completes_and_flags_the_gap(): void {
        $this->seedPrepWithoutAvailability( self::EMPTY_ID );

        $res = $this->finish( self::EMPTY_ID );
        $this->assertSame( 200, $res->get_status(), 'the final whistle is never refused once the match ran' );

        $body = $this->payload( $res );
        $this->assertFalse( $body['attendance_recorded'] ?? true, 'the response says no register was derived' );
        $this->assertSame( 0, $body['attendance_rows'] ?? -1 );

        $gap = $body['attendance_gap'] ?? [];
        $this->assertIsArray( $gap, 'the gap travels as a machine-readable block' );
        $this->assertSame( AttendanceRecomputeOutcome::REASON_NO_AVAILABILITY, $gap['reason'] ?? '' );
        $this->assertNotSame( '', (string) ( $gap['message'] ?? '' ), 'the message names the cause' );
        $this->assertNotSame( '', (string) ( $gap['fix_url'] ?? '' ), 'and links somewhere the register can be recorded' );

        // State after the route returned.
        $this->assertSame( 'completed', $this->activityStatus( self::EMPTY_ID ), 'the match still completes' );
        $this->assertSame( 0, $this->countAttendance( self::EMPTY_ID, 'actual' ), 'and has no register — which is why it is flagged' );
    }

    public function test_the_gap_is_readable_back_out_of_the_database_after_the_finish(): void {
        // The screen the coach lands on derives the notice from state, not
        // from the response, so a reload cannot lose it.
        $this->seedPrepWithoutAvailability( self::EMPTY_ID );
        $this->finish( self::EMPTY_ID );

        $gap = MatchRegisterGap::forActivity( self::EMPTY_ID );
        $this->assertNotNull( $gap, 'the gap survives the request that created it' );
        $this->assertSame( AttendanceRecomputeOutcome::REASON_NO_AVAILABILITY, $gap->reason() );
        $this->assertNotSame( '', $gap->fixUrl() );
    }

    public function test_an_expected_roster_alone_is_not_a_register(): void {
        // tt_attendance holds the plan and the record in one table. A
        // planned roster must not make a completed match look recorded.
        $this->seedPrepWithoutAvailability( self::EMPTY_ID );
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => self::EMPTY_ID,
            'player_id'   => 4,
            'status'      => 'Present',
            'record_type' => 'expected',
        ] );

        $this->finish( self::EMPTY_ID );

        $this->assertNotNull(
            MatchRegisterGap::forActivity( self::EMPTY_ID ),
            'an expected row does not close the gap'
        );
        $this->assertSame( 1, $this->countAttendance( self::EMPTY_ID, 'expected' ), 'and the plan is not swept away' );
    }

    // -----------------------------------------------------------------
    // 3. The happy path is unchanged
    // -----------------------------------------------------------------

    public function test_finish_with_prep_records_attendance_and_minutes(): void {
        $this->seedPrepped( self::PREPPED_ID );

        $res = $this->finish( self::PREPPED_ID );
        $this->assertSame( 200, $res->get_status() );

        $body = $this->payload( $res );
        $this->assertTrue( $body['attendance_recorded'] ?? false, 'the register landed' );
        $this->assertSame( 3, $body['attendance_rows'] ?? 0 );
        $this->assertArrayNotHasKey( 'attendance_gap', $body, 'nothing to flag' );
        $this->assertSame( MatchExecutionState::PENDING_REVIEW, $body['state'] ?? '' );

        // State after the route returned.
        $this->assertSame( 'completed', $this->activityStatus( self::PREPPED_ID ) );
        $this->assertSame( 3, $this->countAttendance( self::PREPPED_ID, 'actual' ), 'three actual rows' );
        $this->assertNull( MatchRegisterGap::forActivity( self::PREPPED_ID ), 'no gap' );

        global $wpdb;
        $minutes = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_played FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND player_id = 1 AND club_id = 1 AND record_type = 'actual'",
            self::PREPPED_ID
        ) );
        $this->assertSame( self::HALF_LENGTH, $minutes, 'minutes are derived from the line-up + sub log' );
    }

    public function test_derived_minutes_land_on_an_actual_row_beside_the_plan(): void {
        // #3445 — the recompute used to find the row for a player without
        // filtering record_type, so derived minutes could be written into
        // the planned (`expected`) row where no actuals reader sees them.
        $this->seedPrepped( self::PREPPED_ID );
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'     => 1,
            'activity_id' => self::PREPPED_ID,
            'player_id'   => 1,
            'status'      => 'Present',
            'record_type' => 'expected',
        ] );

        $this->finish( self::PREPPED_ID );

        $this->assertSame( 1, $this->countAttendance( self::PREPPED_ID, 'expected' ), 'the plan row is untouched' );
        $this->assertSame( 3, $this->countAttendance( self::PREPPED_ID, 'actual' ), 'and an actual row exists per available player' );

        $planned_minutes = $wpdb->get_var( $wpdb->prepare(
            "SELECT minutes_played FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND player_id = 1 AND club_id = 1 AND record_type = 'expected'",
            self::PREPPED_ID
        ) );
        $this->assertTrue(
            $planned_minutes === null || (int) $planned_minutes === 0,
            'derived minutes were not written into the plan row'
        );
    }

    // -----------------------------------------------------------------
    // 4. The recompute's bail conditions, pinned directly
    // -----------------------------------------------------------------

    public function test_recompute_names_a_missing_execution(): void {
        $outcome = ( new MatchExecutionRepository() )->recomputeAttendanceAndMinutes( 987654 );
        $this->assertFalse( $outcome->isRecorded() );
        $this->assertSame( AttendanceRecomputeOutcome::REASON_NO_EXECUTION, $outcome->reason() );
    }

    public function test_recompute_names_a_missing_prep(): void {
        // The prep row is what the availability list and the line-up hang
        // off. Losing it after the execution exists is the exact early
        // return that used to answer a bare false.
        $prep_id = $this->seedPrepped( self::PREPPED_ID );
        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( self::PREPPED_ID, $prep_id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_match_prep', [ 'id' => $prep_id ] );

        $outcome = $repo->recomputeAttendanceAndMinutes( $exec_id );
        $this->assertFalse( $outcome->isRecorded() );
        $this->assertSame( AttendanceRecomputeOutcome::REASON_NO_PREP, $outcome->reason() );
        $this->assertSame( 0, $outcome->rowsWritten() );
    }

    public function test_recompute_names_an_empty_availability_list(): void {
        $prep_id = $this->seedPrepWithoutAvailability( self::EMPTY_ID );
        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( self::EMPTY_ID, $prep_id );

        $outcome = $repo->recomputeAttendanceAndMinutes( $exec_id );
        $this->assertFalse( $outcome->isRecorded() );
        $this->assertSame( AttendanceRecomputeOutcome::REASON_NO_AVAILABILITY, $outcome->reason() );
    }

    public function test_recompute_reports_what_it_wrote(): void {
        $prep_id = $this->seedPrepped( self::PREPPED_ID );
        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( self::PREPPED_ID, $prep_id );

        $outcome = $repo->recomputeAttendanceAndMinutes( $exec_id );
        $this->assertTrue( $outcome->isRecorded() );
        $this->assertSame( AttendanceRecomputeOutcome::REASON_OK, $outcome->reason() );
        $this->assertSame( 3, $outcome->rowsWritten() );
    }
}
