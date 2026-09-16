<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\CachedEntitlement;
use TT\Modules\License\FeatureMap;

/**
 * #3466 — the entitlement record, now that something writes it.
 *
 * `CachedEntitlement::store()` had no callers, so the age semantics it
 * documents had never been exercised against a record anybody wrote.
 * These pin the four answers the gate depends on:
 *
 *   1. A stored tier reads back as itself.
 *   2. A record past the TTL is still honoured — staleness means
 *      "wants refreshing", not "stop working".
 *   3. A record past TTL + grace stops being honoured, which is what a
 *      lapsed subscription looks like once the window runs out.
 *   4. Clearing leaves the unentitled state, not a stale tier.
 *
 * The grace window matters more than it looks: under-honouring costs a
 * paying club their product because our control plane was unreachable.
 */
final class CachedEntitlementTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CachedEntitlement::clear();
    }

    public function tear_down(): void {
        CachedEntitlement::clear();
        parent::tear_down();
    }

    // ── round-trip ─────────────────────────────────────────────────────

    public function test_a_stored_tier_reads_back_as_itself(): void {
        CachedEntitlement::store( FeatureMap::TIER_STANDARD );

        $this->assertSame(
            FeatureMap::TIER_STANDARD,
            ( new CachedEntitlement() )->tier(),
            'A freshly stored entitlement is the tier that was stored.'
        );
    }

    public function test_storing_records_when_the_control_plane_answered(): void {
        $when = time() - 120;
        CachedEntitlement::store( FeatureMap::TIER_PRO, $when );

        $record = CachedEntitlement::read();
        $this->assertNotNull( $record );
        $this->assertSame( FeatureMap::TIER_PRO, $record['tier'] );
        $this->assertSame( $when, $record['fetched_at'] );
    }

    public function test_an_unknown_tier_is_normalized_rather_than_stored_verbatim(): void {
        // The CLI validates before calling store(); this pins that a value
        // reaching store() anyway can never become an unknown tier on disk.
        CachedEntitlement::store( 'enterprise' );

        $this->assertSame(
            FeatureMap::TIER_FREE,
            ( new CachedEntitlement() )->tier(),
            'An unrecognised tier reads as the unentitled state, never as itself.'
        );
    }

    // ── age semantics ──────────────────────────────────────────────────

    public function test_no_record_reads_as_unentitled(): void {
        $this->assertNull(
            ( new CachedEntitlement() )->tier(),
            'Never entitled and lapsed past grace are the same answer: null.'
        );
    }

    public function test_a_record_past_its_ttl_is_still_honoured(): void {
        CachedEntitlement::store(
            FeatureMap::TIER_STANDARD,
            time() - ( CachedEntitlement::TTL_SECONDS + 3600 )
        );

        $this->assertTrue( CachedEntitlement::isStale(), 'Past the TTL the record wants refreshing.' );
        $this->assertSame(
            FeatureMap::TIER_STANDARD,
            ( new CachedEntitlement() )->tier(),
            'Wanting a refresh is not the same as having lapsed — a club does not '
            . 'lose their product because the control plane was briefly unreachable.'
        );
    }

    public function test_a_record_inside_the_grace_window_is_still_honoured(): void {
        CachedEntitlement::store(
            FeatureMap::TIER_PRO,
            time() - ( CachedEntitlement::TTL_SECONDS + CachedEntitlement::GRACE_SECONDS - 3600 )
        );

        $this->assertSame( FeatureMap::TIER_PRO, ( new CachedEntitlement() )->tier() );
    }

    public function test_a_record_past_ttl_plus_grace_stops_being_honoured(): void {
        CachedEntitlement::store(
            FeatureMap::TIER_PRO,
            time() - ( CachedEntitlement::TTL_SECONDS + CachedEntitlement::GRACE_SECONDS + 3600 )
        );

        $this->assertNull(
            ( new CachedEntitlement() )->tier(),
            'Past TTL + grace the record reads as unentitled.'
        );
    }

    // ── clearing ───────────────────────────────────────────────────────

    public function test_clearing_leaves_the_unentitled_state(): void {
        CachedEntitlement::store( FeatureMap::TIER_PRO );
        CachedEntitlement::clear();

        $this->assertNull( CachedEntitlement::read() );
        $this->assertNull( ( new CachedEntitlement() )->tier() );
    }

    // ── malformed records ──────────────────────────────────────────────

    public function test_a_malformed_record_reads_as_unentitled_rather_than_fataling(): void {
        update_option( CachedEntitlement::OPTION, 'not json at all', false );

        $this->assertNull( CachedEntitlement::read() );
        $this->assertNull( ( new CachedEntitlement() )->tier() );
    }

    public function test_a_record_missing_fetched_at_reads_as_unentitled(): void {
        update_option( CachedEntitlement::OPTION, wp_json_encode( [ 'tier' => 'pro' ] ), false );

        $this->assertNull(
            ( new CachedEntitlement() )->tier(),
            'A record with no timestamp cannot be aged, so it cannot be honoured.'
        );
    }
}
