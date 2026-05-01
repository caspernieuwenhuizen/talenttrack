<?php
namespace TT\Modules\Authorization\Impersonation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ImpersonationBanner (#0071 child 5) — non-dismissible yellow banner
 * rendered on every page during an active impersonation session.
 *
 * Hooks:
 *   - wp_body_open  — frontend (renders before the dashboard shortcode)
 *   - admin_notices — wp-admin (renders above any page content)
 *   - body_class    — adds `tt-impersonating` so themes can style around it
 */
final class ImpersonationBanner {

    public static function init(): void {
        add_action( 'wp_body_open',  [ self::class, 'renderFrontend' ] );
        add_action( 'admin_notices', [ self::class, 'renderAdmin' ] );
        add_filter( 'body_class',    [ self::class, 'bodyClass' ] );
        add_filter( 'admin_body_class', [ self::class, 'adminBodyClass' ] );
    }

    public static function renderFrontend(): void {
        if ( ! ImpersonationContext::isImpersonating() ) return;
        echo self::markup();
    }

    public static function renderAdmin(): void {
        if ( ! ImpersonationContext::isImpersonating() ) return;
        echo self::markup();
    }

    public static function bodyClass( $classes ) {
        if ( ImpersonationContext::isImpersonating() ) {
            $classes = (array) $classes;
            $classes[] = 'tt-impersonating';
        }
        return $classes;
    }

    public static function adminBodyClass( $classes ) {
        if ( ImpersonationContext::isImpersonating() ) {
            $classes = trim( (string) $classes . ' tt-impersonating' );
        }
        return $classes;
    }

    private static function markup(): string {
        $target = wp_get_current_user();
        $name   = $target && $target->ID ? (string) $target->display_name : '';
        $end_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tt_impersonation_end' ),
            'tt_impersonation_end',
            '_tt_impersonation_nonce'
        );

        ob_start();
        ?>
        <div role="alert" class="tt-impersonation-banner" style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#FCD34D;color:#0b1f3a;padding:8px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #b45309;">
            <span>
                <?php
                printf(
                    /* translators: %s: display name of the impersonated user */
                    esc_html__( 'Impersonating %s. Every action is logged.', 'talenttrack' ),
                    '<strong>' . esc_html( $name ) . '</strong>'
                );
                ?>
            </span>
            <a class="button" href="<?php echo esc_url( $end_url ); ?>" style="background:#0b1f3a;color:#FCD34D;padding:4px 12px;border-radius:6px;text-decoration:none;font-weight:600;">
                <?php esc_html_e( 'Switch back', 'talenttrack' ); ?>
            </a>
        </div>
        <style>body.tt-impersonating, html.tt-impersonating { padding-top: 40px !important; }</style>
        <?php
        return (string) ob_get_clean();
    }
}
