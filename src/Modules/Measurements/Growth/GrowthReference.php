<?php
namespace TT\Modules\Measurements\Growth;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GrowthReference (#2895) — one growth reference, asked for a z-score.
 *
 * The interface exists because the reference is expected to change. WHO
 * 2007 ships because it is openly published; the Dutch TNO 2010 curve is
 * the preferred end state for a Dutch academy and swaps in behind this
 * interface if redistribution rights clear. That should be a new file and
 * one line of wiring, not a refactor of everything that reads a percentile.
 *
 * Implementations are pure: no database, no current user, no clock. Given
 * a value, an age and a sex they return a number, which is what makes them
 * testable against the reference's own published tables.
 */
interface GrowthReference {

    /**
     * Identifier for display and for recording which reference produced a
     * stored figure — e.g. `who-2007`.
     */
    public function key(): string;

    /** Human-readable name, translated. */
    public function label(): string;

    /**
     * Is this reference defined for the given age and sex?
     *
     * Outside its range the honest answer is nothing at all, not an
     * extrapolation: a curve that stops at 19 stops meaning something at 19.
     */
    public function covers( int $age_months, string $sex ): bool;

    /**
     * Standard-deviation score (z) for a value, or null when the reference
     * does not cover this age or sex.
     */
    public function sds( float $value, int $age_months, string $sex ): ?float;

    /**
     * The value at a given z for this age and sex — the inverse of
     * {@see sds()}. Used to draw the reference bands on a chart.
     */
    public function valueAtSds( float $z, int $age_months, string $sex ): ?float;
}
