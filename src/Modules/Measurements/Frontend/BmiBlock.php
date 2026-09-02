<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Measurements\Reports\BmiQuery;

/**
 * BmiBlock (#2895) — the one renderer every BMI-for-age surface uses.
 *
 * Decision 3 on the issue: the roster table, the per-player trend and the
 * Measurements-tab block are three placements of ONE component, not three
 * implementations that happen to agree today. A number that means "this child
 * is heavy for their age" must not be formatted two different ways on two
 * screens.
 *
 * #3278 scoped the second half of that — "a caveat shown on one must be shown
 * on all of them" — to the surfaces dedicated to BMI. The reasoning behind it
 * stands: a caveat that appears only sometimes teaches people to ignore it,
 * and these are minors. But the player's Measurements tab is not a BMI report;
 * it is a file a coach opens mid-task, and the block there is a bare figure
 * that the full report explains. So the caveat is mandatory wherever BMI is
 * the subject, and the tab carries the figure alone.
 *
 * Presentation rules this component enforces, from the issue:
 *
 *   - No verdict. No colour-coded overweight/underweight, no red rows, no
 *     threshold styling. The percentile IS the output.
 *   - The reference is named on screen. A Dutch academy reading a WHO curve
 *     should know that is what they are looking at.
 *   - The pairing tolerance is stated, not implied. A BMI built from a weight
 *     and a height 27 days apart is a tolerance, not a fact, and the report has
 *     to be checkable.
 *   - An uncovered player renders as absent, not as zero.
 */
final class BmiBlock {

    /**
     * The caveat line. Rendered once per BMI *report* surface, always, even
     * when every row happens to be clean — a caveat that appears only
     * sometimes teaches people to ignore it.
     *
     * "Every surface" meant literally every one until #3278; it now means
     * every surface whose subject is BMI. The player's Measurements tab shows
     * the bare figure and does not call this.
     */
    public static function renderCaveat( BmiQuery $query ): void {
        $reference = $query->reference()->label();
        $days      = $query->pairWindowDays();

        echo '<p class="tt-bmi-caveat">';
        printf(
            /* translators: 1: growth reference name, 2: number of days. */
            esc_html__( 'Percentiles are against %1$s. A BMI is only shown when a weight and a height were recorded within %2$d days of each other; the gap for each figure is listed so you can judge it.', 'talenttrack' ),
            esc_html( $reference ),
            (int) $days
        );
        echo '</p>';
        echo '<p class="tt-bmi-caveat tt-bmi-caveat--soft">'
            . esc_html__( 'BMI describes a body, not a player. It is a screening figure for spotting change over time, not a judgement about any individual — read it alongside what you know about them.', 'talenttrack' )
            . '</p>';
    }

    /**
     * One player's latest standing: the BMI and where it sits on the curve.
     *
     * #3278 — the figure, and nothing that explains it. This renders on the
     * player's Measurements tab, where the reader arrived to do something
     * else and wants the standing in one glance; the caveat, the provenance
     * line and the change-since line all still render on
     * `Player · BMI-for-age`, which is the surface someone opens *to read
     * BMI*. That is where the explaining belongs.
     *
     * The percentile stays because it is a figure, not an explanation: for a
     * growing child the raw BMI barely means anything without the age-and-sex
     * reference the percentile carries.
     *
     * Two rules from #2895 survive this trim and are not negotiable here:
     * no verdict — no colour-coding, thresholds or overweight/underweight
     * styling — and an uncovered player renders as absent rather than as
     * zero, which is what the "no percentile" line is for. It marks a missing
     * figure; it does not explain the report.
     *
     * @param array<string,mixed> $row a row from BmiQuery::rosterRows()
     */
    public static function renderStanding( array $row ): void {
        $bmi = $row['bmi'] ?? null;

        if ( $bmi === null ) {
            echo '<p class="tt-bmi-empty">'
                . esc_html__( 'No BMI yet — it needs a height and a weight recorded close together.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<div class="tt-bmi-standing">';

        echo '<p class="tt-bmi-value"><span class="tt-bmi-number">'
            . esc_html( number_format_i18n( (float) $bmi, 1 ) )
            . '</span> <span class="tt-bmi-unit">'
            . esc_html__( 'BMI', 'talenttrack' )
            . '</span></p>';

        if ( empty( $row['covered'] ) || $row['percentile'] === null ) {
            echo '<p class="tt-bmi-uncovered">'
                . esc_html__( 'No percentile: the growth reference does not cover this age and sex.', 'talenttrack' )
                . '</p>';
        } else {
            echo '<p class="tt-bmi-percentile">';
            printf(
                /* translators: 1: percentile, 2: standard-deviation score. */
                esc_html__( '%1$s percentile (SDS %2$s)', 'talenttrack' ),
                '<strong>' . esc_html( self::ordinal( (float) $row['percentile'] ) ) . '</strong>',
                esc_html( self::signed( (float) $row['sds'] ) )
            );
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * The provenance line — measurement date and how far apart the two
     * readings were. This is what makes a figure checkable rather than
     * authoritative-looking.
     *
     * @param array<string,mixed> $row
     */
    public static function provenance( array $row ): string {
        $date = isset( $row['date'] ) ? self::formatDate( (string) $row['date'] ) : '';
        $gap  = (int) ( $row['gap_days'] ?? 0 );

        if ( $gap === 0 ) {
            /* translators: %s = measurement date. */
            return sprintf( __( 'Measured %s, height and weight the same day.', 'talenttrack' ), $date );
        }

        return sprintf(
            /* translators: 1: measurement date, 2: number of days between the two readings. */
            _n(
                'Measured %1$s, height recorded %2$d day apart.',
                'Measured %1$s, height recorded %2$d days apart.',
                $gap,
                'talenttrack'
            ),
            $date,
            $gap
        );
    }

    /**
     * A percentile as an ordinal — "62nd", not "62.0".
     *
     * Percentiles are read as positions, and a decimal point invites a
     * precision the underlying curve does not have.
     */
    public static function ordinal( float $percentile ): string {
        $n = (int) round( $percentile );
        $n = max( 1, min( 99, $n ) );

        // Dutch and most non-English locales do not form ordinals this way, so
        // the suffix goes through the translation layer rather than being
        // concatenated in English.
        return sprintf(
            /* translators: %d = a percentile position, e.g. 62. Render as an ordinal in your language. */
            __( '%dth', 'talenttrack' ),
            $n
        );
    }

    /** A z-score with an explicit sign, so +0.4 and -0.4 read as opposites. */
    public static function signed( float $value ): string {
        $formatted = number_format_i18n( abs( $value ), 2 );
        if ( abs( $value ) < 0.005 ) return $formatted;
        return ( $value < 0 ? '−' : '+' ) . $formatted;
    }

    private static function formatDate( string $ymd ): string {
        $ts = strtotime( $ymd );
        if ( $ts === false ) return $ymd;
        return date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
    }
}
