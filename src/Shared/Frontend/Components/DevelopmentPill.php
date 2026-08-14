<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DevelopmentPill (#2387) — an informational "Under development" badge
 * rendered above a routable view whose owning feature has been marked
 * under development on the module/feature page.
 *
 * Cosmetic only: the flag never gates the surface (the feature stays fully
 * live). Visible to everyone — it sets expectations that a surface is still
 * being built. Rendered centrally by the dashboard dispatcher for every
 * view a flagged feature owns, so individual views need no opt-in.
 */
final class DevelopmentPill {

    /**
     * Pill HTML for a `tt_view=` slug, or '' when the slug isn't owned by
     * a feature marked under development (the common case).
     */
    public static function htmlForViewSlug( string $slug ): string {
        if ( $slug === '' || ! class_exists( '\\TT\\Core\\FeatureRegistry' ) ) return '';
        if ( ! \TT\Core\FeatureRegistry::underDevelopmentForViewSlug( $slug ) ) return '';

        $label   = __( 'Under development', 'talenttrack' );
        $tooltip = __( 'This feature is still being built and may change.', 'talenttrack' );

        return '<div class="tt-dev-pill-wrap">'
            . '<span class="tt-dev-pill" role="status" title="' . esc_attr( $tooltip ) . '">'
            . '<span class="tt-dev-pill__dot" aria-hidden="true"></span>'
            . esc_html( $label )
            . '</span></div>';
    }

    /** Echo the pill for a slug (no-op when not under development). */
    public static function render( string $slug ): void {
        echo self::htmlForViewSlug( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlForViewSlug escapes its dynamic parts.
    }
}
