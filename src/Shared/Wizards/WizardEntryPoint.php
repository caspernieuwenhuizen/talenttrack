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
        return add_query_arg( [ 'tt_view' => 'wizard', 'slug' => $wizard_slug ], self::dashboardBaseUrl() );
    }

    /**
     * Resolve the dashboard's current page URL — the page where the
     * `[tt_dashboard]` shortcode is rendered. Strips known
     * view-routing args so the result is a clean base.
     *
     * Mirrors `DashboardShortcode::shortcodeBaseUrl()`. Public + here
     * because wizard redirects + entry-point links must use the page
     * the user is currently on, not `home_url('/')` (which breaks on
     * any install where the dashboard isn't on the front page).
     */
    public static function dashboardBaseUrl(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab', 'slug', 'restart', 'action', 'id' ],
            $current ?: home_url( '/' )
        );
    }
}
