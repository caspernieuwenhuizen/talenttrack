<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Entitlement — the one place that names a concrete
 * `EntitlementSourceInterface`.
 *
 * `LicenseGate` asks this; this asks the source. Swapping how an
 * install learns its tier is a change to `source()` and nothing else.
 *
 * There is deliberately no setter and no filter here. The tier a club
 * is entitled to is not something the club's own site can be talked
 * into changing.
 */
final class Entitlement {

    /**
     * Tier this install is entitled to, or null when unknown.
     */
    public static function tier(): ?string {
        return self::source()->tier();
    }

    private static function source(): EntitlementSourceInterface {
        return new CachedEntitlement();
    }
}
