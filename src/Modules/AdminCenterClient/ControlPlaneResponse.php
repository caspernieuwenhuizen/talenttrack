<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\License\CachedEntitlement;
use TT\Modules\License\FeatureMap;

/**
 * ControlPlaneResponse (#3486) — what the Admin Center answers to a
 * phone-home, and what this install does with it.
 *
 * The ingest endpoint answers every accepted ping with a JSON body signed
 * the same way the request was (`X-TTAC-Signature: sha256=<hex>` over the
 * canonical JSON, with the same secret). That response is the refresh
 * path `CachedEntitlement` was waiting for: the channel already runs daily
 * and on activation and version change, so no second channel and no pull
 * endpoint on a club's WordPress are needed.
 *
 * ## An install never downgrades itself because something malformed came back
 *
 * Every failure path — a body that is not JSON, a signature that does not
 * verify, an unrecognised tier — leaves what is cached alone, to age out on
 * its own TTL and grace window. That is what those windows exist for.
 *
 * Two answers look alike and are not:
 *
 * - **no `entitlement` key** — the control plane has no opinion. Keep the
 *   cache.
 * - **`"tier": "free"`** — a deliberate revocation (a suspended club, an
 *   operator clearing the tier). Apply it.
 *
 * And an unrecognised tier is ignored, never normalised:
 * `FeatureMap::normalizeTier()` answers `free` for anything it does not
 * know, and applying that would revoke a paying club's plan over a typo at
 * the other end.
 *
 * The decode, verify and extract steps are pure so
 * `bin/admin-center-self-check.php` can exercise them without WordPress.
 */
final class ControlPlaneResponse {

    public const OUTCOME_APPLIED        = 'applied';
    public const OUTCOME_NO_ENTITLEMENT = 'no_entitlement';
    public const OUTCOME_UNVERIFIED     = 'unverified';
    public const OUTCOME_MALFORMED      = 'malformed';

    private const UNVERIFIED_THROTTLE_OPTION = 'tt_admin_center_last_unverified';

    /**
     * Handle a 2xx response. Returns what happened, for the diagnostic.
     */
    public static function handle( string $raw_body, string $signature_header, string $install_id, string $site_url ): string {
        $decoded = self::decodeVerified( $raw_body, $signature_header, Signer::deriveSecret( $install_id, $site_url ) );
        if ( $decoded === null ) {
            // An empty body is an older control plane saying nothing, not
            // an attack; only a body we could not trust is worth a log line.
            if ( trim( $raw_body ) === '' ) return self::OUTCOME_MALFORMED;
            self::logUnverifiedOnce();
            return self::OUTCOME_UNVERIFIED;
        }

        // #3493 — the release ring rides on every verified answer. A
        // malformed block is ignored and the ring already stored ages out
        // on its own; nothing here can freeze updates.
        $ring = ReleaseRing::fromResponse( $decoded );
        if ( $ring !== null ) {
            ReleaseRing::store( $ring );
        }

        // #3497 — why features went away, when it is not an ordinary plan.
        // Stored before the entitlement so a revocation and its reason land
        // on the same answer.
        $subscription = \TT\Modules\License\SubscriptionStatus::fromResponse( $decoded );
        if ( $subscription !== null ) {
            \TT\Modules\License\SubscriptionStatus::store( $subscription );
        }

        // #3499 — operator broadcasts. Replaced wholesale, so one the control
        // plane stopped sending disappears.
        $broadcasts = Broadcasts::fromResponse( $decoded );
        if ( $broadcasts !== null ) {
            Broadcasts::store( $broadcasts );
        }

        // #3501 — time-boxed operator access. Replaced wholesale for the same
        // reason broadcasts are, and here it is load-bearing rather than tidy:
        // replacing is what makes a revoked grant stop working on the next
        // ping. Merging would leave a withdrawn grant in place until it aged
        // out on its own.
        $grants = SupportGrants::fromResponse( $decoded );
        if ( $grants !== null ) {
            SupportGrants::store( $grants );
        }

        if ( ! array_key_exists( 'entitlement', $decoded ) ) {
            return self::OUTCOME_NO_ENTITLEMENT;
        }

        $entitlement = self::entitlementFrom( $decoded );
        if ( $entitlement === null ) {
            return self::OUTCOME_MALFORMED;
        }

        CachedEntitlement::store( $entitlement['tier'], $entitlement['fetched_at'] );
        return self::OUTCOME_APPLIED;
    }

    /**
     * The decoded body, or null when it is not a JSON object or its
     * signature does not verify. Fails closed.
     *
     * @return array<string,mixed>|null
     */
    public static function decodeVerified( string $raw_body, string $signature_header, string $secret ): ?array {
        if ( $raw_body === '' || $signature_header === '' ) return null;

        $decoded = json_decode( $raw_body, true );
        if ( ! is_array( $decoded ) ) return null;

        $expected = 'sha256=' . hash_hmac( 'sha256', Signer::canonicalize( $decoded ), $secret );
        if ( ! hash_equals( $expected, trim( $signature_header ) ) ) return null;

        return $decoded;
    }

    /**
     * The entitlement a verified body carries, or null when it carries none
     * this install may apply.
     *
     * `fetched_at` is when the control plane issued the answer, clamped to
     * now: a clock ahead of ours must not stretch the cache's life.
     *
     * @param array<string,mixed> $decoded
     * @return array{tier:string, fetched_at:int}|null
     */
    public static function entitlementFrom( array $decoded, ?int $now = null ): ?array {
        $block = $decoded['entitlement'] ?? null;
        if ( ! is_array( $block ) ) return null;

        $tier = is_string( $block['tier'] ?? null ) ? strtolower( trim( $block['tier'] ) ) : '';
        if ( ! in_array( $tier, FeatureMap::tiers(), true ) ) return null;

        $now       = $now ?? time();
        $issued_at = is_int( $block['issued_at'] ?? null ) ? $block['issued_at'] : 0;

        return [
            'tier'       => $tier,
            'fetched_at' => $issued_at > 0 && $issued_at <= $now ? $issued_at : $now,
        ];
    }

    private static function logUnverifiedOnce(): void {
        $last = (int) get_option( self::UNVERIFIED_THROTTLE_OPTION, 0 );
        if ( time() - $last < DAY_IN_SECONDS ) return;
        update_option( self::UNVERIFIED_THROTTLE_OPTION, time(), false );
        Logger::warning( 'admin_center.response_unverified', [] );
    }
}
