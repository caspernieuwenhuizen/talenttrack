<?php
namespace TT\Modules\License;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FeatureMap — tier → feature mapping with PHP defaults + Freemius
 * dashboard overrides.
 *
 * Two layers:
 *
 *   1. **DEFAULT_MAP** below — the source of truth that ships in code.
 *      Used when Freemius hasn't synced (cold start, offline, no
 *      account configured yet) and as the universal fallback.
 *      Inheritance: pro inherits standard inherits free.
 *
 *   2. **Freemius plan-features** synced into `tt_freemius_features`
 *      option by FreemiusAdapter. When present, the synced map wins —
 *      this lets Casper change tier composition from the Freemius
 *      dashboard without releasing a plugin update.
 *
 * Customers cannot edit either layer. Edits to the synced map flow
 * exclusively from the Freemius dashboard.
 *
 * Feature keys:
 *   - core_*       — always Free (basic operational features)
 *   - radar_charts, player_comparison, rate_cards, csv_import,
 *     functional_roles, partial_restore, undo_bulk    — Standard+
 *   - multi_academy, photo_session, trial_module, scout_access,
 *     team_chemistry, s3_backup                         — Pro
 */
class FeatureMap {

    public const TIER_FREE     = 'free';
    public const TIER_STANDARD = 'standard';
    public const TIER_PRO      = 'pro';

    public const SYNCED_OPTION = 'tt_freemius_features';

    /**
     * @var array<string, array<string,bool>>
     */
    public const DEFAULT_MAP = [
        // Universal baseline — every install gets these.
        self::TIER_FREE => [
            // Core CRUD that the plugin would be useless without.
            'core_evaluations'   => true,
            'core_sessions'      => true,
            'core_goals'         => true,
            'core_attendance'    => true,
            'core_player_card'   => true,
            'core_dashboard'     => true,
            // Backup baseline (#0013 Sprint 1).
            'backup_local'       => true,
            'backup_email'       => true,
            // Onboarding wizard (#0024).
            'onboarding'         => true,
            // Demo data generator (#0020) is a dev/testing feature, not gated.
            'demo_data'          => true,
            // Everything below defaults to false until a higher tier turns it on.
            'radar_charts'       => false,
            'player_comparison'  => false,
            'rate_cards_full'    => false,
            'csv_import'         => false,
            'functional_roles'   => false,
            'partial_restore'    => false,
            'undo_bulk'          => false,
            'multi_academy'      => false,
            'photo_session'      => false,
            'trial_module'       => false,
            'scout_access'       => false,
            'team_chemistry'     => false,
            's3_backup'          => false,
        ],
        // Standard adds the analytics + import + backup-undo features.
        // Inherits everything from Free.
        self::TIER_STANDARD => [
            'radar_charts'      => true,
            'player_comparison' => true,
            'rate_cards_full'   => true,
            'csv_import'        => true,
            'functional_roles'  => true,
            'partial_restore'   => true,
            'undo_bulk'         => true,
        ],
        // Pro adds future-epic features + multi-academy + heavier backup
        // destinations. Inherits everything from Standard.
        self::TIER_PRO => [
            'multi_academy'  => true,
            'photo_session'  => true,
            'trial_module'   => true,
            'scout_access'   => true,
            'team_chemistry' => true,
            's3_backup'      => true,
        ],
    ];

    /**
     * Resolve whether a tier has a feature, applying inheritance and
     * checking the Freemius-synced override before the PHP default.
     */
    public static function tierHas( string $tier, string $feature ): bool {
        $tier = self::normalizeTier( $tier );

        $synced = get_option( self::SYNCED_OPTION, '' );
        if ( is_string( $synced ) && $synced !== '' ) {
            $decoded = json_decode( $synced, true );
            if ( is_array( $decoded ) && isset( $decoded[ $tier ][ $feature ] ) ) {
                return ! empty( $decoded[ $tier ][ $feature ] );
            }
        }

        // PHP default with inheritance.
        $effective = [];
        $effective = array_merge( $effective, self::DEFAULT_MAP[ self::TIER_FREE ] ?? [] );
        if ( $tier === self::TIER_STANDARD || $tier === self::TIER_PRO ) {
            $effective = array_merge( $effective, self::DEFAULT_MAP[ self::TIER_STANDARD ] ?? [] );
        }
        if ( $tier === self::TIER_PRO ) {
            $effective = array_merge( $effective, self::DEFAULT_MAP[ self::TIER_PRO ] ?? [] );
        }
        return ! empty( $effective[ $feature ] );
    }

    /**
     * Persist a synced feature matrix from Freemius.
     *
     * @param array<string, array<string,bool>> $matrix tier => feature => enabled
     */
    public static function syncFromFreemius( array $matrix ): void {
        $clean = [];
        foreach ( [ self::TIER_FREE, self::TIER_STANDARD, self::TIER_PRO ] as $tier ) {
            $clean[ $tier ] = [];
            $row = $matrix[ $tier ] ?? [];
            if ( ! is_array( $row ) ) continue;
            foreach ( $row as $feature => $enabled ) {
                $key = preg_replace( '/[^a-z0-9_]/i', '', (string) $feature );
                if ( $key === '' ) continue;
                $clean[ $tier ][ $key ] = (bool) $enabled;
            }
        }
        update_option( self::SYNCED_OPTION, wp_json_encode( $clean ), false );
    }

    /**
     * @return string[]
     */
    public static function tiers(): array {
        return [ self::TIER_FREE, self::TIER_STANDARD, self::TIER_PRO ];
    }

    public static function tierLabel( string $tier ): string {
        switch ( self::normalizeTier( $tier ) ) {
            case self::TIER_PRO:      return __( 'Pro',      'talenttrack' );
            case self::TIER_STANDARD: return __( 'Standard', 'talenttrack' );
            default:                  return __( 'Free',     'talenttrack' );
        }
    }

    public static function normalizeTier( string $tier ): string {
        $tier = strtolower( trim( $tier ) );
        return in_array( $tier, [ self::TIER_FREE, self::TIER_STANDARD, self::TIER_PRO ], true )
            ? $tier
            : self::TIER_FREE;
    }
}
