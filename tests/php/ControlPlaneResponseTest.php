<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\AdminCenterClient\ControlPlaneResponse;
use TT\Modules\AdminCenterClient\PayloadBuilder;
use TT\Modules\AdminCenterClient\Sender;
use TT\Modules\AdminCenterClient\Signer;
use TT\Modules\License\CachedEntitlement;
use TT\Modules\License\FeatureMap;

/**
 * #3486 — the phone-home response is the entitlement refresh path.
 *
 * Driven through `Sender::sendDiagnostic()` with the HTTP call answered by
 * `pre_http_request`, so the whole path runs: build, sign, send, read the
 * response, verify, apply. Pinned: a signed entitlement is stored; no
 * `entitlement` key, a bad signature and an unknown tier all leave the
 * cached record exactly as it was; an explicit `free` is applied; and a
 * non-2xx response is not read at all.
 */
final class ControlPlaneResponseTest extends WP_UnitTestCase {

    /** @var array{code:int, body:string, signature:string}|null */
    private ?array $reply = null;

    public function set_up(): void {
        parent::set_up();
        CachedEntitlement::clear();
        delete_option( 'tt_admin_center_last_unverified' );
        add_filter( 'pre_http_request', [ $this, 'answer' ], 10, 3 );
    }

    public function tear_down(): void {
        remove_filter( 'pre_http_request', [ $this, 'answer' ], 10 );
        CachedEntitlement::clear();
        parent::tear_down();
    }

    /**
     * @param mixed               $pre
     * @param array<string,mixed> $args
     * @return mixed
     */
    public function answer( $pre, array $args, string $url ) {
        if ( $url !== Sender::endpoint() || $this->reply === null ) return $pre;
        return [
            'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( [ Sender::SIGNATURE_HEADER => $this->reply['signature'] ] ),
            'body'     => $this->reply['body'],
            'response' => [ 'code' => $this->reply['code'], 'message' => '' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    public function test_a_signed_entitlement_is_stored(): void {
        $this->replyWith( [ 'ok' => true, 'entitlement' => [ 'tier' => 'pro', 'issued_at' => time() - 60 ] ] );

        $result = Sender::sendDiagnostic( PayloadBuilder::TRIGGER_DAILY );

        $this->assertSame( ControlPlaneResponse::OUTCOME_APPLIED, $result['response'] ?? '' );
        $this->assertSame( FeatureMap::TIER_PRO, ( new CachedEntitlement() )->tier() );
    }

    public function test_no_entitlement_key_leaves_the_cache_untouched(): void {
        CachedEntitlement::store( FeatureMap::TIER_STANDARD, time() - 3600 );
        $before = get_option( CachedEntitlement::OPTION );
        $this->replyWith( [ 'ok' => true, 'broadcasts' => [] ] );

        $result = Sender::sendDiagnostic( PayloadBuilder::TRIGGER_DAILY );

        $this->assertSame( ControlPlaneResponse::OUTCOME_NO_ENTITLEMENT, $result['response'] ?? '' );
        $this->assertSame( $before, get_option( CachedEntitlement::OPTION ) );
    }

    public function test_an_unverifiable_response_is_ignored_and_logged_once(): void {
        CachedEntitlement::store( FeatureMap::TIER_STANDARD, time() - 3600 );
        $before = get_option( CachedEntitlement::OPTION );
        $this->replyWith( [ 'ok' => true, 'entitlement' => [ 'tier' => 'free', 'issued_at' => time() ] ], 'sha256=' . str_repeat( 'a', 64 ) );

        $first = Sender::sendDiagnostic( PayloadBuilder::TRIGGER_DAILY );
        $stamp = get_option( 'tt_admin_center_last_unverified' );
        Sender::sendDiagnostic( PayloadBuilder::TRIGGER_DAILY );

        $this->assertSame( ControlPlaneResponse::OUTCOME_UNVERIFIED, $first['response'] ?? '' );
        $this->assertSame( $before, get_option( CachedEntitlement::OPTION ), 'A forged revocation must not downgrade the install.' );
        $this->assertNotEmpty( $stamp );
        $this->assertSame( $stamp, get_option( 'tt_admin_center_last_unverified' ), 'The warning is throttled, not written on every ping.' );
    }

    public function test_an_unrecognised_tier_is_ignored_not_normalised_to_free(): void {
        CachedEntitlement::store( FeatureMap::TIER_PRO, time() - 3600 );
        $before = get_option( CachedEntitlement::OPTION );
        $this->replyWith( [ 'ok' => true, 'entitlement' => [ 'tier' => 'Platinum', 'issued_at' => time() ] ] );

        $result = Sender::sendDiagnostic( PayloadBuilder::TRIGGER_DAILY );

        $this->assertSame( ControlPlaneResponse::OUTCOME_MALFORMED, $result['response'] ?? '' );
        $this->assertSame( $before, get_option( CachedEntitlement::OPTION ) );
    }

    public function test_an_explicit_free_is_applied_as_a_revocation(): void {
        CachedEntitlement::store( FeatureMap::TIER_PRO, time() - 3600 );
        $this->replyWith( [ 'ok' => true, 'entitlement' => [ 'tier' => 'free', 'issued_at' => time() ] ] );

        Sender::sendDiagnostic( PayloadBuilder::TRIGGER_DAILY );

        $record = CachedEntitlement::read();
        $this->assertNotNull( $record );
        $this->assertSame( FeatureMap::TIER_FREE, $record['tier'] );
    }

    public function test_a_non_2xx_response_is_not_read(): void {
        CachedEntitlement::store( FeatureMap::TIER_STANDARD, time() - 3600 );
        $before = get_option( CachedEntitlement::OPTION );
        $this->replyWith( [ 'ok' => true, 'entitlement' => [ 'tier' => 'pro', 'issued_at' => time() ] ], null, 503 );

        $result = Sender::sendDiagnostic( PayloadBuilder::TRIGGER_DAILY );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( '', $result['response'] ?? '' );
        $this->assertSame( $before, get_option( CachedEntitlement::OPTION ) );
    }

    public function test_an_issued_at_from_a_clock_ahead_of_ours_is_clamped(): void {
        $entitlement = ControlPlaneResponse::entitlementFrom( [ 'entitlement' => [ 'tier' => 'standard', 'issued_at' => 2000000000 ] ], 1800000000 );
        $this->assertSame( [ 'tier' => 'standard', 'fetched_at' => 1800000000 ], $entitlement );
    }

    /**
     * Answer the next ping with `$body`, signed as the control plane would
     * sign it for this install unless `$signature` overrides it.
     *
     * @param array<string,mixed> $body
     */
    private function replyWith( array $body, ?string $signature = null, int $code = 200 ): void {
        $payload = PayloadBuilder::build( PayloadBuilder::TRIGGER_DAILY );
        $secret  = Signer::deriveSecret( (string) $payload['install_id'], (string) $payload['site_url'] );

        $this->reply = [
            'code'      => $code,
            'body'      => (string) wp_json_encode( $body ),
            'signature' => $signature ?? 'sha256=' . hash_hmac( 'sha256', Signer::canonicalize( $body ), $secret ),
        ];
    }
}
