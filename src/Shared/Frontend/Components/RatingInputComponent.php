<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * RatingInputComponent — 1–5 star rating input (#1641).
 *
 * One control, two entry points:
 *
 *   - `renderSingle( $args )` — a single overall rating (post-match /
 *     player self-evaluation).
 *   - `renderListRow( $args )` — a per-category row for the evaluation
 *     wizards (`RateActorsStep`, `HybridDeepRateStep`). Optionally
 *     label-less when the host supplies its own label cell.
 *
 * Both emit a hidden posting input named `$args['name']` carrying the
 * numeric value, a row of star buttons, and a qualitative readout. The
 * star widget replaced the earlier chip-grid / slider shapes (#1067) —
 * the slider's near-invisible track read as a stray circle on desktop
 * (#1642).
 *
 * Scale comes from `tt_config` (`rating_min` / `rating_max` /
 * `rating_step`). From #1641 the scale is locked to 5–9 in whole steps,
 * so the five stars map to 5 / 6 / 7 / 8 / 9 with the qualitative labels
 * onvoldoende … uitstekend. When the configured scale yields exactly five
 * steps the readout shows the qualitative word; otherwise it falls back
 * to the number, so a non-standard config never breaks.
 *
 * CSS in `assets/css/components/rating-input.css`; click / keyboard /
 * recalc-sync JS in `assets/js/components/rating-input.js`.
 */
class RatingInputComponent {

    /**
     * Qualitative labels for the five stars, lowest → highest. Indexed
     * to the value series (star 1 = first label). Translatable.
     *
     * @return string[]
     */
    public static function qualitativeLabels(): array {
        return [
            _x( 'Insufficient', 'player rating', 'talenttrack' ),
            _x( 'Poor', 'player rating', 'talenttrack' ),
            _x( 'Average', 'player rating', 'talenttrack' ),
            _x( 'Good', 'player rating', 'talenttrack' ),
            _x( 'Excellent', 'player rating', 'talenttrack' ),
        ];
    }

    /**
     * Single overall rating.
     *
     * @param array{
     *   name:string, value?:float|string|null, label?:string,
     *   min?:float, max?:float, step?:float, required?:bool, disabled?:bool
     * } $args
     */
    public static function renderSingle( array $args ): string {
        $name     = (string) $args['name'];
        $value    = isset( $args['value'] ) ? (string) $args['value'] : '';
        $label    = (string) ( $args['label'] ?? '' );
        $required = ! empty( $args['required'] );
        $disabled = ! empty( $args['disabled'] );

        [ $min, $max, $step ] = self::scale( $args );

        $out  = '<div class="tt-rating-input tt-rating-input--single">';
        $out .= self::renderStars( $name, $value, $label, $min, $max, $step, $disabled, '', [], $required );
        $out .= '</div>';
        return $out;
    }

    /**
     * Per-category row.
     *
     * @param array{
     *   name:string, value?:float|string|null, label:string,
     *   sub_label?:string, sub?:bool, label_hidden?:bool, min?:float,
     *   max?:float, step?:float, disabled?:bool, input_class?:string,
     *   data_attrs?:array<string,string|int>
     * } $args
     */
    public static function renderListRow( array $args ): string {
        $name         = (string) $args['name'];
        $value        = isset( $args['value'] ) ? (string) $args['value'] : '';
        $label        = (string) ( $args['label'] ?? '' );
        $sub_label    = (string) ( $args['sub_label'] ?? '' );
        $is_sub       = ! empty( $args['sub'] );
        $label_hidden = ! empty( $args['label_hidden'] );
        $disabled     = ! empty( $args['disabled'] );
        $input_class  = (string) ( $args['input_class'] ?? '' );
        $data_attrs   = ( ! empty( $args['data_attrs'] ) && is_array( $args['data_attrs'] ) ) ? $args['data_attrs'] : [];

        [ $min, $max, $step ] = self::scale( $args );

        $classes = 'tt-rating-row tt-rating-row--stars';
        if ( $is_sub )       $classes .= ' tt-rating-row--sub';
        if ( $label_hidden ) $classes .= ' tt-rating-row--nolabel';

        $input_id = 'tt-rating-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );

        $out = '<div class="' . esc_attr( $classes ) . '" data-tt-rating-row>';
        if ( ! $label_hidden ) {
            $out .= '<label class="tt-rating-row__label" for="' . esc_attr( $input_id ) . '">';
            $out .= esc_html( $label );
            if ( $sub_label !== '' ) {
                $out .= '<span class="tt-rating-row__sub">' . esc_html( $sub_label ) . '</span>';
            }
            $out .= '</label>';
        }
        $out .= self::renderStars( $name, $value, $label, $min, $max, $step, $disabled, $input_class, $data_attrs, false );
        $out .= '</div>';
        return $out;
    }

    /**
     * Shared star widget — hidden value input + star buttons + readout.
     *
     * @param array<string,string|int> $data_attrs
     */
    private static function renderStars(
        string $name, string $value, string $aria_label,
        float $min, float $max, float $step,
        bool $disabled, string $input_class, array $data_attrs, bool $required
    ): string {
        $values     = self::stepSeries( $min, $max, $step );
        $labels     = self::qualitativeLabels();
        $has_labels = ( count( $values ) === count( $labels ) );

        $is_empty = ( $value === '' || (float) $value === 0.0 );
        $cur      = $is_empty ? null : (float) $value;

        $input_id = 'tt-rating-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );
        $cls      = trim( 'tt-rating-hidden ' . $input_class );

        $data_attr_str = '';
        foreach ( $data_attrs as $k => $v ) {
            $data_attr_str .= ' data-' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
        }

        // Readout for the current value: the qualitative word when the
        // scale yields the five labelled stars, otherwise the number.
        $readout = '—';
        if ( ! $is_empty ) {
            $readout = self::format( (float) $cur );
            if ( $has_labels ) {
                foreach ( $values as $i => $v ) {
                    if ( abs( $v - (float) $cur ) < 0.001 ) { $readout = $labels[ $i ]; break; }
                }
            }
        }

        $out  = '<input type="hidden" class="' . esc_attr( $cls ) . '" id="' . esc_attr( $input_id ) . '"';
        $out .= ' name="' . esc_attr( $name ) . '" value="' . esc_attr( $is_empty ? '' : self::format( (float) $cur ) ) . '"';
        $out .= ' data-tt-rating-value';
        if ( $is_empty ) $out .= ' data-tt-rating-empty="1"';
        if ( $required )  $out .= ' required';
        $out .= $data_attr_str . ' />';

        $out .= '<div class="tt-rating-stars" role="radiogroup" aria-label="' . esc_attr( $aria_label ) . '"';
        if ( $has_labels ) {
            $out .= ' data-labels="' . esc_attr( (string) wp_json_encode( array_values( $labels ) ) ) . '"';
        }
        $out .= '>';
        foreach ( $values as $i => $v ) {
            $on  = ( ! $is_empty && (float) $cur >= $v - 0.001 );
            $sel = ( ! $is_empty && abs( (float) $cur - $v ) < 0.001 );
            $star_label = $has_labels ? $labels[ $i ] : self::format( $v );
            $tabindex   = ( $sel || ( $is_empty && $i === 0 ) ) ? '0' : '-1';
            $out .= '<button type="button" class="tt-rating-star' . ( $on ? ' is-on' : '' ) . '"';
            $out .= ' role="radio" aria-checked="' . ( $sel ? 'true' : 'false' ) . '"';
            $out .= ' data-value="' . esc_attr( self::format( $v ) ) . '"';
            $out .= ' aria-label="' . esc_attr( $star_label ) . '"';
            $out .= ' tabindex="' . $tabindex . '"';
            if ( $disabled ) $out .= ' disabled';
            $out .= '>&#9733;</button>';
        }
        $out .= '</div>';

        $out .= '<output class="tt-rating-row__val' . ( $is_empty ? ' tt-rating-row__val--unset' : '' ) . '"';
        $out .= ' data-tt-rating-readout>' . esc_html( $readout ) . '</output>';
        return $out;
    }

    /**
     * Resolve [min, max, step] from args, falling back to the locked
     * 5 / 9 / 1 config defaults.
     *
     * @param array<string,mixed> $args
     * @return array{0:float,1:float,2:float}
     */
    private static function scale( array $args ): array {
        $min  = isset( $args['min'] )  ? (float) $args['min']  : (float) QueryHelpers::get_config( 'rating_min',  '5' );
        $max  = isset( $args['max'] )  ? (float) $args['max']  : (float) QueryHelpers::get_config( 'rating_max',  '9' );
        $step = isset( $args['step'] ) ? (float) $args['step'] : (float) QueryHelpers::get_config( 'rating_step', '1' );
        return [ $min, $max, $step ];
    }

    /**
     * Build the inclusive value series for the scale. Guards against a
     * degenerate config so we never emit zero stars.
     *
     * @return array<int,float>
     */
    private static function stepSeries( float $min, float $max, float $step ): array {
        if ( $step <= 0 || $max <= $min ) return [ $min ];
        $out    = [];
        $factor = 1.0 / $step;
        $imin   = (int) round( $min * $factor );
        $imax   = (int) round( $max * $factor );
        for ( $i = $imin; $i <= $imax; $i++ ) {
            $out[] = $i / $factor;
        }
        return array_values( $out );
    }

    /** Render a value the way the schema stores it, trimming `.0`. */
    private static function format( float $v ): string {
        $rounded = round( $v, 1 );
        if ( abs( $rounded - round( $rounded ) ) < 0.001 ) {
            return (string) (int) round( $rounded );
        }
        return number_format( $rounded, 1, '.', '' );
    }
}
