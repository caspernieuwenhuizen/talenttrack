<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * RatingInputComponent — touch-friendly rating input (#1067).
 *
 * Two surfaces, one component:
 *
 *   - `renderSingle( $args )` — chip grid. 11 chips at the default
 *     5.0–10.0/0.5 scale (rows of 4 with one filler cell). One tap
 *     commits a final value, no keyboard, half-steps visually lighter
 *     than whole numbers. Used by the post-match / player self-
 *     evaluation forms where a single overall rating is captured.
 *
 *   - `renderListRow( $args )` — slider row. Label on the left, range
 *     slider in the middle, tabular value readout on the right. Used
 *     by the per-category eval wizards (`RateActorsStep`,
 *     `HybridDeepRateStep`) where each main + sub category gets its
 *     own row and the list can stack a dozen rows on one phone
 *     screen.
 *
 * Both shapes emit a posting input named `$args['name']` with the
 * rating value. The chip grid uses a hidden `<input>` (so chips are
 * just buttons), the slider row uses the `<input type="range">`
 * directly (so existing JS that reads `.tt-rate-input` values keeps
 * working).
 *
 * Range + step come from `tt_config` (`rating_min` / `rating_max` /
 * `rating_step` — schema is `DECIMAL(4,1)` so 0.5 is safe). The
 * `intended fallback` is whole-number chips above 11 cells — see the
 * `renderSingle` note on chip-count clamping.
 *
 * Mockup of record: `.local-mockups/player-rating-input/`. CSS lives
 * in `assets/css/components/rating-input.css`; chip-click + readout
 * JS in `assets/js/components/rating-input.js`. Enqueue both wherever
 * the host form renders (the forms in this PR enqueue via the
 * existing form-CSS handles — see callers).
 */
class RatingInputComponent {

    /**
     * Chip grid for a single rating.
     *
     * @param array{
     *   name:string,
     *   value?:float|string|null,
     *   label?:string,
     *   min?:float,
     *   max?:float,
     *   step?:float,
     *   required?:bool,
     *   disabled?:bool
     * } $args
     */
    public static function renderSingle( array $args ): string {
        $name     = (string) $args['name'];
        $value    = isset( $args['value'] ) ? (string) $args['value'] : '';
        $label    = (string) ( $args['label'] ?? '' );
        $required = ! empty( $args['required'] );
        $disabled = ! empty( $args['disabled'] );

        $min  = isset( $args['min'] )  ? (float) $args['min']  : (float) QueryHelpers::get_config( 'rating_min',  '5' );
        $max  = isset( $args['max'] )  ? (float) $args['max']  : (float) QueryHelpers::get_config( 'rating_max',  '10' );
        $step = isset( $args['step'] ) ? (float) $args['step'] : (float) QueryHelpers::get_config( 'rating_step', '0.5' );

        $values = self::stepSeries( $min, $max, $step );

        $hidden_id = 'tt-rating-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );

        $out  = '<div class="tt-rating-input tt-rating-input--single" data-tt-rating-input>';
        $out .= '<input type="hidden" id="' . esc_attr( $hidden_id ) . '" name="' . esc_attr( $name ) . '"';
        $out .= ' value="' . esc_attr( $value ) . '" data-tt-rating-value';
        if ( $required ) $out .= ' required';
        $out .= ' />';
        $out .= '<div class="tt-rating-chips" role="radiogroup"';
        if ( $label !== '' ) {
            $out .= ' aria-label="' . esc_attr( $label ) . '"';
        }
        $out .= '>';
        foreach ( $values as $v ) {
            $is_half     = abs( $v - round( $v ) ) > 0.001;
            $is_selected = $value !== '' && abs( (float) $value - $v ) < 0.001;
            $out .= '<button type="button" class="tt-rating-chip"';
            $out .= ' role="radio"';
            $out .= ' aria-checked="' . ( $is_selected ? 'true' : 'false' ) . '"';
            $out .= ' data-value="' . esc_attr( self::format( $v ) ) . '"';
            if ( $is_half ) $out .= ' data-half="true"';
            if ( $disabled ) $out .= ' disabled';
            $out .= '>' . esc_html( self::format( $v ) ) . '</button>';
        }
        // Fill the grid to a multiple of 4 so the last row stays even.
        $pad = ( 4 - ( count( $values ) % 4 ) ) % 4;
        for ( $i = 0; $i < $pad; $i++ ) {
            $out .= '<span class="tt-rating-chip tt-rating-chip--filler" aria-hidden="true"></span>';
        }
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    /**
     * Inline-slider row for a multi-category rating list.
     *
     * @param array{
     *   name:string,
     *   value?:float|string|null,
     *   label:string,
     *   sub_label?:string,
     *   sub?:bool,
     *   min?:float,
     *   max?:float,
     *   step?:float,
     *   disabled?:bool,
     *   input_class?:string,
     *   data_attrs?:array<string,string|int>
     * } $args
     */
    public static function renderListRow( array $args ): string {
        $name      = (string) $args['name'];
        $value     = isset( $args['value'] ) ? (string) $args['value'] : '';
        $label     = (string) ( $args['label'] ?? '' );
        $sub_label = (string) ( $args['sub_label'] ?? '' );
        $is_sub    = ! empty( $args['sub'] );
        // Hosts that supply their own label markup (e.g. a `<th>` in a
        // table layout — see HybridDeepRateStep) can opt out of the
        // component's label column, leaving just the slider + readout.
        $label_hidden = ! empty( $args['label_hidden'] );
        $disabled  = ! empty( $args['disabled'] );
        $extra_cls = (string) ( $args['input_class'] ?? '' );

        $min  = isset( $args['min'] )  ? (float) $args['min']  : (float) QueryHelpers::get_config( 'rating_min',  '5' );
        $max  = isset( $args['max'] )  ? (float) $args['max']  : (float) QueryHelpers::get_config( 'rating_max',  '10' );
        $step = isset( $args['step'] ) ? (float) $args['step'] : (float) QueryHelpers::get_config( 'rating_step', '0.5' );

        $input_id = 'tt-rating-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );

        // Slider sits at the midpoint when no value yet so the thumb is
        // visible (a true blank input would default to min). The hidden
        // `data-tt-rating-empty` flag tells the JS that the displayed
        // value is the midpoint default, not a real coach pick — the
        // host can read it back as 0 / empty for "not rated".
        $is_empty       = ( $value === '' || (float) $value === 0.0 );
        $display_value  = $is_empty ? (string) round( ( $min + $max ) / 2, 1 ) : $value;
        $readout_text   = $is_empty ? '—' : self::format( (float) $value );

        $classes = 'tt-rating-row';
        if ( $is_sub )       $classes .= ' tt-rating-row--sub';
        if ( $label_hidden ) $classes .= ' tt-rating-row--nolabel';
        if ( $extra_cls !== '' ) $classes .= ' ' . $extra_cls;

        $data_attr_str = '';
        if ( ! empty( $args['data_attrs'] ) && is_array( $args['data_attrs'] ) ) {
            foreach ( $args['data_attrs'] as $k => $v ) {
                $data_attr_str .= ' data-' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
            }
        }

        $out  = '<div class="' . esc_attr( $classes ) . '" data-tt-rating-row>';
        if ( ! $label_hidden ) {
            $out .= '<label class="tt-rating-row__label" for="' . esc_attr( $input_id ) . '">';
            $out .= esc_html( $label );
            if ( $sub_label !== '' ) {
                $out .= '<span class="tt-rating-row__sub">' . esc_html( $sub_label ) . '</span>';
            }
            $out .= '</label>';
        }
        $out .= '<input type="range" class="tt-rating-row__slider tt-rate-input"';
        $out .= ' id="' . esc_attr( $input_id ) . '"';
        $out .= ' name="' . esc_attr( $name ) . '"';
        $out .= ' min="' . esc_attr( (string) $min ) . '"';
        $out .= ' max="' . esc_attr( (string) $max ) . '"';
        $out .= ' step="' . esc_attr( (string) $step ) . '"';
        $out .= ' value="' . esc_attr( $display_value ) . '"';
        $out .= ' aria-label="' . esc_attr( $label ) . '"';
        if ( $is_empty ) $out .= ' data-tt-rating-empty="1"';
        if ( $disabled ) $out .= ' disabled';
        $out .= $data_attr_str;
        $out .= ' />';
        $out .= '<output class="tt-rating-row__val' . ( $is_empty ? ' tt-rating-row__val--unset' : '' ) . '"';
        $out .= ' for="' . esc_attr( $input_id ) . '"';
        $out .= ' data-tt-rating-readout>' . esc_html( $readout_text ) . '</output>';
        $out .= '</div>';
        return $out;
    }

    /**
     * Build the value series for a chip grid. Includes both endpoints.
     * Guards against degenerate config so we never emit zero chips.
     *
     * @return array<int,float>
     */
    private static function stepSeries( float $min, float $max, float $step ): array {
        if ( $step <= 0 || $max <= $min ) return [ $min ];
        $out = [];
        // Use integer math to avoid float-drift loops; convert step into
        // "half-step units" if step=0.5, "tenth-units" if step=0.1, etc.
        $factor = 1.0 / $step;
        $imin = (int) round( $min * $factor );
        $imax = (int) round( $max * $factor );
        for ( $i = $imin; $i <= $imax; $i++ ) {
            $out[] = $i / $factor;
        }
        return $out;
    }

    /**
     * Render a rating value the same way the schema stores it
     * (DECIMAL(4,1)) — trim a trailing `.0` only when the step itself
     * is a whole number, so an 8.0 reads as "8" on a whole-step config
     * but as "8.0" on a half-step config (keeps the "8" / "8.5" pairing
     * visually consistent on chips).
     */
    private static function format( float $v ): string {
        $rounded = round( $v, 1 );
        return number_format( $rounded, 1, '.', '' );
    }
}
