<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LicenseGate — the public API every gate-checking caller goes through.
 *
 *   LicenseGate::can( 'radar_charts' )      → bool
 *   LicenseGate::tier()                     → 'free' | 'standard' | 'pro'
 *   LicenseGate::capsExceeded( 'players' )  → bool
 *
 * Resolution order for tier():
 *   1. Dev override (if TT_DEV_OVERRIDE_SECRET defined + active transient)
 *   2. Entitlement                   → what the control plane says
 *                                       this install bought
 *   3. Free                          → fallback
 *
 * The control plane owns entitlement; the install caches it. See
 * `CachedEntitlement` for the TTL and grace-window semantics that
 * keep a club working when the control plane is unreachable.
 *
 * Free-tier caps live here too — a single place to ask "is this
 * customer at their team / player limit?" The caps numbers come
 * from FreeTierCaps so they can be tuned without touching gate
 * logic.
 */
class LicenseGate {

    public static function tier(): string {
        // v3.110.44 — non-commercial test instance: short-circuit to
        // Pro so every feature is unlocked. The License module's tier
        // resolution only matters once a paying customer goes live.
        if ( ! LicenseMode::isCommercial() ) {
            return FeatureMap::TIER_PRO;
        }

        // 1. Developer override
        $override = DevOverride::active();
        if ( $override !== null ) {
            return FeatureMap::normalizeTier( $override['tier'] );
        }

        // 2. Entitlement, as last answered by the control plane
        $entitled = Entitlement::tier();
        if ( $entitled !== null ) {
            return FeatureMap::normalizeTier( $entitled );
        }

        // 3. Free fallback — no entitlement recorded, or the recorded
        //    one aged past its grace window.
        return FeatureMap::TIER_FREE;
    }

    public static function can( string $feature ): bool {
        // v3.110.44 — non-commercial test instance: every feature is
        // available regardless of FeatureMap tier-membership.
        if ( ! LicenseMode::isCommercial() ) {
            return true;
        }
        return FeatureMap::tierHas( self::tier(), $feature );
    }


    /**
     * Whether the install is at or above its free-tier cap for the
     * given resource type. Returns false on paid tiers — caps don't
     * apply there.
     *
     * @param string $cap_type 'teams' | 'players'
     */
    public static function capsExceeded( string $cap_type ): bool {
        // v3.110.44 — non-commercial test instance: caps don't apply.
        if ( ! LicenseMode::isCommercial() ) return false;

        // If the License module itself is disabled, there's no cap
        // enforcement to apply — the Account page (where the operator
        // would start a trial or enter a license key) isn't even
        // reachable, so leaving the cap active would lock them out
        // with no path back. Discovered on a pilot install:
        // operator disabled the License module via Authorization →
        // Modules; tried to add a second team; got the cap_teams
        // redirect; landed on a page that no longer exists in the
        // menu. Treat module-disabled as "operator opted out of
        // license enforcement on this install."
        if ( class_exists( '\\TT\\Core\\ModuleRegistry' )
             && ! \TT\Core\ModuleRegistry::isEnabled( '\\TT\\Modules\\License\\LicenseModule' )
        ) {
            return false;
        }
        if ( self::tier() !== FeatureMap::TIER_FREE ) return false;

        return FreeTierCaps::isAtCap( $cap_type );
    }

    /**
     * Tier currently enforced. Kept as a distinct entry point from
     * `tier()` because callers that render feature availability ask
     * this one, and a future non-payment read-only state would land
     * here rather than in `tier()`.
     */
    public static function effectiveTier(): string {
        return self::tier();
    }

    /**
     * v3.85.5 — single chokepoint for "is this feature available?" with
     * the License module's enabled state baked in. Returns true when
     * the feature should run; false means the caller must short-circuit.
     *
     * Special case: if the License module is disabled, every gate is
     * open. Same reasoning as capsExceeded — operator opted out of
     * license enforcement on this install.
     *
     * @param string $feature  FeatureMap feature key
     */
    public static function allows( string $feature ): bool {
        if ( class_exists( '\\TT\\Core\\ModuleRegistry' )
             && ! \TT\Core\ModuleRegistry::isEnabled( '\\TT\\Modules\\License\\LicenseModule' )
        ) {
            return true;
        }
        return self::can( $feature );
    }

    /**
     * REST-friendly enforcement. Returns null when allowed; returns a
     * WP_REST_Response 402 error envelope when blocked. Caller pattern:
     *
     *   $blocked = LicenseGate::enforceFeatureRest( 'trial_module' );
     *   if ( $blocked ) return $blocked;
     *
     * **402, never 403** (#3104). The two refusals are different facts
     * and a caller has to be able to tell them apart from the status
     * line alone, in a log or a support ticket:
     *
     *   - `403` — the capability model said no. This user may not do
     *     this, on any plan. Retrying after an upgrade changes nothing.
     *   - `402` — the plan said no. This user may do it; the install is
     *     not entitled to the feature. An upgrade is the fix.
     *
     * Sharing a status between them is what makes "why did this fail?"
     * unanswerable, so nothing in this class ever returns 403.
     */
    public static function enforceFeatureRest( string $feature ): ?\WP_REST_Response {
        if ( self::allows( $feature ) ) return null;
        return self::planRefusal( $feature );
    }

    /**
     * The 402 envelope itself, built without consulting entitlement.
     *
     * Public because the refusal *shape* is the thing #3104 is fixing:
     * one body, one code, one status, whoever is emitting it. Callers
     * that have already decided the answer is no — a controller with its
     * own reason to refuse, a test pinning the contract — build it here
     * rather than hand-rolling a second envelope that says nearly the
     * same thing.
     */
    public static function planRefusal( string $feature ): \WP_REST_Response {
        $tier       = self::requiredTierFor( $feature );
        $tier_label = FeatureMap::tierLabel( $tier );

        return \TT\Infrastructure\REST\RestResponse::error(
            'license_required',
            sprintf(
                /* translators: 1: feature name, 2: required plan label */
                __( '%1$s is part of the %2$s plan, which this install is not on.', 'talenttrack' ),
                FeatureMap::featureLabel( $feature ),
                $tier_label
            ),
            402,
            [ 'feature' => $feature, 'required_tier' => $tier ]
        );
    }

    /**
     * The write-verb gate (#3104).
     *
     * #3017's third decision: a record already in the database stays
     * **readable** when its feature leaves the plan. A club dropping from
     * Pro keeps reading and exporting the match analyses it wrote while
     * it was on Pro; what it loses is the ability to write new ones. So
     * the refusal belongs on the mutating verbs and on the creation entry
     * point, never on `GET`.
     *
     * That asymmetry is one rule, so it lives in one helper rather than
     * being re-derived correctly-by-inspection in every controller:
     *
     *   $blocked = LicenseGate::enforceWriteRest( 'match_analysis', $request );
     *   if ( $blocked ) return $blocked;
     *
     * A feature with no stored records (`analytics_explorer`, the grids)
     * has nothing to keep readable and locks whole — those callers use
     * `enforceFeatureRest()` directly, on every verb.
     *
     * @param string                    $feature FeatureMap feature key.
     * @param \WP_REST_Request|string   $request The request, or its method.
     */
    public static function enforceWriteRest( string $feature, $request ): ?\WP_REST_Response {
        if ( self::allows( $feature ) ) return null;

        $method = is_string( $request )
            ? $request
            : ( is_object( $request ) && method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '' );

        return self::refusalForMethod( $feature, $method );
    }

    /**
     * The asymmetry on its own: what an out-of-plan feature answers to a
     * given verb. `null` for a read, a 402 for a write.
     *
     * Split out from `enforceWriteRest()` so the rule can be stated and
     * tested without an entitlement state to arrange — the property that
     * matters ("a read of an existing record survives its feature leaving
     * the plan") is about the verb, not about which tier the install is
     * on, and it should be provable as such.
     */
    public static function refusalForMethod( string $feature, string $method ): ?\WP_REST_Response {
        return self::isWriteMethod( $method ) ? self::planRefusal( $feature ) : null;
    }

    /**
     * Whether an HTTP method mutates. Anything that is not a documented
     * safe method counts as a write: an unknown verb reaching a gated
     * controller is refused rather than waved through, because the
     * failure mode of the other default is a silent write on an
     * unentitled install.
     */
    public static function isWriteMethod( string $method ): bool {
        $method = strtoupper( trim( $method ) );
        if ( $method === '' ) return false;
        return ! in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true );
    }

    /**
     * REST cap-enforcement. Returns null when below cap; returns a
     * 402 envelope when at/over. Used by REST POST /players + /teams.
     */
    public static function enforceCapRest( string $cap_type ): ?\WP_REST_Response {
        if ( ! self::capsExceeded( $cap_type ) ) return null;
        return \TT\Infrastructure\REST\RestResponse::error(
            'license_cap_' . $cap_type,
            self::capMessage( $cap_type ),
            402,
            [ 'cap_type' => $cap_type ]
        );
    }

    /**
     * The sentence a cap refusal says, wherever it is said. Shared by the
     * REST envelope and the on-screen panel so a club reading one and
     * then the other is not told two different things.
     *
     * @param string $cap_type 'teams' | 'players'
     */
    public static function capMessage( string $cap_type ): string {
        return $cap_type === 'teams'
            ? __( 'You have reached the limit of 1 team on this plan.', 'talenttrack' )
            : __( 'You have reached the limit of 25 players on this plan.', 'talenttrack' );
    }

    /**
     * Lowest tier that has a feature on. Used to construct upgrade
     * messages that name the right plan. Falls back to Standard if
     * the feature is unknown to FeatureMap.
     */
    public static function requiredTierFor( string $feature ): string {
        foreach ( [ FeatureMap::TIER_FREE, FeatureMap::TIER_STANDARD, FeatureMap::TIER_PRO ] as $tier ) {
            if ( FeatureMap::tierHas( $tier, $feature ) ) return $tier;
        }
        return FeatureMap::TIER_STANDARD;
    }
}
