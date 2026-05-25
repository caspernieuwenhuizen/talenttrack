<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Helper for the existing "+ New X" buttons on manage views.
 *
 *   $url = WizardEntryPoint::urlFor( 'new-player', $flat_form_url );
 *
 * Returns the wizard URL when the wizard is registered + enabled +
 * the current user has the cap; falls back to the flat-form URL
 * otherwise. Manage-view callers stay simple — one line, no branch.
 */
final class WizardEntryPoint {

    public static function urlFor( string $wizard_slug, string $fallback_url ): string {
        if ( ! WizardRegistry::isAvailable( $wizard_slug ) ) return $fallback_url;
        // #901 — query var is `tt_wizard`, not `slug`. The previous
        // `slug` name collided with public query vars registered by
        // third-party plugins (Yoast SEO and several caching plugins
        // register `slug` via the `query_vars` filter), causing
        // `WP_Query::parse_query()` to mark the request `is_singular`
        // and 404 because no post matched the wizard's slug. All TT
        // query vars are now namespaced (`tt_view`, `tt_back`,
        // `tt_wizard`).
        return add_query_arg( [ 'tt_view' => 'wizard', 'tt_wizard' => $wizard_slug ], self::dashboardBaseUrl() );
    }

    /**
     * Resolve the dashboard's current page URL — the page where the
     * `[talenttrack_dashboard]` shortcode is rendered. Strips known
     * view-routing args so the result is a clean base.
     *
     * v3.85.5 — delegates to `RecordLink::dashboardUrl()` instead of
     * relying solely on `REQUEST_URI`. The pre-fix behaviour silently
     * 404'd whenever a wizard URL was visited from a non-dashboard
     * page (or after a same-page redirect like `&dismiss_resume=1`)
     * on installs where the home page isn't the dashboard. Reported
     * by a pilot install:
     *
     *   - Visit `?tt_view=wizard&slug=new-team` directly  →  REQUEST_URI
     *     resolves to `/` (because that's how WP routes a query-only
     *     URL). The home page on that install isn't the dashboard,
     *     so the shortcode never runs and the user sees the home
     *     page (which on a fresh install is empty / 404-like).
     *   - Click "Dismiss" on the resume banner  →  same.
     *
     * `RecordLink::dashboardUrl()` (shipped in v3.85.0) does the
     * right resolution chain: configured `dashboard_page_id` →
     * shortcode-discovery scan → REQUEST_URI fallback → home_url.
     * Reusing it puts wizard URLs on the same correctness footing
     * as every other dashboard-pointing URL builder in the
     * codebase. Falls back gracefully when RecordLink isn't loaded
     * (shouldn't happen — Shared/Frontend/Components is core — but
     * defensive against module-disable scenarios).
     */
    public static function dashboardBaseUrl(): string {
        if ( class_exists( '\\TT\\Shared\\Frontend\\Components\\RecordLink' ) ) {
            $base = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
            // Strip wizard-specific query args that the resolver
            // wouldn't have stripped (it only knows about the
            // detail-link args). Fixes the case where the operator
            // is currently on `?tt_view=wizard&slug=…` and clicks
            // a fresh wizard link — without this strip, the new
            // URL inherits the old `slug=`.
            return remove_query_arg(
                [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab', 'slug', 'tt_wizard', 'restart', 'action', 'id', 'dismiss_resume' ],
                $base
            );
        }

        // Defensive fallback if RecordLink isn't available.
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab', 'slug', 'tt_wizard', 'restart', 'action', 'id', 'dismiss_resume' ],
            $current ?: home_url( '/' )
        );
    }

    /**
     * v3.110.182 (#782 follow-up) — robust same-page redirect base URL.
     *
     * Use this from wizard `submit()` handlers and post-step redirects
     * inside a web request, instead of `dashboardBaseUrl()`. The
     * difference:
     *
     *   - `dashboardBaseUrl()` runs a four-stage resolution chain
     *     (`dashboard_page_id` config → shortcode-page scan →
     *     REQUEST_URI → `home_url('/')`) that's correct for REST /
     *     admin / CLI callers but brittle on the pilot's Strato install
     *     where `dashboard_page_id` config has drifted or the chain
     *     hands back a URL that doesn't actually host the shortcode.
     *     The user's wizard 404 on the new-team-blueprint and
     *     new-tournament wizards (#766 / #782) was that brittleness.
     *
     *   - `currentDashboardUrl()` uses `home_url($path)` where `$path`
     *     is the REQUEST_URI's path portion (no query). By definition
     *     the path the user is currently on routes — they're already
     *     rendering a wizard step from that URL. So the redirect base
     *     is guaranteed to land on the same shortcode page. Falls back
     *     to `home_url('/')` only when REQUEST_URI is missing (CLI /
     *     proxy weirdness), which is the same final fallback the
     *     existing chain ends with.
     *
     * The trade-off: this helper requires being called from inside the
     * web request. `dashboardBaseUrl()` stays the right pick for
     * contexts without that (REST controller building a URL for an
     * email link, admin page building a redirect target, etc.).
     */
    public static function currentDashboardUrl(): string {
        $path = '/';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $raw   = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
            $q_pos = strpos( $raw, '?' );
            $path  = $q_pos === false ? $raw : substr( $raw, 0, $q_pos );
            if ( $path === '' ) $path = '/';
        }
        return home_url( $path );
    }
}
