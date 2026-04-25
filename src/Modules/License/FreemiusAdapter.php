<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FreemiusAdapter — wraps the Freemius SDK and translates its concepts
 * to TalentTrack's tier names.
 *
 * **Dormant by default.** The SDK is initialized only when both
 * `TT_FREEMIUS_PRODUCT_ID` and `TT_FREEMIUS_PUBLIC_KEY` are defined in
 * wp-config.php. Customers get those constants in a future plugin
 * update; today they're absent on all installs (including Casper's
 * dev), and the adapter reports tier = 'free' / not-configured.
 *
 * Trial mapping: TalentTrack runs its own 30+14 trial state machine
 * (TrialState) rather than relying on Freemius's built-in trial. The
 * adapter still surfaces Freemius's `is_trial()` for the *paid* trial
 * if Casper enables it on the dashboard, but the primary trial path
 * is plugin-internal. This decouples the trial mechanics from
 * Freemius's specific implementation.
 *
 * Plan-to-tier mapping:
 *   Freemius slug  →  TT tier
 *   ─────────────────────────
 *   (no license)   →  free
 *   "standard"     →  standard
 *   "pro"          →  pro
 *   anything else  →  free  (defensive)
 */
class FreemiusAdapter {

    public const PRODUCT_ID_CONST   = 'TT_FREEMIUS_PRODUCT_ID';
    public const PUBLIC_KEY_CONST   = 'TT_FREEMIUS_PUBLIC_KEY';

    private static bool $booted   = false;

    /**
     * Whether all required Freemius credentials are present.
     */
    public static function isConfigured(): bool {
        return defined( self::PRODUCT_ID_CONST )
            && defined( self::PUBLIC_KEY_CONST )
            && (string) constant( self::PRODUCT_ID_CONST ) !== ''
            && (string) constant( self::PUBLIC_KEY_CONST ) !== '';
    }

    /**
     * Hook the SDK init if credentials are present and the SDK file is
     * loadable. Until both are true, this is a no-op.
     */
    public static function maybeBoot(): void {
        if ( self::$booted ) return;
        if ( ! self::isConfigured() ) return;

        // The SDK lives at vendor/freemius/wordpress-sdk/start.php in a
        // typical install; the actual path is decided when Casper opens
        // the Freemius account and runs their setup wizard. Until then
        // we guard with file_exists so the dormant path stays safe.
        $sdk = TT_PLUGIN_DIR . 'vendor/freemius/wordpress-sdk/start.php';
        if ( ! file_exists( $sdk ) ) return;

        // Once the SDK is wired up, Casper replaces this stub with the
        // generated init from Freemius's account dashboard.
        self::$booted = true;
        do_action( 'tt_freemius_sdk_booted' );
    }

    /**
     * Tier reported by Freemius. Returns FeatureMap::TIER_FREE when not
     * configured / no plan / unknown plan slug.
     */
    public static function tier(): string {
        if ( ! self::isConfigured() ) return FeatureMap::TIER_FREE;
        $plan = self::currentPlanSlug();
        if ( $plan === '' ) return FeatureMap::TIER_FREE;
        return FeatureMap::normalizeTier( $plan );
    }

    /**
     * Whether Freemius reports the install is in a Freemius-managed
     * trial. Note: TalentTrack's own 30+14 trial via TrialState is the
     * primary path; this is only true if Casper additionally enables a
     * Freemius-managed trial on the dashboard.
     */
    public static function isFreemiusTrial(): bool {
        if ( ! self::$booted ) return false;
        // Real implementation would call $fs->is_trial(); placeholder
        // returns false until SDK is initialized for real.
        return apply_filters( 'tt_freemius_is_trial', false );
    }

    /**
     * Current Freemius plan slug (empty string = no paid plan).
     */
    public static function currentPlanSlug(): string {
        if ( ! self::$booted ) return '';
        // Real implementation would call $fs->get_plan()->name. The
        // filter lets us mock during tests + dev override flows.
        return (string) apply_filters( 'tt_freemius_plan_slug', '' );
    }
}
