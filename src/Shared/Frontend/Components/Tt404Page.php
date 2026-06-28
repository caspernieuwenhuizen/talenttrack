<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\RecordLink;

/**
 * Tt404Page (#2035) — branded TalentTrack "page not found" surface.
 *
 * A pure presentation component: emits the branded 404 *content* using
 * `.tt-404-*` classes and tokens.css design tokens only. It makes no theme
 * calls and inlines no styles, so the exact same markup lifts into the SaaS
 * front end (CLAUDE.md §4) — the WP-takeover decision lives elsewhere, at the
 * Tt404Handler chokepoint.
 *
 * Two consumers, one component:
 *  - Tt404Handler renders innerHtml() inside its own `.tt-404 .tt-dashboard`
 *    wrapper for the standalone WP-404 takeover (a pre-app surface, so it
 *    carries a single primary "Back to dashboard" button — §5 pre-login-style
 *    exemption).
 *  - DashboardShortcode echoes innerHtml() for the in-app `?tt_view=<unknown>`
 *    fallback, already inside the dashboard shell, where the breadcrumb chain
 *    supplies the canonical back affordance.
 *
 * Copy is football-academy voiced and routed through `__()` so it is
 * translatable / operator-editable. No new palette: brand shades come from
 * `--tt-primary` / `--tt-secondary` already on the document.
 */
class Tt404Page {

    /**
     * The branded 404 inner content. Assumes it is rendered inside a
     * `.tt-dashboard` (or `.tt-404`) scope so the stylesheet selectors win.
     *
     * @param bool $with_button When true, append the primary
     *                          "Back to dashboard" button. The in-app
     *                          fallback omits it (the breadcrumb chain is the
     *                          back affordance); the standalone takeover
     *                          includes it (no chain on a pre-app surface).
     */
    public static function innerHtml( bool $with_button = false ): string {
        $headline = esc_html__( 'Offside! This page is out of play', 'talenttrack' );
        $subline  = esc_html__(
            "The link you followed has wandered off the pitch. The page may have been renamed, moved, or subbed off — but every player's journey carries on from the dashboard.",
            'talenttrack'
        );

        $html  = '<div class="tt-404-panel">';
        $html .= '<div class="tt-404-emblem" aria-hidden="true">404</div>';
        $html .= '<h1 class="tt-404-headline">' . $headline . '</h1>';
        $html .= '<p class="tt-404-subline">' . $subline . '</p>';

        if ( $with_button ) {
            $dashboard_url = RecordLink::dashboardUrl();
            if ( $dashboard_url === '' ) {
                $dashboard_url = home_url( '/' );
            }
            $html .= '<p class="tt-404-actions">';
            $html .= '<a class="tt-btn tt-btn-primary tt-404-home" href="' . esc_url( $dashboard_url ) . '">'
                   . esc_html__( 'Back to dashboard', 'talenttrack' )
                   . '</a>';
            $html .= '</p>';
        }

        $html .= '</div>';

        return $html;
    }
}
