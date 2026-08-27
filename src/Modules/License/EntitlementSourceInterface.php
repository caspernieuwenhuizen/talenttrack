<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EntitlementSourceInterface — answers "what tier is this install
 * entitled to?" for `LicenseGate`.
 *
 * The control plane owns the answer. An install holds a cached copy so
 * it keeps working when the control plane is unreachable, but it never
 * decides for itself — see `CachedEntitlement`.
 *
 * The interface exists so the cache can be swapped for a different
 * mechanism (a direct call, a signed token, a per-environment provider)
 * without touching `LicenseGate` or any of its callers. `Entitlement`
 * is the one place that names a concrete implementation.
 */
interface EntitlementSourceInterface {

    /**
     * Tier this install is entitled to, or null when that is unknown —
     * no entitlement has ever been recorded, or the recorded one has
     * aged out. Callers treat null as Free.
     *
     * @return string|null one of FeatureMap's tier constants
     */
    public function tier(): ?string;
}
