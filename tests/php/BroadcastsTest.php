<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\AdminCenterClient\BroadcastBanner;
use TT\Modules\AdminCenterClient\Broadcasts;
use TT\Modules\AdminCenterClient\ControlPlaneResponse;
use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Signer;

/**
 * #3499 — operator broadcasts from the ping response, shown in the product.
 *
 * Pinned: a verified answer replaces the cache and an empty array clears it;
 * a broadcast past `ends_at` is not shown even with no fresh answer;
 * dismissal is per user and keyed on id, so a later broadcast still shows;
 * `dismissable: false` has no dismiss control and cannot be dismissed; the
 * body renders as text, never markup; and the REST routes answer for the
 * caller only.
 */
final class BroadcastsTest extends WP_UnitTestCase {

    private int $user = 0;

    public function set_up(): void {
        parent::set_up();
        delete_option( Broadcasts::OPTION );
        $this->user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $this->user );
    }

    public function tear_down(): void {
        delete_option( Broadcasts::OPTION );
        parent::tear_down();
    }

    public function test_a_verified_answer_replaces_the_cache_and_an_empty_one_clears_it(): void {
        $this->handleSigned( [ 'ok' => true, 'broadcasts' => [ $this->broadcast( 7 ), $this->broadcast( 8 ) ] ] );
        $this->assertSame( [ 7, 8 ], $this->ids( Broadcasts::active() ) );

        $this->handleSigned( [ 'ok' => true, 'broadcasts' => [ $this->broadcast( 9 ) ] ] );
        $this->assertSame( [ 9 ], $this->ids( Broadcasts::active() ), 'Replaced, not merged.' );

        $this->handleSigned( [ 'ok' => true, 'broadcasts' => [] ] );
        $this->assertSame( [], Broadcasts::active() );
    }

    public function test_an_ended_broadcast_is_not_shown_without_a_fresh_answer(): void {
        Broadcasts::store( [
            $this->broadcast( 1, [ 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] ),
            $this->broadcast( 2, [ 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] ),
        ] );
        $this->assertSame( [ 2 ], $this->ids( Broadcasts::forUser( $this->user ) ) );
    }

    public function test_dismissal_is_per_user_and_keyed_on_id(): void {
        Broadcasts::store( [ $this->broadcast( 7 ) ] );
        $other = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertTrue( Broadcasts::dismiss( $this->user, 7 ) );
        $this->assertSame( [], Broadcasts::forUser( $this->user ) );
        $this->assertSame( [ 7 ], $this->ids( Broadcasts::forUser( $other ) ), 'Another user still sees it.' );

        Broadcasts::store( [ $this->broadcast( 7 ), $this->broadcast( 8 ) ] );
        $this->assertSame( [ 8 ], $this->ids( Broadcasts::forUser( $this->user ) ), 'A new broadcast is never hidden by an earlier dismissal.' );
    }

    public function test_an_undismissable_broadcast_has_no_control_and_stays(): void {
        Broadcasts::store( [ $this->broadcast( 5, [ 'dismissable' => false ] ) ] );

        $this->assertFalse( Broadcasts::dismiss( $this->user, 5 ) );
        $html = BroadcastBanner::html( Broadcasts::forUser( $this->user ) );
        $this->assertStringNotContainsString( 'tt-broadcast__dismiss', $html );
        $this->assertSame( [ 5 ], $this->ids( Broadcasts::forUser( $this->user ) ) );
    }

    public function test_the_body_renders_as_text(): void {
        Broadcasts::store( [ $this->broadcast( 3, [ 'body' => '<script>alert(1)</script><b>Bold</b>' ] ) ] );

        $html = BroadcastBanner::html( Broadcasts::forUser( $this->user ) );
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '<b>', $html );
        $this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt;', $html );
    }

    public function test_warning_and_info_render_distinctly(): void {
        $html = BroadcastBanner::html( [ $this->broadcast( 1, [ 'severity' => 'warning' ] ), $this->broadcast( 2 ) ] );
        $this->assertStringContainsString( 'tt-broadcast--warning', $html );
        $this->assertStringContainsString( 'tt-broadcast--info', $html );
    }

    public function test_rest_lists_and_dismisses_for_the_caller(): void {
        Broadcasts::store( [ $this->broadcast( 11 ) ] );

        $list = rest_do_request( new \WP_REST_Request( 'GET', '/talenttrack/v1/me/broadcasts' ) );
        $this->assertSame( 200, $list->get_status() );
        $this->assertSame( [ 11 ], $this->ids( $list->get_data()['data']['broadcasts'] ?? [] ) );

        $dismiss = rest_do_request( new \WP_REST_Request( 'POST', '/talenttrack/v1/me/broadcasts/11/dismiss' ) );
        $this->assertSame( 200, $dismiss->get_status() );
        $this->assertSame( [], Broadcasts::forUser( $this->user ) );

        $missing = rest_do_request( new \WP_REST_Request( 'POST', '/talenttrack/v1/me/broadcasts/999/dismiss' ) );
        $this->assertSame( 404, $missing->get_status() );

        wp_set_current_user( 0 );
        $anon = rest_do_request( new \WP_REST_Request( 'GET', '/talenttrack/v1/me/broadcasts' ) );
        $this->assertContains( $anon->get_status(), [ 401, 403 ] );
    }

    /**
     * @param array<string,mixed> $over
     * @return array{id:int, body:string, severity:string, dismissable:bool, ends_at:string}
     */
    private function broadcast( int $id, array $over = [] ): array {
        return array_merge( [
            'id'          => $id,
            'body'        => 'Maintenance on Sunday 20:00–22:00.',
            'severity'    => 'info',
            'dismissable' => true,
            'ends_at'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
        ], $over );
    }

    /**
     * @param array<int,array<string,mixed>> $list
     * @return list<int>
     */
    private function ids( array $list ): array {
        return array_values( array_map( static fn( array $b ): int => (int) $b['id'], $list ) );
    }

    /** @param array<string,mixed> $body */
    private function handleSigned( array $body ): void {
        $payload = PayloadBuilder::build( PayloadBuilder::TRIGGER_DAILY );
        $secret  = Signer::deriveSecret( (string) $payload['install_id'], (string) $payload['site_url'] );
        ControlPlaneResponse::handle(
            (string) wp_json_encode( $body ),
            'sha256=' . hash_hmac( 'sha256', Signer::canonicalize( $body ), $secret ),
            (string) $payload['install_id'],
            (string) $payload['site_url']
        );
    }
}
