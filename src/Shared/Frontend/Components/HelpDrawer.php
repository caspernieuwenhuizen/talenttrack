<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reusable help-drawer trigger. The drawer DOM + JS already lives in
 * DashboardShortcode (#0016 Part B); this component just emits the open
 * button anywhere a view wants context-aware help.
 *
 * Topic slug, when supplied, overrides the URL-based topic inference in
 * docs-drawer.js via the `data-tt-docs-topic` attribute. Without a slug
 * the drawer falls back to the `?tt_view=` -> topic map.
 */
final class HelpDrawer {

    public static function button( ?string $topic_slug = null, string $label = '' ): void {
        $help_url = add_query_arg( [ 'tt_view' => 'docs' ], home_url( '/' ) );
        if ( $topic_slug ) {
            $help_url = add_query_arg( [ 'topic' => $topic_slug ], $help_url );
        }
        $aria = $label !== '' ? $label : __( 'Help & docs', 'talenttrack' );
        $topic_attr = $topic_slug ? ' data-tt-docs-topic="' . esc_attr( $topic_slug ) . '"' : '';
        $label_html = $label !== '' ? '<span class="tt-help-button-label">' . esc_html( $label ) . '</span>' : '';

        echo '<a href="' . esc_url( $help_url ) . '" class="tt-help-button" '
            . 'data-tt-docs-drawer-open' . $topic_attr . ' '
            . 'title="' . esc_attr( $aria ) . '" '
            . 'aria-label="' . esc_attr( $aria ) . '">'
            . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="10"></circle>'
            . '<path d="M9.5 9a2.5 2.5 0 0 1 4.9.7c0 1.7-2.5 2.3-2.5 4"></path>'
            . '<circle cx="12" cy="17.5" r="0.6" fill="currentColor"></circle>'
            . '</svg>'
            . $label_html
            . '</a>';
    }
}
