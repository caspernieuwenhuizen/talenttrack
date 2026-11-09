<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * RatingScale (#4976, epic #4975) — the owner of the academy's rating scale:
 * its bounds, its step, how many steps it has, and how a step on a five-point
 * form maps onto a stored rating and back.
 *
 * The scale lives in `tt_config` (`rating_min`, `rating_max`, `rating_step`).
 * Since #1641 it is locked to 5-9 in whole steps, shown as one to five stars,
 * and this reads it with the defaults `RatingInputComponent` uses.
 *
 * The mapping is the one rule a form score and a stored rating share:
 *
 *     stored = rating_min + ( form_step - 1 ) × rating_step
 *
 * It is only defined on a scale with exactly five steps. On any other scale
 * `toStored()` and `toFormStep()` refuse, and enabling the PDP forms is
 * refused with a message, rather than writing ratings the scale cannot hold.
 *
 * Other readers of the three config keys still read them directly; moving
 * them here is its own issue.
 */
final class RatingScale {

    public const FORM_STEPS = 5;

    private float $min;
    private float $max;
    private float $step;

    public function __construct( float $min, float $max, float $step ) {
        $this->min  = $min;
        $this->max  = $max;
        $this->step = $step;
    }

    /** The academy's current scale. */
    public static function current(): self {
        return new self(
            (float) QueryHelpers::get_config( 'rating_min', '5' ),
            (float) QueryHelpers::get_config( 'rating_max', '9' ),
            (float) QueryHelpers::get_config( 'rating_step', '1' )
        );
    }

    public function min(): float { return $this->min; }
    public function max(): float { return $this->max; }
    public function step(): float { return $this->step; }

    /** How many values the scale has, bounds included; 0 for a scale that has none. */
    public function stepCount(): int {
        if ( $this->step <= 0 || $this->max < $this->min ) return 0;
        $span = ( $this->max - $this->min ) / $this->step;
        $n    = (int) round( $span );
        // A step that does not divide the range leaves a scale with no clean
        // series; count it as none rather than guess.
        if ( abs( $span - $n ) > 1e-6 ) return 0;
        return $n + 1;
    }

    /** Can a five-point form be stored on this scale? */
    public function fitsFormSteps(): bool {
        return $this->stepCount() === self::FORM_STEPS;
    }

    /**
     * The stored rating for a step of a five-point form.
     *
     * @throws \DomainException when the scale has not got five steps, or the step is not 1 to 5.
     */
    public function toStored( int $form_step ): float {
        $this->assertFits();
        if ( $form_step < 1 || $form_step > self::FORM_STEPS ) {
            throw new \DomainException( 'A form step is 1 to ' . self::FORM_STEPS . ', got ' . $form_step . '.' );
        }
        return round( $this->min + ( $form_step - 1 ) * $this->step, 4 );
    }

    /**
     * The step of a five-point form a stored rating shows as.
     *
     * @throws \DomainException when the scale has not got five steps, or the rating is not on it.
     */
    public function toFormStep( float $stored ): int {
        $this->assertFits();
        $position = ( $stored - $this->min ) / $this->step;
        $index    = (int) round( $position );
        if ( abs( $position - $index ) > 1e-6 || $index < 0 || $index >= self::FORM_STEPS ) {
            throw new \DomainException( 'The rating ' . $stored . ' is not a value on this scale.' );
        }
        return $index + 1;
    }

    private function assertFits(): void {
        if ( ! $this->fitsFormSteps() ) {
            throw new \DomainException( sprintf(
                'The rating scale %s to %s in steps of %s has %d values, not %d.',
                $this->min, $this->max, $this->step, $this->stepCount(), self::FORM_STEPS
            ) );
        }
    }
}
