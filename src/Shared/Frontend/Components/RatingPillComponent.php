<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

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
 * Tier thresholds (percentage-of-max, locked in #0003 spec; restated
 * as percentages in v3.110.116 so they survive a config-driven scale
 * change):
 *   - **strong** (green):       ≥ 80% of rating_max
 *   - **developing** (yellow):  50% ≤ rating < 80% of rating_max
 *   - **needs attention** (red): rating < 50% of rating_max
 *
 * On the v1 1–5 scale these were 4.0 / 2.5 absolute. On the v3.110.116
 * 5–10 scale they translate to 8.0 / 5.0 — meaning the lowest possible
 * rating (5) sits at the developing/attention boundary; "attention"
 * tier rarely surfaces unless the operator widens the scale floor.
 *
 * Color is never the only indicator of tier — every rendered surface
 * carries the numeric value AND a tier label in the aria attribute.
 */
class RatingPillComponent {

    public const TIER_STRONG     = 'strong';
    public const TIER_DEVELOPING = 'developing';
    public const TIER_ATTENTION  = 'attention';

    /**
     * Resolve the tier for a given rating. `$max` defaults to null
     * which triggers a config read of `rating_max` so the tier is
     * always evaluated against the active scale. Callers that already
     * know the max (e.g. the rate-card view that pre-resolves it)
     * can pass it explicitly to skip the config lookup.
     *
     * v3.110.116 — was `float $max = 5.0`. Hardcoded default broke
     * the tier classifier on the new 5–10 scale (a rating of 5 was
     * normalised to 5.0 which exceeded the "strong" threshold of 4.0,
     * so every rating registered as "strong" / green).
     */
    public static function tierFor( float $rating, ?float $max = null ): string {
        $max = $max ?? (float) QueryHelpers::get_config( 'rating_max', '10' );
        if ( $max <= 0 ) return self::TIER_DEVELOPING;
        // Percent-of-max thresholds (see class docblock).
        $pct = ( $rating / $max ) * 100.0;
        if ( $pct >= 80.0 ) return self::TIER_STRONG;
        if ( $pct >= 50.0 ) return self::TIER_DEVELOPING;
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
     *
     * v3.110.116 — `$max` default now resolved from config when null
     * (was hardcoded `5.0`).
     */
    public static function pill( string $label, float $rating, ?float $max = null ): string {
        $max = $max ?? (float) QueryHelpers::get_config( 'rating_max', '10' );
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
     *
     * v3.110.116 — see `pill()` note on `$max` default.
     */
    public static function chip( float $rating, ?float $max = null ): string {
        $max = $max ?? (float) QueryHelpers::get_config( 'rating_max', '10' );
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
     *
     * v3.110.116 — see `pill()` note on `$max` default.
     */
    public static function badge( float $rating, ?float $max = null ): string {
        $max = $max ?? (float) QueryHelpers::get_config( 'rating_max', '10' );
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
