<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CachedEntitlement — the install's local copy of what the control
 * plane says this club bought.
 *
 * Stored in `tt_entitlement` (wp_options) as JSON:
 *
 *   {
 *     "tier":       "standard",
 *     "fetched_at": <unix>       // when the control plane last answered
 *   }
 *
 * **The cache is a cache, not an authority.** It is written by the
 * refresh path (which the control plane fills in — see the note at the
 * bottom) and read by `LicenseGate`. Nothing in the club's own admin
 * writes it: there is no settings field and no filter, because a club
 * admin promoting themselves to Pro by toggling a checkbox is exactly
 * what this must not allow.
 *
 * Age handling has two thresholds:
 *
 *   - **TTL (24h)** — past this the record wants refreshing. It is
 *     still honoured; `isStale()` is what a refresher asks.
 *   - **Grace (30d)** — past TTL *plus* grace the record stops being
 *     honoured and `tier()` returns null, which `LicenseGate` reads as
 *     Free.
 *
 * The grace window is deliberately generous. A club should not lose
 * features because the control plane was unreachable for a fortnight,
 * and enforcement here is not adversarial — every install is one we
 * provisioned and can reach directly. Under-honouring costs a paying
 * customer their product; over-honouring costs nothing anybody notices.
 */
final class CachedEntitlement implements EntitlementSourceInterface {

    public const OPTION = 'tt_entitlement';

    /** Seconds after which the record wants refreshing. */
    public const TTL_SECONDS = 86400;

    /** Seconds past the TTL for which a stale record is still honoured. */
    public const GRACE_SECONDS = 2592000;

    public function tier(): ?string {
        $record = self::read();
        if ( $record === null ) return null;

        $age = time() - $record['fetched_at'];
        if ( $age > self::TTL_SECONDS + self::GRACE_SECONDS ) return null;

        return $record['tier'];
    }

    /**
     * Whether the record is old enough to want refreshing. Still
     * honoured until the grace window also runs out.
     */
    public static function isStale(): bool {
        $record = self::read();
        if ( $record === null ) return true;
        return ( time() - $record['fetched_at'] ) > self::TTL_SECONDS;
    }

    /**
     * @return array{tier:string, fetched_at:int}|null
     */
    public static function read(): ?array {
        $raw = get_option( self::OPTION, '' );
        if ( ! is_string( $raw ) || $raw === '' ) return null;

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) return null;
        if ( ! isset( $data['tier'], $data['fetched_at'] ) ) return null;

        return [
            'tier'       => FeatureMap::normalizeTier( (string) $data['tier'] ),
            'fetched_at' => (int) $data['fetched_at'],
        ];
    }

    /**
     * Record what the control plane answered.
     *
     * Called by provisioning when an install is stood up, and by the
     * refresh path once that exists. Not called from anywhere a club
     * admin can reach.
     */
    public static function store( string $tier, ?int $fetched_at = null ): void {
        update_option(
            self::OPTION,
            wp_json_encode( [
                'tier'       => FeatureMap::normalizeTier( $tier ),
                'fetched_at' => $fetched_at !== null ? $fetched_at : time(),
            ] ),
            false
        );
    }

    public static function clear(): void {
        delete_option( self::OPTION );
    }
}
