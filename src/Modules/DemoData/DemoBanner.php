<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoBanner — visible "DEMO MODE" indicators.
 *
 * - WordPress admin bar: a bright red "🎭 DEMO MODE" item for any user
 *   whose admin bar is showing (admin or frontend).
 * - Frontend shortcode output: a banner prepended to the output of
 *   `[talenttrack_dashboard]` when demo mode is ON.
 *
 * Both only render when DemoMode::isOn() returns true.
 */
class DemoBanner {

    public static function init(): void {
        add_action( 'admin_bar_menu', [ self::class, 'addAdminBarNode' ], 100 );
        add_filter( 'tt_dashboard_data', [ self::class, 'prependFrontendBanner' ], 10, 1 );
    }

    public static function addAdminBarNode( \WP_Admin_Bar $bar ): void {
        if ( ! DemoMode::isOn() ) return;
        $bar->add_node( [
            'id'    => 'tt-demo-mode',
            'title' => '<span style="background:#b32d2e;color:#fff;padding:2px 10px;border-radius:3px;font-weight:600;">🎭 DEMO MODE</span>',
            'href'  => admin_url( 'tools.php?page=tt-demo-data' ),
            'meta'  => [ 'title' => __( 'TalentTrack is running in demo mode. Real data is hidden.', 'talenttrack' ) ],
        ] );
    }

    public static function prependFrontendBanner( string $html ): string {
        if ( ! DemoMode::isOn() ) return $html;
        $msg = esc_html__( '🎭 This is TalentTrack demo data. Real club records are hidden while demo mode is on.', 'talenttrack' );
        $banner = '<div style="background:#b32d2e;color:#fff;padding:10px 16px;border-radius:6px;margin-bottom:16px;font-weight:600;">' . $msg . '</div>';
        return $banner . $html;
    }
}
