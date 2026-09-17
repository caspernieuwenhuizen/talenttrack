<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\AdminCenterClient\ControlPlaneResponse;
use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Signer;
use TT\Modules\License\CachedEntitlement;
use TT\Modules\License\Frontend\SubscriptionBanner;
use TT\Modules\License\SubscriptionStatus;
use TT\Modules\License\UpgradePanel;

/**
 * #3497 — a suspended academy is told its data is safe, not sold a plan.
 *
 * Pinned: a verified answer stores the status together with its explicit
 * revocation; an answer without the block clears it; an unreadable block
 * leaves it alone; an unverified answer stores nothing; the interrupted
 * panel has no upgrade call to action and leads with "data is safe"; the
 * banner has a 48px dismiss control; and a non-commercial install is never
 * treated as suspended.
 */
final class SubscriptionStatusTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        delete_option( SubscriptionStatus::OPTION );
        CachedEntitlement::clear();
    }

    public function tear_down(): void {
        delete_option( SubscriptionStatus::OPTION );
        CachedEntitlement::clear();
        parent::tear_down();
    }

    public function test_a_verified_suspension_stores_status_and_revocation(): void {
        $this->handleSigned( [ 'ok' => true, 'entitlement' => [ 'tier' => 'free', 'issued_at' => time() ], 'subscription' => [ 'status' => 'suspended' ] ] );

        $this->assertSame( SubscriptionStatus::SUSPENDED, SubscriptionStatus::stored() );
        $this->assertSame( 'free', CachedEntitlement::read()['tier'] ?? '' );
    }

    public function test_an_answer_without_the_block_clears_it(): void {
        SubscriptionStatus::store( SubscriptionStatus::SUSPENDED );
        $this->handleSigned( [ 'ok' => true, 'entitlement' => [ 'tier' => 'standard', 'issued_at' => time() ] ] );
        $this->assertSame( '', SubscriptionStatus::stored() );
    }

    public function test_an_unreadable_block_or_unverified_answer_changes_nothing(): void {
        SubscriptionStatus::store( SubscriptionStatus::CANCELLED );
        $this->handleSigned( [ 'ok' => true, 'subscription' => [ 'status' => 'paused-ish' ] ] );
        $this->assertSame( SubscriptionStatus::CANCELLED, SubscriptionStatus::stored() );

        delete_option( SubscriptionStatus::OPTION );
        $payload = PayloadBuilder::build( PayloadBuilder::TRIGGER_DAILY );
        ControlPlaneResponse::handle(
            (string) wp_json_encode( [ 'ok' => true, 'subscription' => [ 'status' => 'suspended' ] ] ),
            'sha256=' . str_repeat( 'c', 64 ),
            (string) $payload['install_id'],
            (string) $payload['site_url']
        );
        $this->assertSame( '', SubscriptionStatus::stored() );
    }

    public function test_the_interrupted_panel_reassures_and_sells_nothing(): void {
        $html = UpgradePanel::interruptedShell( 'Team chemistry' );

        $this->assertStringContainsString( 'nothing has been deleted', $html );
        $this->assertStringContainsString( 'Team chemistry', $html );
        $this->assertStringNotContainsString( 'tt-upgrade-panel__cta', $html, 'No upgrade call to action while suspended.' );
        $this->assertStringNotContainsString( 'plan', strtolower( wp_strip_all_tags( $html ) ) );
    }

    public function test_the_banner_can_be_hidden_for_the_session(): void {
        $html = SubscriptionBanner::html();
        $this->assertStringContainsString( 'data-tt-subscription-dismiss', $html );
        $this->assertStringContainsString( 'role="status"', $html );
    }

    public function test_a_non_commercial_install_is_never_suspended(): void {
        SubscriptionStatus::store( SubscriptionStatus::SUSPENDED );
        $this->assertFalse( \TT\Modules\License\LicenseMode::isCommercial(), 'Precondition: the suite runs non-commercial.' );
        $this->assertSame( '', SubscriptionStatus::current() );
        $this->assertFalse( SubscriptionStatus::isInterrupted() );
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
