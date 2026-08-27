<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\REST\ImpersonationRestController;
use TT\Modules\Authorization\Impersonation\ImpersonationLogRepository;

/**
 * #2861 — the impersonation trail can be read back.
 *
 * Impersonation lets a staff member open a minor's full record. The audit
 * trail is the entire control that makes that acceptable, so the tests
 * that matter are the ones about who may read it and whether a session
 * can become unattributable — not whether the table renders.
 */
final class ImpersonationLogReadTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$this->p}tt_impersonation_log" );

        ImpersonationRestController::init();
        do_action( 'rest_api_init' );
    }

    private function logSession( int $actor, int $target, ?string $ended = null, string $reason = 'Support request' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_impersonation_log", [
            'actor_user_id'  => $actor,
            'target_user_id' => $target,
            'club_id'        => 1,
            'started_at'     => '2026-08-20 10:00:00',
            'ended_at'       => $ended,
            'end_reason'     => $ended ? 'manual' : null,
            'actor_ip'       => '203.0.113.7',
            'reason'         => $reason,
        ] );
        return (int) $wpdb->insert_id;
    }

    public function test_a_written_session_can_be_read_back(): void {
        $actor  = self::factory()->user->create( [ 'display_name' => 'Ada Vermeer' ] );
        $target = self::factory()->user->create( [ 'display_name' => 'Jonge Speler' ] );
        $this->logSession( $actor, $target, '2026-08-20 10:12:00' );

        $rows = ( new ImpersonationLogRepository() )->recent();

        $this->assertCount( 1, $rows );
        $this->assertSame( 'Ada Vermeer', $rows[0]['actor_name'] );
        $this->assertSame( 'Jonge Speler', $rows[0]['target_name'] );
        $this->assertSame( 'Support request', $rows[0]['reason'] );
        $this->assertFalse( $rows[0]['is_active'] );
    }

    public function test_an_open_session_is_flagged_active(): void {
        $actor  = self::factory()->user->create();
        $target = self::factory()->user->create();
        $this->logSession( $actor, $target, null );

        $rows = ( new ImpersonationLogRepository() )->recent();

        $this->assertTrue( $rows[0]['is_active'], 'an unclosed session did not read as active' );
    }

    public function test_a_deleted_account_does_not_make_a_session_unattributable(): void {
        // An audit row must survive the account it names. If the display
        // name resolution silently returned '', a session could be read as
        // "someone impersonated someone".
        $actor  = self::factory()->user->create();
        $target = self::factory()->user->create();
        $this->logSession( $actor, $target, '2026-08-20 10:05:00' );

        wp_delete_user( $actor );

        $rows = ( new ImpersonationLogRepository() )->recent();
        $this->assertCount( 1, $rows );
        $this->assertNotSame( '', $rows[0]['actor_name'] );
        $this->assertStringContainsString( (string) $actor, $rows[0]['actor_name'] );
    }

    public function test_filters_narrow_the_trail(): void {
        $a = self::factory()->user->create();
        $b = self::factory()->user->create();
        $t = self::factory()->user->create();
        $this->logSession( $a, $t, '2026-08-20 10:05:00' );
        $this->logSession( $b, $t, null );

        $repo = new ImpersonationLogRepository();

        $this->assertCount( 2, $repo->recent() );
        $this->assertCount( 1, $repo->recent( [ 'actor_user_id' => $a ] ) );
        $this->assertCount( 1, $repo->recent( [ 'active_only' => true ] ) );
        $this->assertSame( 2, $repo->count() );
        $this->assertSame( 1, $repo->count( [ 'actor_user_id' => $b ] ) );
    }

    public function test_the_route_is_registered(): void {
        $this->assertArrayHasKey(
            '/talenttrack/v1/impersonation/log',
            rest_get_server()->get_routes(),
            'the route docs/impersonation.md promises is still missing'
        );
    }

    public function test_reading_the_trail_requires_permission(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/impersonation/log' )
        );

        $this->assertNotSame(
            200,
            $response->get_status(),
            'a subscriber could read who opened a minor\'s record'
        );
    }
}
