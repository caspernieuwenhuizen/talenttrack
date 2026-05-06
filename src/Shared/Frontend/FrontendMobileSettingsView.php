<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Mobile\MobileActionHandlers;
use TT\Shared\Mobile\MobileSettings;

/**
 * FrontendMobileSettingsView — operator-only toggle for the per-club
 * mobile gate (#0084 Child 1).
 *
 * Reachable at `?tt_view=mobile-settings`. Cap `tt_edit_settings`.
 *
 * Single setting at the moment: `force_mobile_for_user_agents` —
 * whether the dispatcher redirects phone-class user agents away from
 * `desktop_only` routes. Default `true`. Operators flip to `false`
 * when they prefer their users see the cramped desktop view on mobile
 * rather than land on the prompt page.
 *
 * Sub-tile under Configuration → Mobile lands in #0084 Child 3 alongside
 * the broader rollout. For now the page is reached directly via URL or
 * the Configuration sidebar.
 */
class FrontendMobileSettingsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            self::renderHeader( __( 'Mobile experience', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage mobile settings.', 'talenttrack' ) . '</p>';
            return;
        }

        $settings = new MobileSettings();
        $enabled  = $settings->isMobileGateEnabled();
        $tt_msg   = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) $_GET['tt_msg'] ) : '';

        self::renderHeader( __( 'Mobile experience', 'talenttrack' ) );

        if ( $tt_msg === 'mobile_setting_saved' ) {
            echo '<div class="tt-notice tt-notice-success" role="status" style="margin-bottom:16px;">'
                . esc_html__( 'Mobile settings saved.', 'talenttrack' )
                . '</div>';
        }

        echo '<p style="max-width:760px;">'
            . esc_html__( "TalentTrack classifies every page as mobile-first, viewable, or desktop-only. Phones visiting a desktop-only page see a polite prompt with an \"email me the link\" affordance. Tablets and laptops get the full experience regardless.", 'talenttrack' )
            . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:24px; max-width:760px; padding:16px; background:#fafafa; border:1px solid #ddd;">';
        wp_nonce_field( MobileActionHandlers::ACTION_SAVE_SETTING, 'tt_mobile_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( MobileActionHandlers::ACTION_SAVE_SETTING ) . '">';

        $current_url = self::currentUrl();
        echo '<input type="hidden" name="return_to" value="' . esc_attr( $current_url ) . '">';

        echo '<label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer;">';
        echo '<input type="checkbox" name="enabled" value="1" ' . checked( $enabled, true, false ) . ' style="margin-top:4px;">';
        echo '<span><strong>' . esc_html__( 'Show the desktop-prompt page on phones for desktop-only routes.', 'talenttrack' ) . '</strong><br>';
        echo '<span style="color:#5b6e75; font-size:13px;">'
            . esc_html__( "Default on. Untick to let phone visitors see the cramped desktop view on every page. Tablets and laptops are unaffected either way.", 'talenttrack' )
            . '</span></span>';
        echo '</label>';

        echo '<p style="margin-top:16px;">';
        echo '<button type="submit" class="tt-button tt-button-primary">' . esc_html__( 'Save', 'talenttrack' ) . '</button>';
        echo '</p>';
        echo '</form>';
    }

    private static function currentUrl(): string {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return home_url( '/' );
        $path = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
        $host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
        if ( $host === '' ) return home_url( $path );
        $scheme = is_ssl() ? 'https' : 'http';
        return $scheme . '://' . $host . $path;
    }
}
