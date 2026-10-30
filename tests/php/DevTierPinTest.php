<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\CachedEntitlement;
use TT\Modules\License\DevOverride;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;

/**
 * #4301 — a developer bench pins its tier with TT_DEV_TIER.
 *
 * The bench's stored entitlement lapses to free after TTL + grace,
 * because no control plane refreshes it. The pin outlasts that, and is
 * honoured only where the developer override is available, so it can
 * never promote a customer install.
 *
 * Commercial mode, the pin and the override secret are constants, and a
 * constant defined here would stay defined for the rest of the run. The
 * tests therefore drive the two seams those constants feed:
 * `DevOverride::resolvePin()` and `LicenseGate::commercialTier()`.
 */
final class DevTierPinTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CachedEntitlement::clear();
    }

    public function tear_down(): void {
        CachedEntitlement::clear();
        parent::tear_down();
    }

    private function storeLapsedEntitlement(): void {
        CachedEntitlement::store(
            FeatureMap::TIER_PRO,
            time() - ( CachedEntitlement::TTL_SECONDS + CachedEntitlement::GRACE_SECONDS + 3600 )
        );
    }

    public function test_a_pinned_bench_stays_pro_after_its_entitlement_lapses(): void {
        $this->storeLapsedEntitlement();

        $this->assertSame(
            FeatureMap::TIER_PRO,
            LicenseGate::commercialTier( DevOverride::resolvePin( 'pro', true ) ),
            'With the secret defined, the pin answers whatever the stored record\'s age.'
        );
    }

    public function test_without_the_secret_the_pin_changes_nothing(): void {
        $this->storeLapsedEntitlement();

        $this->assertNull( DevOverride::resolvePin( 'pro', false ) );
        $this->assertSame(
            FeatureMap::TIER_FREE,
            LicenseGate::commercialTier( DevOverride::resolvePin( 'pro', false ) ),
            'A customer install never defines the secret, so a stray TT_DEV_TIER leaves it on its lapsed tier.'
        );
    }

    public function test_without_a_pin_a_live_entitlement_still_decides(): void {
        CachedEntitlement::store( FeatureMap::TIER_STANDARD );

        $this->assertSame(
            FeatureMap::TIER_STANDARD,
            LicenseGate::commercialTier( DevOverride::resolvePin( null, true ) )
        );
    }

    public function test_only_the_three_tiers_are_accepted_as_a_pin(): void {
        foreach ( FeatureMap::tiers() as $tier ) {
            $this->assertSame( $tier, DevOverride::resolvePin( $tier, true ) );
        }
        foreach ( [ 'enterprise', 'PRO', '', 1, true ] as $raw ) {
            $this->assertNull(
                DevOverride::resolvePin( $raw, true ),
                'Anything but a tier slug is ignored: ' . var_export( $raw, true )
            );
        }
    }

    public function test_an_unset_pin_reads_as_not_set_here(): void {
        $this->assertNull(
            DevOverride::pinnedTier(),
            'The test install defines neither constant.'
        );
    }
}
