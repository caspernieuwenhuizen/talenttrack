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
        // Placed under `top-secondary` so the node appears on the right
        // side of the admin bar, adjacent to the user/howdy dropdown,
        // instead of taking real estate on the left next to the site
        // title. Compact: emoji + 4-letter label, no wordy "DEMO MODE".
        $bar->add_node( [
            'id'     => 'tt-demo-mode',
            'parent' => 'top-secondary',
            'title'  => '<span style="background:#b32d2e;color:#fff;padding:1px 7px;border-radius:3px;font-size:11px;font-weight:700;letter-spacing:0.05em;line-height:1.6;">🎭 DEMO</span>',
            'href'   => admin_url( 'tools.php?page=tt-demo-data' ),
            'meta'   => [ 'title' => __( 'TalentTrack is running in demo mode. Real data is hidden.', 'talenttrack' ) ],
        ] );
    }

    public static function prependFrontendBanner( string $html ): string {
        if ( ! DemoMode::isOn() ) return $html;
        $label   = esc_html__( '🎭 DEMO', 'talenttrack' );
        $tooltip = esc_attr__( 'TalentTrack is running in demo mode. Real club records are hidden.', 'talenttrack' );
        $pill    = '<div style="text-align:right;margin-bottom:8px;"><span title="' . $tooltip . '" style="display:inline-block;background:#b32d2e;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;letter-spacing:0.05em;line-height:1.6;">' . $label . '</span></div>';
        return $pill . $html;
    }
}
