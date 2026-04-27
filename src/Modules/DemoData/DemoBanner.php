<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoBanner — visible "DEMO MODE" indicators.
 *
 * Renders the wp-admin bar node only. The frontend dashboard's own
 * header pulls a "DEMO" pill into its actions row when DemoMode::isOn()
 * (see DashboardShortcode::renderHeader, #0036). Previously a separate
 * banner was prepended via the `tt_dashboard_data` filter; that's been
 * dropped in favour of the in-header pill so the dashboard saves
 * vertical space.
 *
 * Renders only when DemoMode::isOn() returns true.
 */
class DemoBanner {

    public static function init(): void {
        add_action( 'admin_bar_menu', [ self::class, 'addAdminBarNode' ], 100 );
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
}
