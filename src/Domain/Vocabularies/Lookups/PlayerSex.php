<?php
namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerSex (#2894) — the sex a growth reference is read against.
 *
 * Deliberately NOT a `tt_lookups` vocabulary. Every other list in this
 * folder that an academy can edit is a matter of local practice; this one
 * is not. BMI-for-age, height-for-age and predicted-adult-height references
 * publish exactly two curves, and an editable list would imply the
 * reference follows whatever an operator adds to it. It does not — a third
 * value would simply have no curve to read.
 *
 * That is also why it is named for its purpose rather than as an identity
 * field. The label a coach sees says what it is used for, and the field
 * help says why it is asked. It is not the academy's record of how a young
 * person describes themselves, and should not be presented as one or
 * borrowed for that.
 *
 * `NONE` (the empty string) is a first-class value, not a gap. It is the
 * default for every record, nothing is backfilled or inferred, and a blank
 * costs the player only the age-adjusted column — raw BMI, height and
 * weight all still read normally.
 */
final class PlayerSex {

    public const MALE   = 'male';
    public const FEMALE = 'female';
    public const NONE   = '';

    /** @return list<string> the storable values, blank included */
    public static function all(): array {
        return [ self::NONE, self::MALE, self::FEMALE ];
    }

    /** @return list<string> the values that select a growth curve */
    public static function withCurve(): array {
        return [ self::MALE, self::FEMALE ];
    }

    /**
     * Storable value, or `NONE` for anything unrecognised. Never throws:
     * an unknown value on a minor's record should degrade to "not
     * recorded" rather than reject the whole save.
     */
    public static function sanitize( mixed $raw ): string {
        $value = strtolower( trim( (string) $raw ) );
        return in_array( $value, self::withCurve(), true ) ? $value : self::NONE;
    }

    public static function isValid( mixed $raw ): bool {
        return in_array( strtolower( trim( (string) $raw ) ), self::all(), true );
    }

    /** Translated label for display. Blank reads as "not recorded". */
    public static function label( string $value ): string {
        switch ( self::sanitize( $value ) ) {
            case self::MALE:
                return __( 'Male', 'talenttrack' );
            case self::FEMALE:
                return __( 'Female', 'talenttrack' );
            default:
                return __( 'Not recorded', 'talenttrack' );
        }
    }

    /**
     * value => label, for a form control. The blank option comes first and
     * is a real choice rather than a placeholder — leaving it unanswered is
     * valid everywhere, including in the wizard.
     *
     * @return array<string,string>
     */
    public static function options(): array {
        return [
            self::NONE   => __( 'Not recorded', 'talenttrack' ),
            self::MALE   => __( 'Male', 'talenttrack' ),
            self::FEMALE => __( 'Female', 'talenttrack' ),
        ];
    }
}
