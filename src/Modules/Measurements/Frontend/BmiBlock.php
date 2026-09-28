<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Measurements\Reports\BmiQuery;
use TT\Shared\Dates\TTDate;

/**
 * BmiBlock (#2895) — the one renderer every BMI surface uses.
 *
 * Decision 3 on the issue: the roster table, the per-player trend and the
 * Measurements-tab block are three placements of ONE component, not three
 * implementations that happen to agree today. A figure about a child's body
 * must not be formatted two different ways on two screens.
 *
 * #3278 scoped the second half of that — "a caveat shown on one must be shown
 * on all of them" — to the surfaces dedicated to BMI. The reasoning behind it
 * stands: a caveat that appears only sometimes teaches people to ignore it,
 * and these are minors. But the player's Measurements tab is not a BMI report;
 * it is a file a coach opens mid-task, and the block there is a bare figure
 * that the full report explains. So the caveat is mandatory wherever BMI is
 * the subject, and the tab carries the figure alone.
 *
 * Presentation rules this component enforces:
 *
 *   - No verdict. No colour-coded overweight/underweight, no red rows, no
 *     threshold styling. The output is the BMI and how it has changed since
 *     the previous measurement.
 *   - No percentile or SDS on screen (#4173). The growth reference left most
 *     of a youth squad uncovered, so the column read as an apology on nearly
 *     every row. The REST payload still carries both.
 *   - The pairing tolerance is stated, not implied. A BMI built from a weight
 *     and a height 27 days apart is a tolerance, not a fact, and the report has
 *     to be checkable.
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
        $days = $query->pairWindowDays();

        echo '<p class="tt-bmi-caveat">';
        printf(
            /* translators: %d: number of days. */
            esc_html__( 'A BMI is only shown when a weight and a height were recorded within %d days of each other. Where they were not recorded on the same day, the gap is listed under the date so you can judge it.', 'talenttrack' ),
            (int) $days
        );
        echo '</p>';
        echo '<p class="tt-bmi-caveat tt-bmi-caveat--soft">'
            . esc_html__( 'BMI describes a body, not a player. It is a screening figure for spotting change over time, not a judgement about any individual — read it alongside what you know about them.', 'talenttrack' )
            . '</p>';
    }

    /**
     * One player's latest standing: the BMI, and nothing that explains it.
     *
     * #3278 — this is the glance, for a reader who arrived to do something
     * else; the caveat and the change-since line live on
     * `Player · BMI-for-age`, the surface someone opens *to read BMI*.
     * #4173 dropped the percentile line that used to sit under the figure.
     *
     * No verdict — no colour-coding, thresholds or overweight/underweight
     * styling.
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
        echo '</div>';
    }

    /**
     * The Change cell: the signed BMI change and, on a muted second line,
     * the date it is measured from. "First measurement" when there is no
     * earlier point to compare with.
     *
     * Escaped HTML, ready to echo.
     */
    public static function changeCellHtml( ?float $delta_bmi, ?string $previous_date ): string {
        if ( $delta_bmi === null || $previous_date === null || $previous_date === '' ) {
            return '<span class="tt-bmi-cell-empty">' . esc_html__( 'First measurement', 'talenttrack' ) . '</span>';
        }

        return esc_html( self::signedBmi( $delta_bmi ) )
            . '<span class="tt-bmi-cell-sub">'
            . esc_html( sprintf(
                /* translators: %s: date of the previous BMI measurement. */
                __( 'since %s', 'talenttrack' ),
                self::date( $previous_date )
            ) )
            . '</span>';
    }

    /**
     * The Measured cell: the date alone, plus a muted line with the gap
     * between the height and weight readings when it is not zero. The
     * same-day case adds nothing — the caveat already states the rule.
     *
     * Escaped HTML, ready to echo.
     */
    public static function measuredCellHtml( string $date, int $gap_days ): string {
        $out = esc_html( self::date( $date ) );
        if ( $gap_days > 0 ) {
            $out .= '<span class="tt-bmi-cell-sub">'
                . esc_html( sprintf(
                    /* translators: %d: number of days between the height and the weight reading. */
                    _n( 'height %d day apart', 'height %d days apart', $gap_days, 'talenttrack' ),
                    $gap_days
                ) )
                . '</span>';
        }
        return $out;
    }

    /** A BMI change with an explicit sign, so +0.4 and -0.4 read as opposites. */
    public static function signedBmi( float $value ): string {
        $formatted = number_format_i18n( abs( $value ), 1 );
        if ( abs( $value ) < 0.05 ) return $formatted;
        return ( $value < 0 ? '−' : '+' ) . $formatted;
    }

    /** A stored Y-m-d date in the academy's date format. */
    public static function date( string $ymd ): string {
        if ( $ymd === '' ) return '';
        $ts = strtotime( $ymd );
        if ( $ts === false ) return $ymd;
        return TTDate::date( $ts );
    }
}
