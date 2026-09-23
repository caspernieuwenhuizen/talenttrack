<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoAnthropometry (#4036) — one body model for a generated academy.
 *
 * Height and weight were decided twice. `PlayerGenerator` wrote the player
 * record from 110 cm at six plus 6 cm a year with a BMI-for-age weight;
 * `MeasurementGenerator` wrote the Height and Weight test battery from a
 * straight line anchored on a twelve-year-old. The two never agreed, so a
 * U7 player's profile said 114 cm and 24 kg while their measurement history
 * said 117.5 cm and 15 kg, and the target band for their age group excluded
 * the record the same run had just written.
 *
 * A player's stated height is not a matter of opinion, so it is not a matter
 * for two models. The typical values here are what the bands and the
 * progression series are derived from; the `*ForAge()` helpers add the
 * per-player scatter the player record carries.
 */
final class DemoAnthropometry {

    /** Youngest and oldest age the curve is defined for. */
    private const MIN_AGE = 4;
    private const MAX_AGE = 21;

    /**
     * Typical standing height in cm at an age, with no scatter — the value a
     * target band is centred on.
     *
     * Deliberately simple: 110 cm at six, 6 cm a year to fifteen, then 2 cm a
     * year. It is a demo curve, not a growth reference.
     */
    public static function typicalHeight( int $age ): float {
        $age = self::clampAge( $age );

        return $age <= 15
            ? 110.0 + ( ( $age - 6 ) * 6.0 )
            : 164.0 + ( ( $age - 15 ) * 2.0 );
    }

    /** One player's height in cm — the typical value plus their own scatter. */
    public static function heightForAge( int $age ): int {
        return max( 110, (int) round( self::typicalHeight( $age ) ) + mt_rand( -4, 6 ) );
    }

    /** The BMI the weight model uses at an age. */
    public static function bmiForAge( int $age ): float {
        $age = self::clampAge( $age );

        if ( $age < 12 ) return 16.0;
        return $age < 15 ? 18.0 : 20.0;
    }

    /**
     * Typical weight in kg at an age, with no scatter — the value a target
     * band is centred on. Derived from `typicalHeight()` so the two bands
     * cannot drift apart.
     */
    public static function typicalWeight( int $age ): float {
        $metres = self::typicalHeight( $age ) / 100.0;

        return max( 20.0, self::bmiForAge( $age ) * $metres * $metres );
    }

    /** One player's weight in kg, from their own height plus scatter. */
    public static function weightForAge( int $age, int $height_cm ): int {
        $metres = $height_cm / 100.0;
        $weight = (int) round( self::bmiForAge( $age ) * $metres * $metres );

        return max( 20, $weight + mt_rand( -3, 4 ) );
    }

    private static function clampAge( int $age ): int {
        return max( self::MIN_AGE, min( self::MAX_AGE, $age ) );
    }
}
