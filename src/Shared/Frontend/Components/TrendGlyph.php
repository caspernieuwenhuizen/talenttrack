<?php
namespace TT\Shared\Frontend\Components;

use TT\Modules\Measurements\Reports\MeasurementDeltaFormat;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TrendGlyph (#2628) — the one place that renders "which way did this move".
 *
 * Two measurement reports show the same trend for the same player and must
 * not disagree about it, the same reason MeasurementDeltaFormat was lifted
 * out of a view in #2586. This is that component for the indicator itself:
 * Test results and Test trends both call it, so a reader comparing the two
 * screens sees the identical token.
 *
 * The rules it owns:
 *
 *   - a glyph always accompanies the colour. Green-versus-red alone is
 *     invisible to a red/green colour-blind reader and does not survive a
 *     greyscale print, and these reports are printed;
 *   - the glyph follows the *verdict*, never the sign of the number. On a
 *     lower-is-better test `−0,08 s` pairs with ▲. Both derive from the same
 *     current/previous pair, so they cannot contradict each other;
 *   - a test whose `direction` column is `neutral` has no better or worse.
 *     Height and weight get a grey glyph reporting which way the value moved
 *     and no verdict word — a fact, not a judgement;
 *   - no previous reading is an em dash, never a fabricated zero.
 *
 * It renders a state; it never derives one. TestTrendsQuery::withChange()
 * produces the state alongside the delta, so the view maps state → glyph and
 * nothing more (CLAUDE.md §4) and the REST payload carries the same six-value
 * vocabulary for a front end that wants to pick its own glyph.
 *
 * Solid triangles rather than any other family because FrontendTestResultsView,
 * FrontendCohortBoardView and RateCardHeroWidget already use exactly these
 * codepoints; they are text-presentation in the standard Windows / Android /
 * iOS / Linux fonts, where the arrow emoji (U+2B06/07) would render as a
 * colour emoji on mobile and override the state colour.
 */
final class TrendGlyph {

    /** No previous reading — the state that is not a trend at all. */
    public const NONE = '';

    /** Direction-aware verdicts, from the measurement's `direction` column. */
    public const IMPROVED = 'up';
    public const DECLINED = 'down';
    public const FLAT     = 'flat';

    /** `direction = neutral`: the value moved, and that is all it means. */
    public const ROSE = 'rose';
    public const FELL = 'fell';

    /**
     * Glyph + accessible label per state. The two neutral states carry no
     * label: there is no verdict to announce, and "rose" read aloud beside a
     * height would imply one.
     *
     * @return array<string,array{0:string,1:string}>
     */
    private static function map(): array {
        return [
            self::IMPROVED => [ '▲', __( 'Improved',  'talenttrack' ) ],
            self::DECLINED => [ '▼', __( 'Declined',  'talenttrack' ) ],
            self::FLAT     => [ '▬', __( 'Unchanged', 'talenttrack' ) ],
            self::ROSE     => [ '▲', '' ],
            self::FELL     => [ '▼', '' ],
        ];
    }

    /**
     * The indicator, optionally trailed by the formatted change.
     *
     * @param string     $state one of the class constants
     * @param float|null $delta null renders no amount
     * @param string     $unit  the test's unit, appended to the amount
     */
    public static function render( string $state, ?float $delta = null, string $unit = '' ): string {
        if ( $state === self::NONE ) {
            return '<span class="tt-tr-empty">—</span>';
        }

        $map = self::map();
        [ $glyph, $label ] = $map[ $state ] ?? $map[ self::FLAT ];

        $amount = '';
        if ( $delta !== null ) {
            $amount = ' <span class="tt-tr-trend__delta">'
                . esc_html( MeasurementDeltaFormat::signed( $delta, $unit ) )
                . '</span>';
        }

        $title = $label !== '' ? ' title="' . esc_attr( $label ) . '"' : '';
        $sr    = $label !== '' ? '<span class="tt-tr-sr">' . esc_html( $label ) . '</span>' : '';

        return '<span class="tt-tr-trend tt-tr-trend--' . esc_attr( sanitize_html_class( $state ) ) . '"' . $title . '>'
            . '<span aria-hidden="true">' . esc_html( $glyph ) . '</span>'
            . $sr . '</span>'
            . $amount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped above.
    }
}
