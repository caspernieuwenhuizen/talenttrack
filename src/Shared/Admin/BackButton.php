<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackButton — renders a "← Back" link at the top of detail/edit
 * admin pages. Sprint v2.19.0.
 *
 * Strategy: use the HTTP Referer (via wp_get_referer()) to determine
 * where to send the user, with sensible fallbacks:
 *
 *   1. If the referer is set and points inside /wp-admin/, use it
 *   2. If the referer is missing, external, or points at the same
 *      page we're on (form re-submit, reload), fall back to the
 *      default URL supplied by the caller
 *   3. If the caller didn't supply a default, fall back to the
 *      TalentTrack dashboard
 *
 * Usage inside an edit/detail render method:
 *
 *   BackButton::render( admin_url( 'admin.php?page=tt-players' ) );
 *   echo '<h1>' . esc_html__( 'Edit Player', 'talenttrack' ) . '</h1>';
 *
 * Do NOT call this on list views — the sidebar menu is the back
 * affordance there.
 */
class BackButton {

    /**
     * Render a back link.
     *
     * @param string $fallback_url  URL to use if referer is unusable.
     * @param string $label         Optional label override. Defaults to "← Back".
     */
    public static function render( string $fallback_url = '', string $label = '' ): void {
        $target = self::resolveTarget( $fallback_url );
        if ( $label === '' ) {
            $label = __( '← Back', 'talenttrack' );
        }
        ?>
        <p class="tt-back-link" style="margin:6px 0 4px;">
            <a href="<?php echo esc_url( $target ); ?>" style="text-decoration:none; color:#555; font-size:13px;">
                <?php echo esc_html( $label ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Resolve the back-target URL. Public so other code (e.g. cancel
     * buttons in edit forms) can reuse the same logic.
     */
    public static function resolveTarget( string $fallback_url = '' ): string {
        $referer = wp_get_referer();

        $current_url = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current_url = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }

        // Fall back when referer is missing/unusable.
        if ( ! $referer ) {
            return self::effectiveFallback( $fallback_url );
        }

        // Normalize paths for self-compare — avoid reload loops where
        // the referer is literally the page we're on.
        $referer_path = wp_parse_url( $referer, PHP_URL_PATH ) . ( wp_parse_url( $referer, PHP_URL_QUERY ) ? '?' . wp_parse_url( $referer, PHP_URL_QUERY ) : '' );
        if ( $referer_path && strpos( $current_url, (string) wp_parse_url( $referer, PHP_URL_PATH ) ) !== false ) {
            // Same URL - could be form resubmit. If the referer's
            // query differs meaningfully from current, allow it.
            // Otherwise fall back.
            $referer_q = wp_parse_url( $referer, PHP_URL_QUERY ) ?: '';
            $current_q = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
            if ( $referer_q === $current_q ) {
                return self::effectiveFallback( $fallback_url );
            }
        }

        // Only trust refers into /wp-admin/.
        $referer_host_path = wp_parse_url( $referer, PHP_URL_PATH );
        if ( $referer_host_path && strpos( $referer_host_path, '/wp-admin/' ) === false ) {
            return self::effectiveFallback( $fallback_url );
        }

        return $referer;
    }

    private static function effectiveFallback( string $fallback_url ): string {
        if ( $fallback_url !== '' ) return $fallback_url;
        return admin_url( 'admin.php?page=talenttrack' );
    }
}
