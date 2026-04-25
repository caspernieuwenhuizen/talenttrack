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
     * Render a back link pointing at the tile landing page.
     *
     * @param string $label Optional label override. Defaults to localized "← Back to dashboard".
     */
    public static function render( string $label = '' ): void {
        $target = self::resolveTarget();
        if ( $label === '' ) {
            $label = __( '← Back to dashboard', 'talenttrack' );
        }
        ?>
        <p class="tt-back-link" style="margin:6px 0 12px;">
            <a href="<?php echo esc_url( $target ); ?>" style="text-decoration:none; color:#555; font-size:14px;">
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
                'player_id', 'eval_id', 'session_id', 'goal_id', 'team_id',
                'id', 'action', 'tab', 'type_id',
            ],
            $current ?: home_url( '/' )
        );
    }
}
