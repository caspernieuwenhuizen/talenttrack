<?php
namespace TT\Modules\Measurements\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MeasurementDeltaFormat (#2586).
 *
 * One place that knows how a measurement *change* is written down, because
 * two reports show the same numbers and must not disagree about them.
 *
 * The rules it owns:
 *
 *   - the sign is explicit — a change reads as a movement, not a quantity,
 *     so `+0,4` and `−0,08` rather than `0,4` and `0,08`;
 *   - the minus is U+2212 MINUS SIGN, not a hyphen, so it aligns with digits;
 *   - precision follows the value: no decimals when the number is whole, one
 *     when one is enough, two at most — a stopwatch reading of `19.17` should
 *     not become `19,170`;
 *   - the decimal separator is the site locale's (`−0,08` on nl_NL), via
 *     `number_format_i18n()`;
 *   - the unit trails the number when the test has one.
 *
 * Deliberately says nothing about *direction*. Whether a negative delta is an
 * improvement depends on the test's `direction` column, and that belongs with
 * the trend verdict, not with formatting. A caller pairs this with the arrow;
 * both derive from the same current/previous pair so they cannot contradict
 * each other.
 *
 * Lifted out of FrontendTestTrendsView, which had these as private statics
 * while FrontendTestResultsView showed no number at all.
 */
final class MeasurementDeltaFormat {

    /** A change, signed so it reads as a movement, with its unit. */
    public static function signed( float $delta, string $unit = '' ): string {
        $sign = $delta > 0 ? '+' : ( $delta < 0 ? '−' : '' );
        $out  = $sign . self::num( abs( $delta ) );
        return $unit !== '' ? $out . ' ' . $unit : $out;
    }

    /** A measurement number at its natural precision, in the site locale. */
    public static function num( float $v ): string {
        $r = round( $v, 2 );
        $d = ( floor( $r ) === $r ) ? 0 : ( round( $r, 1 ) === $r ? 1 : 2 );
        return number_format_i18n( $r, $d );
    }
}
