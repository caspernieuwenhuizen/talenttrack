<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RatingPillComponent — the shared "rating pill" used across player-
 * facing views (#0003 My evaluations, #0004 My card tile, future
 * #0014 player profile rebuild).
 *
 * Three render modes:
 *
 *   - **pill()**   — small chip with category label + rating value
 *                    + tier color + accessible aria-label.
 *   - **badge()**  — large circular badge for the overall score per
 *                    evaluation.
 *   - **chip()**   — like pill but without a category label, used
 *                    for the rolling-rating display on tiles.
 *
 * Tier thresholds (locked in #0003 spec):
 *   - **strong** (green):       rating ≥ 4.0
 *   - **developing** (yellow):  2.5 ≤ rating < 4.0
 *   - **needs attention** (red): rating < 2.5
 *
 * Color is never the only indicator of tier — every rendered surface
 * carries the numeric value AND a tier label in the aria attribute.
 */
class RatingPillComponent {

    public const TIER_STRONG     = 'strong';
    public const TIER_DEVELOPING = 'developing';
    public const TIER_ATTENTION  = 'attention';

    /**
     * Resolve the tier for a given rating, optionally normalized to a
     * different max scale (default 5).
     */
    public static function tierFor( float $rating, float $max = 5.0 ): string {
        if ( $max <= 0 ) return self::TIER_DEVELOPING;
        $normalized = ( $rating / $max ) * 5.0;
        if ( $normalized >= 4.0 ) return self::TIER_STRONG;
        if ( $normalized >= 2.5 ) return self::TIER_DEVELOPING;
        return self::TIER_ATTENTION;
    }

    public static function tierLabel( string $tier ): string {
        switch ( $tier ) {
            case self::TIER_STRONG:     return __( 'strong',          'talenttrack' );
            case self::TIER_DEVELOPING: return __( 'developing',      'talenttrack' );
            case self::TIER_ATTENTION:  return __( 'needs attention', 'talenttrack' );
        }
        return '';
    }

    /**
     * Pill chip: "Technical: 4.3" with green/yellow/red tier color.
     */
    public static function pill( string $label, float $rating, float $max = 5.0 ): string {
        $tier = self::tierFor( $rating, $max );
        $aria = sprintf(
            /* translators: 1: category label, 2: rating value, 3: max scale, 4: tier label */
            __( '%1$s, rating %2$s out of %3$s, %4$s', 'talenttrack' ),
            $label,
            number_format_i18n( $rating, 1 ),
            number_format_i18n( $max, 0 ),
            self::tierLabel( $tier )
        );
        return sprintf(
            '<span class="tt-rp tt-rp-%1$s" aria-label="%2$s" title="%3$s"><span class="tt-rp-label">%4$s</span><span class="tt-rp-value">%5$s</span></span>',
            esc_attr( $tier ),
            esc_attr( $aria ),
            esc_attr( number_format_i18n( $rating, 2 ) . ' / ' . number_format_i18n( $max, 0 ) ),
            esc_html( $label ),
            esc_html( number_format_i18n( $rating, 1 ) )
        );
    }

    /**
     * Standalone chip with just the rating + tier color, no label.
     * Used for rolling-rating display on the My card tile.
     */
    public static function chip( float $rating, float $max = 5.0 ): string {
        $tier = self::tierFor( $rating, $max );
        $aria = sprintf(
            /* translators: 1: rating value, 2: max scale, 3: tier label */
            __( 'Rating %1$s out of %2$s, %3$s', 'talenttrack' ),
            number_format_i18n( $rating, 1 ),
            number_format_i18n( $max, 0 ),
            self::tierLabel( $tier )
        );
        return sprintf(
            '<span class="tt-rp tt-rp-chip tt-rp-%1$s" aria-label="%2$s">%3$s</span>',
            esc_attr( $tier ),
            esc_attr( $aria ),
            esc_html( number_format_i18n( $rating, 1 ) )
        );
    }

    /**
     * Large circular badge for the overall score on each evaluation
     * row/card. ~60px diameter on desktop; scales on mobile via CSS.
     */
    public static function badge( float $rating, float $max = 5.0 ): string {
        $tier = self::tierFor( $rating, $max );
        $aria = sprintf(
            /* translators: 1: rating value, 2: max scale */
            __( 'Overall rating: %1$s out of %2$s', 'talenttrack' ),
            number_format_i18n( $rating, 1 ),
            number_format_i18n( $max, 0 )
        );
        return sprintf(
            '<span class="tt-rp-badge tt-rp-%1$s" aria-label="%2$s" role="img"><span aria-hidden="true">%3$s</span></span>',
            esc_attr( $tier ),
            esc_attr( $aria ),
            esc_html( number_format_i18n( $rating, 1 ) )
        );
    }
}
