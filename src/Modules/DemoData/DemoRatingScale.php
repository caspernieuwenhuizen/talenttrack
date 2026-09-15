<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * DemoRatingScale — generated ratings expressed in the scale the install
 * actually uses.
 *
 * Demo ratings used to be drawn on an internal 1–5 curve and remapped to
 * the configured range, with ±0.3 of noise applied before the remap. On a
 * 5–9 step-1 install that is a wobble of a third of a step around a climb
 * of two thirds of a step per season, landing on values (6.4, 6.7) the
 * scale has no way to express — so an improving player's list of ratings
 * read as noise and the archetype behind it was invisible (#3401).
 *
 * Everything here is in the install's own units: the curve climbs whole
 * steps, and every value written is one a coach could have picked.
 */
final class DemoRatingScale {

    private float $min;

    private float $max;

    private float $step;

    public function __construct( float $min, float $max, float $step ) {
        if ( $max <= $min ) {
            $min = 1.0;
            $max = 5.0;
        }
        if ( $step <= 0 || $step > ( $max - $min ) ) {
            $step = 1.0;
        }
        $this->min  = $min;
        $this->max  = $max;
        $this->step = $step;
    }

    public static function fromConfig(): self {
        return new self(
            (float) QueryHelpers::get_config( 'rating_min', '5' ),
            (float) QueryHelpers::get_config( 'rating_max', '10' ),
            (float) QueryHelpers::get_config( 'rating_step', '0.5' )
        );
    }

    public function min(): float {
        return $this->min;
    }

    public function max(): float {
        return $this->max;
    }

    public function step(): float {
        return $this->step;
    }

    public function span(): float {
        return $this->max - $this->min;
    }

    /** How many steps separate the bottom of the scale from the top. */
    public function steps(): int {
        return (int) round( $this->span() / $this->step );
    }

    /**
     * How far an improving player climbs across the whole window: one step
     * per season, capped at the scale's own range.
     *
     * A step a season is the acceptance criterion — the climb has to be
     * legible between one round and the next on the install's own scale, not
     * only on a chart. A short window therefore uses a short climb and keeps
     * the curve near the middle of the scale; a window long enough to need
     * the whole range gets it.
     */
    public function climbOver( int $seasons ): float {
        return min( $this->span(), max( 1, $seasons ) * $this->step );
    }

    /** Snap to a value the scale can express, inside its range. */
    public function quantise( float $value ): float {
        $snapped = $this->min + round( ( $value - $this->min ) / $this->step ) * $this->step;
        return round( max( $this->min, min( $this->max, $snapped ) ), 2 );
    }
}
