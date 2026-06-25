<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendPrivateSection (#1867) — the dignified "kept private" state a
 * parent sees when their child has hidden a section. Never an error or a
 * blank screen: a calm explanation that the child chose to keep this to
 * themselves. Safeguarding/medical gating is separate and unaffected.
 */
class FrontendPrivateSection {

    /** Enqueue the visibility stylesheet (toggles + private state). Idempotent. */
    public static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-parent-visibility',
            TT_PLUGIN_URL . 'assets/css/frontend-parent-visibility.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    /** Full-page state: breadcrumb + a calm explanation. */
    public static function render( string $section_label ): void {
        self::enqueue();
        FrontendBreadcrumbs::fromDashboard( $section_label );
        echo self::card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped within card().
    }

    /** Inline card, e.g. for a hidden block on the development home. */
    public static function card(): string {
        return '<div class="tt-private-section">'
            . '<p class="tt-private-section__msg">'
            . esc_html__( 'Your child has chosen to keep this private.', 'talenttrack' )
            . '</p></div>';
    }
}
