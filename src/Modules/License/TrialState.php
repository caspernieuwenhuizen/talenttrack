<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TrialState — 30-day full trial → 14-day read-only grace → free.
 *
 * State stored in `tt_license_trial` (wp_options) as JSON:
 *
 *   {
 *     "started_at":  <unix>,        // when the user clicked "Start trial"
 *     "expires_at":  <unix>,        // started_at + 30 days
 *     "grace_until": <unix>,        // expires_at + 14 days
 *     "tier_during": "standard"     // tier the trial unlocks (typically Standard)
 *   }
 *
 * Three states the user can be in at any moment:
 *
 *   - **Active trial**       → now <  expires_at        → tier == tier_during, full features
 *   - **Read-only grace**    → expires_at <= now < grace_until → free tier features, banner says
 *                              "trial ended; upgrade to keep full access"
 *   - **Expired (or never)** → now >= grace_until OR no trial started → free tier
 *
 * Started-once: a user who completed a trial cannot start a second one
 * unless `reset()` is called (manual admin action / dev override).
 */
class TrialState {

    public const OPTION = 'tt_license_trial';

    public const TRIAL_LENGTH_DAYS = 30;
    public const GRACE_LENGTH_DAYS = 14;

    public const STATE_NONE   = 'none';
    public const STATE_ACTIVE = 'active';
    public const STATE_GRACE  = 'grace';
    public const STATE_EXPIRED = 'expired';

    /**
     * @return array{started_at:int, expires_at:int, grace_until:int, tier_during:string}|null
     */
    public static function read(): ?array {
        $raw = get_option( self::OPTION, '' );
        if ( ! is_string( $raw ) || $raw === '' ) return null;
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) return null;
        if ( ! isset( $data['started_at'], $data['expires_at'], $data['grace_until'] ) ) return null;
        return [
            'started_at'  => (int) $data['started_at'],
            'expires_at'  => (int) $data['expires_at'],
            'grace_until' => (int) $data['grace_until'],
            'tier_during' => isset( $data['tier_during'] ) ? (string) $data['tier_during'] : FeatureMap::TIER_STANDARD,
        ];
    }

    /**
     * Start the in-app trial.
     *
     * v3.94.1 — default tier flipped from Standard to Pro. Operators
     * who clicked "Start trial" expected every Pro feature (trial
     * cases, scout access, team chemistry) to unlock during the trial
     * window, but the previous default left them at Standard so those
     * three features stayed gated. Trials in flight keep their
     * existing `tier_during` value (read from `tt_config` on each
     * tier() call) — only NEW trials default to Pro. Caller can still
     * pass `FeatureMap::TIER_STANDARD` explicitly if a Standard-only
     * trial is wanted.
     */
    public static function start( string $tier_during = FeatureMap::TIER_PRO ): bool {
        if ( self::read() !== null ) return false; // started once already
        $now = time();
        $payload = [
            'started_at'  => $now,
            'expires_at'  => $now + self::TRIAL_LENGTH_DAYS * DAY_IN_SECONDS,
            'grace_until' => $now + ( self::TRIAL_LENGTH_DAYS + self::GRACE_LENGTH_DAYS ) * DAY_IN_SECONDS,
            'tier_during' => FeatureMap::normalizeTier( $tier_during ),
        ];
        update_option( self::OPTION, wp_json_encode( $payload ), false );
        return true;
    }

    public static function reset(): void {
        delete_option( self::OPTION );
    }

    public static function state(): string {
        $t = self::read();
        if ( ! $t ) return self::STATE_NONE;
        $now = time();
        if ( $now < $t['expires_at']  ) return self::STATE_ACTIVE;
        if ( $now < $t['grace_until'] ) return self::STATE_GRACE;
        return self::STATE_EXPIRED;
    }

    public static function isActive(): bool {
        return self::state() === self::STATE_ACTIVE;
    }

    public static function isInGrace(): bool {
        return self::state() === self::STATE_GRACE;
    }

    public static function trialDaysRemaining(): int {
        $t = self::read();
        if ( ! $t ) return 0;
        return max( 0, (int) ceil( ( $t['expires_at'] - time() ) / DAY_IN_SECONDS ) );
    }

    public static function graceDaysRemaining(): int {
        $t = self::read();
        if ( ! $t ) return 0;
        $now = time();
        if ( $now >= $t['grace_until'] ) return 0;
        if ( $now < $t['expires_at']  ) return 0; // not in grace yet
        return (int) ceil( ( $t['grace_until'] - $now ) / DAY_IN_SECONDS );
    }

    /**
     * The tier the trial unlocked while active. Empty when no trial.
     */
    public static function tierDuring(): string {
        $t = self::read();
        return $t ? $t['tier_during'] : '';
    }
}
