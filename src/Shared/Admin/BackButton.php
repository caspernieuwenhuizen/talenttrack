<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackButton — hierarchical back navigation for admin pages.
 *
 * v2.22.0 rewrite. Previously used wp_get_referer() which caused a
 * ping-pong bug: the target page's referer was the page you just
 * came from, so a second "back" click landed you back where you
 * started. See BackNavigator for the detailed reasoning.
 *
 * This version uses the explicit parent map in BackNavigator.
 * Back button always navigates to the current page's parent, so
 * walking back repeatedly reliably climbs to the dashboard.
 *
 * Optionally renders a full breadcrumb trail above the back button.
 * Both link to the same resolved targets — clicking any breadcrumb
 * segment jumps directly to that ancestor.
 *
 * Usage (unchanged for callers from v2.19):
 *
 *   BackButton::render();
 *   BackButton::render( 'custom fallback URL' );  // legacy signature; fallback ignored
 *   BackButton::render( '', __( '← Back to players', 'talenttrack' ) );
 */
class BackButton {

    /**
     * Render the back link + breadcrumb trail.
     *
     * @param string $fallback_url  (IGNORED as of v2.22.0 — kept for backward-compat
     *                              with callers written for the v2.19.0 API; the
     *                              parent is now always resolved from BackNavigator.)
     * @param string $label         Optional label override. Defaults to "← Back".
     */
    public static function render( string $fallback_url = '', string $label = '' ): void {
        $route = BackNavigator::currentRoute();
        if ( BackNavigator::isHome( $route['page'], $route['action'] ) ) return;

        $target = BackNavigator::parentUrl( $route['page'], $route['action'] );
        if ( $label === '' ) {
            $label = __( '← Back', 'talenttrack' );
        }

        self::renderBreadcrumbs( $route['page'], $route['action'] );

        ?>
        <p class="tt-back-link" style="margin:6px 0 4px;">
            <a href="<?php echo esc_url( $target ); ?>" style="text-decoration:none; color:#555; font-size:13px;">
                <?php echo esc_html( $label ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Render a breadcrumb trail from Dashboard down to the current page.
     * Skipped silently when the current page has no map entry (wouldn't
     * be helpful to show "unknown › unknown ›" — better to show nothing).
     */
    private static function renderBreadcrumbs( string $page, string $action ): void {
        $crumbs = BackNavigator::breadcrumbs( $page, $action );
        if ( count( $crumbs ) < 2 ) return;

        ?>
        <nav class="tt-breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'talenttrack' ); ?>" style="margin:10px 0 4px; font-size:12px; color:#666;">
            <?php
            $total = count( $crumbs );
            foreach ( $crumbs as $i => $crumb ) :
                $is_last = ( $i === $total - 1 );
                ?>
                <?php if ( $is_last ) : ?>
                    <span style="color:#1a1d21; font-weight:500;"><?php echo esc_html( $crumb['label'] ); ?></span>
                <?php else : ?>
                    <a href="<?php echo esc_url( $crumb['url'] ); ?>" style="color:#2271b1; text-decoration:none;">
                        <?php echo esc_html( $crumb['label'] ); ?>
                    </a>
                    <span style="color:#ccc; margin:0 6px;">›</span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}
