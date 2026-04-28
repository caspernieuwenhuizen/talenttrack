<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AudienceType — string-backed enum for report audience.
 *
 * Sprint 3 (#0014). Class-based pseudo-enum because the plugin's
 * minimum PHP is 7.4 (native enums are 8.1+). String constants live
 * here as the canonical form; helpers translate to/from strings.
 */
final class AudienceType {

    public const STANDARD          = 'standard';
    public const PARENT_MONTHLY    = 'parent_monthly';
    public const INTERNAL_DETAILED = 'internal_detailed';
    public const PLAYER_PERSONAL   = 'player_personal';
    public const SCOUT             = 'scout';

    public const TRIAL_ADMITTANCE          = 'trial_admittance';
    public const TRIAL_DENIAL_FINAL        = 'trial_denial_final';
    public const TRIAL_DENIAL_ENCOURAGE    = 'trial_denial_encouragement';

    /**
     * @return string[]
     */
    public static function all(): array {
        return [
            self::STANDARD,
            self::PARENT_MONTHLY,
            self::INTERNAL_DETAILED,
            self::PLAYER_PERSONAL,
            self::SCOUT,
            self::TRIAL_ADMITTANCE,
            self::TRIAL_DENIAL_FINAL,
            self::TRIAL_DENIAL_ENCOURAGE,
        ];
    }

    /**
     * Trial-letter audiences — used by #0017 to tag persisted letter
     * rows and to route renderer calls through the letter template engine
     * instead of the ratings-and-charts renderer path.
     *
     * @return string[]
     */
    public static function trialLetters(): array {
        return [
            self::TRIAL_ADMITTANCE,
            self::TRIAL_DENIAL_FINAL,
            self::TRIAL_DENIAL_ENCOURAGE,
        ];
    }

    public static function isTrialLetter( string $value ): bool {
        return in_array( $value, self::trialLetters(), true );
    }

    public static function isValid( string $value ): bool {
        return in_array( $value, self::all(), true );
    }

    public static function label( string $value ): string {
        switch ( $value ) {
            case self::STANDARD:          return __( 'Standard', 'talenttrack' );
            case self::PARENT_MONTHLY:    return __( 'Parent (monthly summary)', 'talenttrack' );
            case self::INTERNAL_DETAILED: return __( 'Internal coaches (detailed)', 'talenttrack' );
            case self::PLAYER_PERSONAL:   return __( 'Player (personal keepsake)', 'talenttrack' );
            case self::SCOUT:             return __( 'Scout', 'talenttrack' );
            case self::TRIAL_ADMITTANCE:        return __( 'Trial admittance letter', 'talenttrack' );
            case self::TRIAL_DENIAL_FINAL:      return __( 'Trial denial letter (final)', 'talenttrack' );
            case self::TRIAL_DENIAL_ENCOURAGE:  return __( 'Trial denial letter (with encouragement)', 'talenttrack' );
            default:                      return $value;
        }
    }

    public static function describe( string $value ): string {
        switch ( $value ) {
            case self::STANDARD:
                return __( 'The familiar A4 report — rate card, headline numbers, breakdown, charts. Same as before.', 'talenttrack' );
            case self::PARENT_MONTHLY:
                return __( 'Warm, plain-language summary of the past month. Strengths and one or two focus areas. No coach free-text by default.', 'talenttrack' );
            case self::INTERNAL_DETAILED:
                return __( 'Formal, data-rich report for coaches. Specific numbers, trends, all categories, all sections. Coach notes included.', 'talenttrack' );
            case self::PLAYER_PERSONAL:
                return __( "A friendly, visual keepsake for the player. Top attributes and progress, no weak-spot callouts.", 'talenttrack' );
            case self::SCOUT:
                return __( 'A privacy-aware report for an external scout. Photo and ratings included; contact details, full date of birth, and coach notes off by default.', 'talenttrack' );
            case self::TRIAL_ADMITTANCE:
                return __( 'Warm welcome letter offering a place after a successful trial. Optional acceptance slip on page 2.', 'talenttrack' );
            case self::TRIAL_DENIAL_FINAL:
                return __( 'Respectful, definitive letter declining a place after the trial.', 'talenttrack' );
            case self::TRIAL_DENIAL_ENCOURAGE:
                return __( 'Respectful denial letter that names strengths and growth areas, and invites a re-application next season.', 'talenttrack' );
            default:
                return '';
        }
    }
}
