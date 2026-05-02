<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendBackButton — referer-based back link for frontend dashboard
 * sub-pages (v2.21.0 tile-based frontend).
 *
 * The frontend dashboard now uses a tile landing page + sub-pages
 * reached by the ?tt_view=<slug> query parameter. When inside a
 * sub-page, this helper renders a "← Back to dashboard" link at the
 * top that returns to the tile landing.
 *
 * Distinct from the admin BackButton because:
 *   - Target is the dashboard URL (current page sans tt_view) rather
 *     than the HTTP referer. Frontend referers are less reliable
 *     (theme quirks, caching, CDN injection) and we have a well-known
 *     safe target: the same shortcode page without the tt_view param.
 *   - Styling uses frontend CSS classes (.tt-back-link) rather than
 *     admin inline styles.
 */
class FrontendBackButton {

    /**
     * Render a back link.
     *
     * v3.75.3 — fix issue #23. The pre-fix signature was
     * `render( string $label = '' )` — so callers that wanted a custom
     * **URL** (e.g. `FrontendPlayerDetailView` passing
     * `add_query_arg(['tt_view'=>'players'], …)`) had their URL silently
     * rendered as the button TEXT and the button still navigated to the
     * dashboard. The screenshot showed labels like `/DEMO/?TT_VIEW=PLAYERS`.
     * Both behaviours were wrong. New signature takes target URL first
     * + optional label second; existing one-arg callers passing a URL
     * now do what they always meant. The single call site that passed
     * a label as first arg (FrontendUsageStatsDetailsView) is updated
     * in this PR to use the new shape.
     *
     * @param string $target_url Optional target URL override. Defaults to the
     *                           tile-landing page resolved from the
     *                           current request URI.
     * @param string $label      Optional label override. Defaults to
     *                           localized "← Back to dashboard".
     */
    public static function render( string $target_url = '', string $label = '' ): void {
        $target = $target_url !== '' ? $target_url : self::resolveTarget();
        if ( $label === '' ) {
            $label = __( '← Back to dashboard', 'talenttrack' );
        }
        ?>
        <p class="tt-back-link" style="margin:6px 0 12px;">
            <a href="<?php echo esc_url( $target ); ?>" class="tt-btn tt-btn-secondary">
                <?php echo esc_html( $label ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Return the URL of the current page with tt_view + drill-down
     * params removed. Restores the tile landing.
     */
    public static function resolveTarget(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        // Strip params that put us inside a sub-view.
        return remove_query_arg(
            [
                'tt_view',
                'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id',
                'id', 'action', 'tab', 'type_id',
            ],
            $current ?: home_url( '/' )
        );
    }
}
