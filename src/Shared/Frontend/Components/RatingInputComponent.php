<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * RatingInputComponent — number input bound to an evaluation-category
 * rating, paired with a visual dot track.
 *
 * Range + step come from the `rating_min` / `rating_max` / `rating_step`
 * config so a club running a 1–10 scale just changes config and every
 * rating input reflows. The dot track is decorative — the input's
 * number value is what POSTs. A tiny inline script (shared via
 * `assets/js/components/rating.js`) keeps the dots in sync.
 *
 * Usage (one per category):
 *
 *     foreach ( QueryHelpers::get_categories() as $cat ) {
 *         RatingInputComponent::render([
 *             'name'     => 'ratings[' . $cat->id . ']',
 *             'label'    => $cat->name,
 *             'required' => true,
 *         ]);
 *     }
 */
class RatingInputComponent {

    /**
     * @param array{name?:string, label?:string, required?:bool, value?:float, min?:float, max?:float, step?:float} $args
     */
    public static function render( array $args = [] ): string {
        $name     = (string) ( $args['name'] ?? 'rating' );
        $label    = (string) ( $args['label'] ?? __( 'Rating', 'talenttrack' ) );
        $required = ! empty( $args['required'] );

        $min  = isset( $args['min'] )  ? (float) $args['min']  : (float) QueryHelpers::get_config( 'rating_min',  '1' );
        $max  = isset( $args['max'] )  ? (float) $args['max']  : (float) QueryHelpers::get_config( 'rating_max',  '5' );
        $step = isset( $args['step'] ) ? (float) $args['step'] : (float) QueryHelpers::get_config( 'rating_step', '0.5' );
        $value = isset( $args['value'] ) ? (string) $args['value'] : '';

        // Dots: one per integer bucket between min and max. Visual only.
        $bucket_count = max( 1, (int) round( $max - $min ) + 1 );

        $dots = '';
        for ( $i = 0; $i < $bucket_count; $i++ ) {
            $dots .= '<span class="tt-rating-dot" data-step="' . esc_attr( (string) ( $min + $i ) ) . '"></span>';
        }

        $input_id = 'tt-rating-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );

        $out  = '<div class="tt-field">';
        $out .= '<label class="tt-field-label' . ( $required ? ' tt-field-required' : '' ) . '" for="' . esc_attr( $input_id ) . '">';
        $out .= esc_html( $label );
        $out .= '</label>';
        $out .= '<div class="tt-rating">';
        $out .= '<input type="number" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $name ) . '"';
        $out .= ' class="tt-input" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '"';
        $out .= ' step="' . esc_attr( (string) $step ) . '" value="' . esc_attr( $value ) . '"';
        if ( $required ) $out .= ' required';
        $out .= ' />';
        $out .= '<div class="tt-rating-track" aria-hidden="true">' . $dots . '</div>';
        $out .= '<span class="tt-rating-hint">(' . esc_html( (string) $min ) . '–' . esc_html( (string) $max ) . ')</span>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }
}
