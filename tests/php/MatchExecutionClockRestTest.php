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
 * #3553 — the server keeps the match clock, so a reload resumes the match
 * where it is instead of at 00:00, paused.
 *
 *   - `pause` stamps `clock_paused_at`; `resume` folds the gap into the
 *     half's pause total, clears the stamp, and ignores a client-supplied
 *     `pause_seconds` (the previous contract);
 *   - pausing twice keeps the first stamp, resuming a running clock is a
 *     no-op — an offline replay cannot double-count;
 *   - `MatchClock` answers half / elapsed / running for every state.
 */
final class MatchExecutionClockRestTest extends WP_UnitTestCase {

    private const ACTIVITY_ID = 8353;

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

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => self::ACTIVITY_ID,
            'team_id'           => 1,
            'title'             => 'Clock match',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'match',
        ] );
        $prep_repo = new MatchPrepRepository();
        $prep_id   = $prep_repo->ensureForActivity( self::ACTIVITY_ID, 35 );
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

    private function row(): object {
        $row = ( new MatchExecutionRepository() )->findByActivity( self::ACTIVITY_ID );
        $this->assertNotNull( $row );
        return $row;
    }

    private function setUtc( string $column, int $seconds_ago ): void {
        ( new MatchExecutionRepository() )->update( $this->exec_id, [
            $column => gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ),
        ] );
    }

    public function test_pause_then_resume_measures_the_gap_server_side(): void {
        $this->assertSame( 200, $this->post( 'start-half', [ 'half' => 1 ] )->get_status() );
        $this->assertSame( 200, $this->post( 'pause', [ 'half' => 1 ] )->get_status() );
        $this->assertNotEmpty( $this->row()->clock_paused_at );

        // Pretend the pause began 60 seconds ago, then resume with a
        // client-supplied figure that must be ignored.
        $this->setUtc( 'clock_paused_at', 60 );
        $res = $this->post( 'resume', [ 'half' => 1, 'pause_seconds' => 999 ] );
        $this->assertSame( 200, $res->get_status() );

        $row = $this->row();
        $this->assertNull( $row->clock_paused_at );
        $this->assertGreaterThanOrEqual( 59, (int) $row->first_half_pause_seconds );
        $this->assertLessThanOrEqual( 65, (int) $row->first_half_pause_seconds );
    }

    public function test_pause_and_resume_are_idempotent(): void {
        $this->post( 'start-half', [ 'half' => 1 ] );
        $this->post( 'pause', [ 'half' => 1 ] );
        $this->setUtc( 'clock_paused_at', 30 );
        $first_stamp = $this->row()->clock_paused_at;

        $this->post( 'pause', [ 'half' => 1 ] );
        $this->assertSame( $first_stamp, $this->row()->clock_paused_at, 'a second pause keeps the first stamp' );

        $this->post( 'resume', [ 'half' => 1 ] );
        $after_first = (int) $this->row()->first_half_pause_seconds;
        $this->post( 'resume', [ 'half' => 1 ] );
        $this->assertSame( $after_first, (int) $this->row()->first_half_pause_seconds, 'resuming a running clock adds nothing' );
    }

    public function test_the_clock_survives_a_reload(): void {
        $this->post( 'start-half', [ 'half' => 1 ] );
        $this->setUtc( 'first_half_started_at', 600 );
        ( new MatchExecutionRepository() )->update( $this->exec_id, [ 'first_half_pause_seconds' => 60 ] );

        $running = MatchClock::forExecution( $this->row() );
        $this->assertSame( 1, $running['half'] );
        $this->assertTrue( $running['running'] );
        $this->assertEqualsWithDelta( 540, $running['elapsed_seconds'], 3 );

        // Paused two minutes ago: frozen at 600 − 120 − 60.
        $this->setUtc( 'clock_paused_at', 120 );
        $paused = MatchClock::forExecution( $this->row() );
        $this->assertFalse( $paused['running'] );
        $this->assertEqualsWithDelta( 420, $paused['elapsed_seconds'], 3 );
    }

    public function test_half_time_freezes_the_first_half_and_a_new_half_runs(): void {
        $this->post( 'start-half', [ 'half' => 1 ] );
        $this->setUtc( 'first_half_started_at', 2400 );
        $this->post( 'end-half', [ 'half' => 1 ] );
        $this->setUtc( 'first_half_ended_at', 300 );

        $ht = MatchClock::forExecution( $this->row() );
        $this->assertSame( MatchExecutionState::HALF_TIME, (string) $this->row()->state );
        $this->assertFalse( $ht['running'] );
        $this->assertEqualsWithDelta( 2100, $ht['elapsed_seconds'], 3 );

        $res  = $this->post( 'start-half', [ 'half' => 2 ] );
        $data = $res->get_data();
        $this->assertSame( 2, $data['data']['clock']['half'] ?? null );
        $this->assertTrue( $data['data']['clock']['running'] ?? false );
    }

    public function test_a_half_ended_while_paused_counts_the_pause(): void {
        $this->post( 'start-half', [ 'half' => 1 ] );
        $this->post( 'pause', [ 'half' => 1 ] );
        $this->setUtc( 'clock_paused_at', 90 );
        $this->post( 'end-half', [ 'half' => 1 ] );

        $row = $this->row();
        $this->assertNull( $row->clock_paused_at );
        $this->assertGreaterThanOrEqual( 89, (int) $row->first_half_pause_seconds );
    }
}
