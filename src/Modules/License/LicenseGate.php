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
        return FeatureMap::tierHas( self::tier(), $feature );
    }

    public static function isInTrial(): bool {
        return TrialState::isActive();
    }

    public static function isInGrace(): bool {
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
}
