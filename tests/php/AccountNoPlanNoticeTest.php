<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\Admin\AccountPage;
use TT\Modules\License\FeatureMap;

/**
 * #4338 — "No plan recorded" says the install runs on Free, so it must not
 * show while a pinned tier or a developer override puts it on another
 * tier. The pin is a constant, so the condition is tested through its seam.
 */
final class AccountNoPlanNoticeTest extends WP_UnitTestCase {

    public function test_with_neither_a_plan_nor_a_pin_nor_an_override_the_notice_shows(): void {
        $this->assertTrue( AccountPage::showsNoPlanNotice( null, null, null ) );
    }

    public function test_a_pinned_tier_without_a_stored_plan_hides_the_notice(): void {
        $this->assertFalse( AccountPage::showsNoPlanNotice( null, FeatureMap::TIER_PRO, null ) );
    }

    public function test_an_active_override_without_a_stored_plan_hides_the_notice(): void {
        $override = [ 'tier' => FeatureMap::TIER_STANDARD, 'set_at' => time(), 'set_by' => 1 ];
        $this->assertFalse( AccountPage::showsNoPlanNotice( null, null, $override ) );
    }

    public function test_a_stored_plan_hides_the_notice(): void {
        $this->assertFalse( AccountPage::showsNoPlanNotice( FeatureMap::TIER_STANDARD, null, null ) );
    }
}
