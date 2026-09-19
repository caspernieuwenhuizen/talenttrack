<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\MatchExecution\Domain\MatchClock;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #3667 — a half somebody started and left running.
 *
 *   - `MatchClock::limitSeconds()` is half length + 10 minutes, and
 *     `isOverrun()` is true only for a live half past it;
 *   - every end of a half is clamped to that limit: `end-half` and the
 *     second half's end in `finish`;
 *   - `end-half {at: scheduled}` ends the half at exactly its length;
 *   - a half ended inside the limit still ends "now";
 *   - the clock payload says whether the half overran and who started it.
 */
final class MatchExecutionHalfOverrunRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8367;
    private const HALF_LENGTH = 30;

    private int $exec_id = 0;
    private int $coach_id = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();

        $this->coach_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Marco Starter' ] );
        wp_set_current_user( $this->coach_id );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Overrun match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );
        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, self::HALF_LENGTH );
        $prep_repo->replaceLineupForHalf( $prep_id, 1, [ 1 => 1, 2 => 2 ] );
        $this->exec_id = ( new MatchExecutionRepository() )->ensureForActivity( self::ACTIVITY_ID, $prep_id );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function post( string $action, array $body = [] ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/' . $action );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( $body ) );
        return rest_do_request( $req );
    }

    /** @return array<string,mixed> */
    private function clock(): array {
        $res = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/match-execution/' . self::ACTIVITY_ID . '/clock' ) );
        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data();
        $this->assertIsArray( $data['data']['clock'] ?? null );
        return $data['data']['clock'];
    }

    private function row(): object {
        $row = ( new MatchExecutionRepository() )->findByActivity( self::ACTIVITY_ID );
        $this->assertNotNull( $row );
        return $row;
    }

    /** Put the execution in a live half that started `$seconds_ago`. */
    private function liveHalf( int $half, int $seconds_ago, int $pause_seconds = 0 ): int {
        $started = time() - $seconds_ago;
        $prefix  = $half === 2 ? 'second_half' : 'first_half';
        $patch   = [
            'state'                   => $half === 2 ? MatchExecutionState::SECOND_HALF : MatchExecutionState::FIRST_HALF,
            $prefix . '_started_at'   => gmdate( 'Y-m-d H:i:s', $started ),
            $prefix . '_pause_seconds' => $pause_seconds,
            'clock_paused_at'         => null,
        ];
        if ( $half === 2 ) {
            $patch['first_half_started_at'] = gmdate( 'Y-m-d H:i:s', $started - 3600 );
            $patch['first_half_ended_at']   = gmdate( 'Y-m-d H:i:s', $started - 1800 );
        }
        ( new MatchExecutionRepository() )->update( $this->exec_id, $patch );
        return $started;
    }

    public function test_the_limit_is_half_length_plus_ten_minutes(): void {
        $this->assertSame( 40 * 60, MatchClock::limitSeconds( 30 ) );
        $this->assertSame( 45 * 60, MatchClock::limitSeconds( 35 ) );
        $this->assertSame( 45 * 60, MatchClock::limitSeconds( 0 ), 'no half length falls back to 35' );
    }

    public function test_is_overrun_only_for_a_live_half_past_the_limit(): void {
        $now = time();
        $row = (object) [
            'state'                    => MatchExecutionState::FIRST_HALF,
            'first_half_started_at'    => gmdate( 'Y-m-d H:i:s', $now - 10 * 3600 ),
            'first_half_pause_seconds' => 0,
            'clock_paused_at'          => null,
        ];
        $this->assertTrue( MatchClock::isOverrun( $row, 30, $now ) );

        $row->first_half_started_at = gmdate( 'Y-m-d H:i:s', $now - 20 * 60 );
        $this->assertFalse( MatchClock::isOverrun( $row, 30, $now ) );

        // Exactly at the limit is not yet overrun.
        $row->first_half_started_at = gmdate( 'Y-m-d H:i:s', $now - 40 * 60 );
        $this->assertFalse( MatchClock::isOverrun( $row, 30, $now ) );

        // A paused clock is judged where it stopped: paused ten hours in.
        $row->first_half_started_at = gmdate( 'Y-m-d H:i:s', $now - 11 * 3600 );
        $row->clock_paused_at       = gmdate( 'Y-m-d H:i:s', $now - 3600 );
        $this->assertTrue( MatchClock::isOverrun( $row, 30, $now ) );

        // Half time is never overrun, whatever the clock.
        $row->state = MatchExecutionState::HALF_TIME;
        $this->assertFalse( MatchClock::isOverrun( $row, 30, $now ) );
    }

    public function test_end_half_on_a_ten_hour_half_is_clamped_to_the_limit(): void {
        $started = $this->liveHalf( 1, 10 * 3600, 120 );
        $this->assertSame( 200, $this->post( 'end-half', [ 'half' => 1 ] )->get_status() );

        $row = $this->row();
        $this->assertSame( MatchExecutionState::HALF_TIME, (string) $row->state );
        $this->assertSame( $started + 120 + 40 * 60, MatchClock::toUnix( $row->first_half_ended_at ) );

        $clock = MatchClock::forExecution( $row );
        $this->assertSame( 40 * 60, $clock['elapsed_seconds'], 'half time reads 40:00' );
    }

    public function test_a_paused_ten_hour_half_is_clamped_too(): void {
        $started = $this->liveHalf( 1, 10 * 3600 );
        ( new MatchExecutionRepository() )->update( $this->exec_id, [
            'clock_paused_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
        ] );
        $this->post( 'end-half', [ 'half' => 1 ] );

        $row = $this->row();
        $this->assertNull( $row->clock_paused_at );
        $this->assertSame( 40 * 60, MatchClock::forExecution( $row )['elapsed_seconds'] );
        $this->assertGreaterThan( $started + 40 * 60, (int) MatchClock::toUnix( $row->first_half_ended_at ), 'the pause sits inside the half' );
    }

    public function test_end_half_at_scheduled_length(): void {
        $started = $this->liveHalf( 1, 10 * 3600, 90 );
        $this->assertSame( 200, $this->post( 'end-half', [ 'half' => 1, 'at' => 'scheduled' ] )->get_status() );

        $row = $this->row();
        $this->assertSame( $started + 90 + 30 * 60, MatchClock::toUnix( $row->first_half_ended_at ) );
        $this->assertSame( 30 * 60, MatchClock::forExecution( $row )['elapsed_seconds'] );
    }

    public function test_an_unknown_at_value_is_refused(): void {
        $this->liveHalf( 1, 600 );
        $this->assertSame( 400, $this->post( 'end-half', [ 'half' => 1, 'at' => 'tomorrow' ] )->get_status() );
    }

    public function test_a_half_ended_inside_the_limit_still_ends_now(): void {
        $this->liveHalf( 1, 25 * 60 );
        $before = time();
        $this->post( 'end-half', [ 'half' => 1 ] );
        $ended = (int) MatchClock::toUnix( $this->row()->first_half_ended_at );

        $this->assertGreaterThanOrEqual( $before, $ended );
        $this->assertLessThanOrEqual( time(), $ended );
    }

    public function test_finish_clamps_the_second_half(): void {
        $started = $this->liveHalf( 2, 10 * 3600, 30 );
        $this->assertSame( 200, $this->post( 'finish' )->get_status() );

        $row = $this->row();
        $this->assertSame( MatchExecutionState::PENDING_REVIEW, (string) $row->state );
        $this->assertSame( $started + 30 + 40 * 60, MatchClock::toUnix( $row->second_half_ended_at ) );
    }

    public function test_finish_keeps_a_second_half_end_already_stamped(): void {
        $started = $this->liveHalf( 2, 10 * 3600 );
        $this->post( 'end-half', [ 'half' => 2, 'at' => 'scheduled' ] );
        $this->post( 'finish' );

        $this->assertSame( $started + 30 * 60, MatchClock::toUnix( $this->row()->second_half_ended_at ) );
    }

    public function test_clock_payload_flags_overrun_and_names_the_starter(): void {
        $started = $this->liveHalf( 1, 10 * 3600 );
        $clock   = $this->clock();

        $this->assertTrue( $clock['overrun'] );
        $this->assertSame( 40 * 60, $clock['limit_seconds'] );
        $this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $started ), $clock['started_at'] );
        $this->assertSame( $this->coach_id, $clock['started_by']['user_id'] ?? null );
        $this->assertSame( 'Marco Starter', $clock['started_by']['name'] ?? null );

        $this->liveHalf( 1, 20 * 60 );
        $this->assertFalse( $this->clock()['overrun'] );
    }

    public function test_the_second_half_reports_its_own_start(): void {
        $started = $this->liveHalf( 2, 5 * 60 );
        $clock   = $this->clock();

        $this->assertSame( 2, $clock['half'] );
        $this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $started ), $clock['started_at'] );
        $this->assertFalse( $clock['overrun'] );
    }
}
