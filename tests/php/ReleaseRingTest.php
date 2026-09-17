<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\UpdateHardening;
use TT\Modules\AdminCenterClient\ControlPlaneResponse;
use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\ReleaseRing;
use TT\Modules\AdminCenterClient\Signer;

/**
 * #3493 — the release ring holds update offers to a version ceiling.
 *
 * Pinned: a verified response stores the ring; an offer above the ceiling is
 * dropped from the update transient and never force-auto-updated; an offer
 * at or below it is kept; `max_version: null` offers everything; and every
 * way of not knowing — no record, a stale record, a malformed block — offers
 * updates exactly as before. Nothing may freeze updates.
 */
final class ReleaseRingTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        delete_option( ReleaseRing::OPTION );
    }

    public function tear_down(): void {
        delete_option( ReleaseRing::OPTION );
        parent::tear_down();
    }

    public function test_a_verified_response_stores_the_ring(): void {
        $this->handleSigned( [ 'ok' => true, 'update_ring' => [ 'ring' => 'stable', 'max_version' => '4.123.0' ] ] );

        $record = ReleaseRing::read();
        $this->assertNotNull( $record );
        $this->assertSame( 'stable', $record['ring'] );
        $this->assertSame( '4.123.0', $record['max_version'] );
    }

    public function test_an_unverified_response_stores_nothing(): void {
        $payload = PayloadBuilder::build( PayloadBuilder::TRIGGER_DAILY );
        ControlPlaneResponse::handle(
            (string) wp_json_encode( [ 'ok' => true, 'update_ring' => [ 'ring' => 'stable', 'max_version' => '1.0.0' ] ] ),
            'sha256=' . str_repeat( 'b', 64 ),
            (string) $payload['install_id'],
            (string) $payload['site_url']
        );
        $this->assertNull( ReleaseRing::read() );
    }

    public function test_an_offer_above_the_ceiling_is_dropped_and_not_auto_installed(): void {
        $this->storeRing( '4.123.0' );

        $filtered = ReleaseRing::filterUpdateTransient( $this->transientOffering( '4.124.0' ) );
        $this->assertArrayNotHasKey( plugin_basename( TT_PLUGIN_FILE ), $filtered->response );

        $item = (object) [ 'plugin' => plugin_basename( TT_PLUGIN_FILE ), 'new_version' => '4.124.0' ];
        $this->assertFalse( UpdateHardening::forceAutoUpdate( null, $item ), 'The ceiling runs before the auto-update decision.' );
    }

    public function test_an_offer_at_or_below_the_ceiling_is_kept(): void {
        $this->storeRing( '4.123.0' );

        foreach ( [ '4.123.0', '4.122.1' ] as $version ) {
            $filtered = ReleaseRing::filterUpdateTransient( $this->transientOffering( $version ) );
            $this->assertArrayHasKey( plugin_basename( TT_PLUGIN_FILE ), $filtered->response, $version );
        }
        $item = (object) [ 'plugin' => plugin_basename( TT_PLUGIN_FILE ), 'new_version' => '4.123.0' ];
        $this->assertTrue( UpdateHardening::forceAutoUpdate( null, $item ) );
    }

    public function test_no_ceiling_offers_everything(): void {
        ReleaseRing::store( [ 'ring' => 'canary', 'max_version' => null, 'fetched_at' => time() ] );
        $this->assertTrue( ReleaseRing::allows( '99.0.0' ) );
    }

    public function test_every_way_of_not_knowing_degrades_open(): void {
        $this->assertTrue( ReleaseRing::allows( '99.0.0' ), 'No record: offer as today.' );

        ReleaseRing::store( [ 'ring' => 'stable', 'max_version' => '1.0.0', 'fetched_at' => time() - ReleaseRing::STALE_SECONDS - 60 ] );
        $this->assertTrue( ReleaseRing::allows( '99.0.0' ), 'A stale record must not freeze updates.' );

        $this->assertNull( ReleaseRing::fromResponse( [ 'update_ring' => [ 'ring' => 'stable', 'max_version' => 'latest' ] ] ), 'A malformed ceiling is ignored, not applied.' );
        $this->assertNull( ReleaseRing::fromResponse( [ 'ok' => true ] ) );
    }

    private function storeRing( string $max ): void {
        ReleaseRing::store( [ 'ring' => 'stable', 'max_version' => $max, 'fetched_at' => time() ] );
    }

    private function transientOffering( string $version ): object {
        return (object) [
            'response' => [
                plugin_basename( TT_PLUGIN_FILE ) => (object) [ 'slug' => 'talenttrack', 'new_version' => $version ],
            ],
        ];
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
