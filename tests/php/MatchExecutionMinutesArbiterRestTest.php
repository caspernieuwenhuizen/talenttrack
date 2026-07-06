<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * Match-execution rebuild — minutes-authority arbiter (Part A1).
 *
 * "Presence of an execution row" is the single authority on a match's minutes.
 * When an execution owns an activity, the raw manual attendance-minutes path
 * (PATCH /attendance/{id}) must refuse with 409 and route the coach to the
 * per-player override endpoint (PATCH /match-execution/{activity}/minutes). A
 * match with no execution (never prepped/run) keeps manual entry unchanged.
 * The override lives in a separate column so it survives the sub-log recompute,
 * and the effective figure is COALESCE(minutes_override, minutes_played).
 */
final class MatchExecutionMinutesArbiterRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8281; // prepped → has an execution row
    private const NO_EXEC_ID  = 8282; // no prep/execution → manual minutes still work
    private const HALF_LENGTH = 35;

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

        $this->seedPreppedMatch();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /**
     * Prepped activity: availability + first-half XI = players 1..5, a
     * PENDING_REVIEW execution, and a recompute so the roster attendance rows
     * exist (each starter played the full half → 35').
     */
    private function seedPreppedMatch(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Arbiter match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );

        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceAvailability( $prep_id, [
            1 => [ 'status' => 'Present' ],
            2 => [ 'status' => 'Present' ],
            3 => [ 'status' => 'Present' ],
            4 => [ 'status' => 'Present' ],
            5 => [ 'status' => 'Present' ],
        ] );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5 ] );

        $exec_repo     = new MatchExecutionRepository();
        $this->exec_id = $exec_repo->ensureForActivity( self::ACTIVITY_ID, $prep_id );
        $exec_repo->update( $this->exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
        $exec_repo->recomputeAttendanceAndMinutes( $this->exec_id );
    }

    private function attendanceIdFor( int $activity_id, int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d AND player_id = %d AND club_id = 1 LIMIT 1",
            $activity_id, $player_id
        ) );
    }

    private function patchAttendance( int $attendance_id, array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/attendance/' . $attendance_id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    private function patchMinutesOverride( array $body ): \WP_REST_Response {
        $req = new WP_REST_Request( 'PATCH', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/minutes' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    public function test_minutes_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $key    = '/talenttrack/v1/match-execution/(?P<activity_id>\d+)/minutes';
        $this->assertArrayHasKey( $key, $routes );
        $methods = [];
        foreach ( $routes[ $key ] as $endpoint ) {
            $methods = array_merge( $methods, array_keys( (array) $endpoint['methods'] ) );
        }
        $this->assertContains( 'PATCH', $methods, 'the minutes override route exposes PATCH' );
    }

    public function test_manual_minutes_patch_refused_when_execution_owns_activity(): void {
        $att_id = $this->attendanceIdFor( self::ACTIVITY_ID, 1 );
        $this->assertGreaterThan( 0, $att_id, 'recompute created the roster attendance row' );

        $res = $this->patchAttendance( $att_id, [ 'minutes_played' => 20 ] );
        $this->assertSame( 409, $res->get_status(), 'manual minutes edit is refused' );
        $this->assertSame( 'minutes_owned_by_execution', $res->get_data()['errors'][0]['code'] ?? '' );

        // The raw minutes_played value is untouched by the refused write.
        $rows = ( new MatchExecutionRepository() )->attendanceRowsByActivity( self::ACTIVITY_ID );
        $this->assertSame( self::HALF_LENGTH, $rows[1]['minutes_derived'] );
    }

    public function test_non_minutes_attendance_edits_still_pass_with_execution(): void {
        // The arbiter gates ONLY minutes; status/notes still write through.
        $att_id = $this->attendanceIdFor( self::ACTIVITY_ID, 2 );
        $res    = $this->patchAttendance( $att_id, [ 'status' => 'Absent' ] );
        $this->assertSame( 200, $res->get_status() );
    }

    public function test_manual_minutes_patch_allowed_without_execution(): void {
        // A no-prep activity has no execution row → manual minutes unchanged.
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::NO_EXEC_ID,
            'team_id'           => 1,
            'title'             => 'No-exec match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'        => 1,
            'activity_id'    => self::NO_EXEC_ID,
            'player_id'      => 7,
            'status'         => 'Present',
            'minutes_played' => 10,
        ] );
        $att_id = $this->attendanceIdFor( self::NO_EXEC_ID, 7 );

        $res = $this->patchAttendance( $att_id, [ 'minutes_played' => 42 ] );
        $this->assertSame( 200, $res->get_status(), 'manual minutes still work without an execution' );
    }

    public function test_override_sets_effective_minutes(): void {
        $res = $this->patchMinutesOverride( [ 'player_id' => 1, 'minutes' => 12 ] );
        $this->assertSame( 200, $res->get_status() );

        $repo = new MatchExecutionRepository();
        $this->assertSame( 12, $repo->loggedMinutesByActivity( self::ACTIVITY_ID )[1], 'effective = override' );

        $row = $repo->attendanceRowsByActivity( self::ACTIVITY_ID )[1];
        $this->assertSame( 12, $row['minutes'],          'effective minutes = override' );
        $this->assertSame( 12, $row['minutes_override'], 'override stored' );
        $this->assertSame( self::HALF_LENGTH, $row['minutes_derived'], 'derived minutes untouched' );
    }

    public function test_override_clears_with_null(): void {
        $repo = new MatchExecutionRepository();
        $this->patchMinutesOverride( [ 'player_id' => 1, 'minutes' => 12 ] );
        $res = $this->patchMinutesOverride( [ 'player_id' => 1, 'minutes' => null ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $repo->attendanceRowsByActivity( self::ACTIVITY_ID )[1];
        $this->assertNull( $row['minutes_override'], 'override cleared' );
        $this->assertSame( self::HALF_LENGTH, $row['minutes'], 'effective falls back to derived' );
    }

    public function test_override_survives_recompute(): void {
        $repo = new MatchExecutionRepository();
        $this->patchMinutesOverride( [ 'player_id' => 1, 'minutes' => 12 ] );

        // A later post-match edit re-derives minutes_played; the override,
        // living in its own column, must survive.
        $repo->recomputeAttendanceAndMinutes( $this->exec_id );

        $row = $repo->attendanceRowsByActivity( self::ACTIVITY_ID )[1];
        $this->assertSame( 12, $row['minutes_override'], 'override untouched by recompute' );
        $this->assertSame( 12, $row['minutes'], 'effective still the override after recompute' );
        $this->assertSame( self::HALF_LENGTH, $row['minutes_derived'], 'derived recomputed to full half' );
    }

    public function test_override_allowed_once_finalized(): void {
        // Finalizing locks the match EVENTS (goals, subs, tracked actions),
        // but recorded minutes stay correctable — this is the #2224
        // capability the override replaces. The override is a separate
        // column that can't corrupt the event record, so no re-open is
        // needed to fix an obviously-wrong recorded figure.
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'state' => MatchExecutionState::FINALIZED ] );
        $res = $this->patchMinutesOverride( [ 'player_id' => 1, 'minutes' => 12 ] );
        $this->assertSame( 200, $res->get_status(), 'recorded minutes stay correctable once finalized' );
        $row = ( new MatchExecutionRepository() )->attendanceRowsByActivity( self::ACTIVITY_ID )[1];
        $this->assertSame( 12, $row['minutes_override'] );
    }

    public function test_override_no_roster_row_returns_409(): void {
        // Player 99 has no attendance row → nothing to override.
        $res = $this->patchMinutesOverride( [ 'player_id' => 99, 'minutes' => 12 ] );
        $this->assertSame( 409, $res->get_status() );
        $this->assertSame( 'no_attendance_row', $res->get_data()['errors'][0]['code'] ?? '' );
    }
}
