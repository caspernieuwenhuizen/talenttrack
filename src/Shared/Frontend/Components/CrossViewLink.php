<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CrossViewLink (#2304) — the render helper for authorization-aware
 * navigation affordances (in-body cross-view links, tiles, buttons).
 *
 * A navigation affordance MUST be hidden when the current user can't reach
 * its target view (CLAUDE.md §7). This helper centralizes that decision:
 * pass the target `tt_view` slug and a `$render` callback that echoes the
 * `<a>` / tile HTML, and the callback only fires when the current user
 * passes the slug's gate (see `CrossViewLinkRegistry`).
 *
 * Usage:
 *
 *   CrossViewLink::render( 'team-planner', function () use ( $url ) {
 *       echo '<a class="tt-player-action" href="' . esc_url( $url ) . '">'
 *           . esc_html__( 'Planner', 'talenttrack' ) . '</a>';
 *   } );
 *
 * For the link-vs-span case (render a live link when allowed, an inert
 * span otherwise) use the `allows()` decision helper directly.
 */
final class CrossViewLink {

    /**
     * Render (echo) the affordance via `$render` only when the current user
     * passes the slug's gate.
     *
     * @param string              $slug    target `tt_view` slug
     * @param callable            $render  echoes the affordance HTML
     * @param array<string,mixed> $opts    ['ctx' => array, 'gate' => string|array|callable]
     */
    public static function render( string $slug, callable $render, array $opts = [] ): void {
        if ( self::allows( $slug, $opts ) ) {
            $render();
        }
    }

    /**
     * Whether the current user may reach `$slug`. Used both by `render()`
     * and by call sites that branch on the decision (e.g. link vs. span).
     *
     * When `$opts['gate']` is provided it wins — evaluated the same way the
     * registry normalizes the three gate forms. Otherwise the registered
     * gate (or the permissive fallback) decides.
     *
     * @param array<string,mixed> $opts ['ctx' => array, 'gate' => string|array|callable]
     */
    public static function allows( string $slug, array $opts = [] ): bool {
        // #3254 — "does this surface exist on this install?" is asked before
        // any gate, including a caller's own. A gate answers who may do the
        // thing; a switched-off module means there is no thing.
        if ( CrossViewLinkRegistry::surfaceSwitchedOff( $slug ) ) return false;

        $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $ctx = isset( $opts['ctx'] ) && is_array( $opts['ctx'] ) ? $opts['ctx'] : [];

        if ( array_key_exists( 'gate', $opts ) && $opts['gate'] !== null ) {
            if ( $uid <= 0 ) return false;
            return CrossViewLinkRegistry::evaluate( $opts['gate'], $uid, $ctx );
        }

        return CrossViewLinkRegistry::allows( $slug, $uid, $ctx );
    }
}
