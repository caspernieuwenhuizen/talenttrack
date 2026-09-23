<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoMeasurementModel (#4036) — what a test battery entry looks like at an
 * age, without going impossible at the bottom of the ladder.
 *
 * The battery describes a twelve-year-old (`base`) and a yearly shift
 * (`per_year`), and every value used to come off that straight line. A line
 * is a fine description over three or four years either side of its anchor
 * and nonsense beyond it: juggling is 25 at twelve and 8 a year, so the line
 * said a seven-year-old juggles the ball **-15 times**, and the U7 target
 * band opened at -15 too. A negative count on a child's profile does not read
 * as a rough demo, it reads as a broken module.
 *
 * So a test whose line runs off the bottom of the ladder gets a curve there
 * instead: what the youngest age group typically manages, growing towards the
 * twelve-year-old's value a fixed share each year. Above twelve the line is
 * untouched — it was never the part that was wrong, and neither is a test
 * whose line reaches the bottom rung intact. Where the curve does apply, the
 * cohort's spread and its within-window gain scale with the typical value,
 * because a squad that juggles four times does not vary by eighteen.
 */
final class DemoMeasurementModel {

    /** The youngest rung any academy in the demo set fields. */
    public const YOUNGEST_AGE = 6;

    /** The age the battery's `base` describes. */
    public const ANCHOR_AGE = 12;

    /** Narrowest a band or improvement may get, as a share of the twelve-year-old's. */
    private const MIN_SPREAD_SHARE = 0.2;

    /**
     * The typical value, the spread around it and the within-window gain for
     * one battery entry at one age.
     *
     * `min` is what the test can physically read — zero for a count, a time
     * no child beats for a sprint. `young` is what the youngest age group
     * typically manages, and it is only consulted when the straight line has
     * already fallen below it by then.
     *
     * @param array{base:float, per_year:float, spread:float, improve:float, min:float, young:float, direction:string} $curve
     * @return array{typical:float, spread:float, improve:float}
     */
    public static function forAge( array $curve, int $age ): array {
        $base    = $curve['base'];
        $typical = self::typicalFor( $curve, $age );

        // The spread is only rescaled where the curve replaced the line — a
        // squad that juggles four times does not vary by eighteen. Everywhere
        // the line still applies, so does the spread the battery states: this
        // is a correction to one broken shape, not a new opinion about how
        // varied a cohort is.
        $share = 1.0;
        if ( $base !== 0.0 && $age < self::ANCHOR_AGE && self::lineRunsOut( $curve ) ) {
            $share = max( self::MIN_SPREAD_SHARE, min( 1.0, abs( $typical / $base ) ) );
        }

        return [
            'typical' => $typical,
            'spread'  => $curve['spread'] * $share,
            'improve' => $curve['improve'] * $share,
        ];
    }

    /**
     * @param array{base:float, per_year:float, spread:float, improve:float, min:float, young:float, direction:string} $curve
     */
    private static function typicalFor( array $curve, int $age ): float {
        $base   = $curve['base'];
        $linear = $base + ( ( $age - self::ANCHOR_AGE ) * $curve['per_year'] );

        if ( $age >= self::ANCHOR_AGE || ! self::lineRunsOut( $curve ) ) {
            return max( $curve['min'], $linear );
        }

        // Geometric between the youngest rung and the anchor: a fixed share
        // of growth a year, which is how a count behaves and how a straight
        // line does not.
        $young    = max( $curve['min'], $curve['young'] );
        $progress = max( 0.0, min( 1.0, ( $age - self::YOUNGEST_AGE ) / ( self::ANCHOR_AGE - self::YOUNGEST_AGE ) ) );
        if ( $young <= 0.0 || $base <= 0.0 ) return max( $curve['min'], $linear );

        return $young * pow( $base / $young, $progress );
    }

    /**
     * Does the straight line fall below what the youngest age group can do
     * before it gets there? Only then is it the wrong shape for the test.
     *
     * @param array{base:float, per_year:float, spread:float, improve:float, min:float, young:float, direction:string} $curve
     */
    private static function lineRunsOut( array $curve ): bool {
        if ( $curve['direction'] === 'lower' ) return false;

        $at_youngest = $curve['base'] + ( ( self::YOUNGEST_AGE - self::ANCHOR_AGE ) * $curve['per_year'] );

        return $at_youngest < $curve['young'];
    }

    /**
     * A value or a band edge, held inside what the test can actually read.
     * The floor is what stops a count going negative; the ceiling is what
     * stops a percentage going past 100.
     */
    public static function clamp( float $value, float $floor, ?float $ceiling = null ): float {
        $value = max( $floor, $value );

        return $ceiling !== null ? min( $ceiling, $value ) : $value;
    }
}
