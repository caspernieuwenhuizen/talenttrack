<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LicenseGate — the public API every gate-checking caller goes through.
 *
 *   LicenseGate::can( 'radar_charts' )      → bool
 *   LicenseGate::tier()                     → 'free' | 'standard' | 'pro'
 *   LicenseGate::isInTrial()                → bool
 *   LicenseGate::isInGrace()                → bool
 *   LicenseGate::trialDaysRemaining()       → int
 *   LicenseGate::graceDaysRemaining()       → int
 *   LicenseGate::capsExceeded( 'players' )  → bool
 *
 * Resolution order for tier():
 *   1. Dev override (if TT_DEV_OVERRIDE_SECRET defined + active transient)
 *   2. Active TalentTrack trial      → trial's tier_during
 *   3. Freemius-reported plan        → mapped tier
 *   4. Free                          → fallback
 *
 * Read-only grace state is reported separately via isInGrace(). The
 * effective tier during grace is Free (gated features hidden), but
 * the UI shows a "trial expired — upgrade to keep full access"
 * banner via the days-remaining helpers.
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

        // 2. Active TalentTrack trial
        if ( TrialState::isActive() ) {
            return FeatureMap::normalizeTier( TrialState::tierDuring() );
        }

        // 3. Freemius-reported plan
        $fs_tier = FreemiusAdapter::tier();
        if ( $fs_tier !== FeatureMap::TIER_FREE ) {
            return $fs_tier;
        }

        // 4. Free fallback
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

    public static function isInTrial(): bool {
        if ( ! LicenseMode::isCommercial() ) return false;
        return TrialState::isActive();
    }

    public static function isInGrace(): bool {
        if ( ! LicenseMode::isCommercial() ) return false;
        return TrialState::isInGrace();
    }

    public static function trialDaysRemaining(): int {
        return TrialState::trialDaysRemaining();
    }

    public static function graceDaysRemaining(): int {
        return TrialState::graceDaysRemaining();
    }

    /**
     * Whether the install is at or above its free-tier cap for the
     * given resource type. Returns false on paid tiers (caps don't
     * apply) and during active trial / grace.
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
        if ( self::isInTrial() ) return false;
        // Grace is read-only Free, so caps DO apply — you can read existing data
        // but can't add past the cap.

        return FreeTierCaps::isAtCap( $cap_type );
    }

    /**
     * Tier currently enforced AFTER applying grace-state read-only
     * downgrade. Used by gate render-paths that should hide features
     * during grace (trial-period users get Standard; grace users get
     * Free with an "upgrade to keep" banner).
     */
    public static function effectiveTier(): string {
        if ( self::isInGrace() ) return FeatureMap::TIER_FREE;
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
     */
    public static function enforceFeatureRest( string $feature ): ?\WP_REST_Response {
        if ( self::allows( $feature ) ) return null;
        $tier = self::requiredTierFor( $feature );
        $tier_label = FeatureMap::tierLabel( $tier );
        return \TT\Infrastructure\REST\RestResponse::error(
            'license_required',
            sprintf(
                /* translators: %s required tier label */
                __( 'This feature is part of the %s plan. Upgrade your TalentTrack license to enable it.', 'talenttrack' ),
                $tier_label
            ),
            402,
            [ 'feature' => $feature, 'required_tier' => $tier ]
        );
    }

    /**
     * REST cap-enforcement. Returns null when below cap; returns a
     * 402 envelope when at/over. Used by REST POST /players + /teams.
     */
    public static function enforceCapRest( string $cap_type ): ?\WP_REST_Response {
        if ( ! self::capsExceeded( $cap_type ) ) return null;
        $message = $cap_type === 'teams'
            ? __( 'You have reached the free-tier limit of 1 team. Upgrade to Standard to add more.', 'talenttrack' )
            : __( 'You have reached the free-tier limit of 25 players. Upgrade to Standard to add more.', 'talenttrack' );
        return \TT\Infrastructure\REST\RestResponse::error(
            'license_cap_' . $cap_type,
            $message,
            402,
            [ 'cap_type' => $cap_type ]
        );
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
