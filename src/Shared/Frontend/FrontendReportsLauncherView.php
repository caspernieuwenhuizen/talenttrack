<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendReportsLauncherView — frontend reports launcher (#0063).
 *
 * #0077 M11: team_ratings + coach_activity render on the frontend
 * natively (FrontendReportDetailView) with a Print/Save-as-PDF button.
 *
 * v3.91.5 — the legacy "Player Progress & Radar" tile that deep-linked
 * to wp-admin was removed from the launcher. Operator complaint: a
 * frontend tile must not punt the user to wp-admin. The legacy view
 * still exists at `wp-admin/admin.php?page=tt-reports&report=legacy`
 * for admins who navigate there directly; the frontend just doesn't
 * advertise it anymore. Porting it natively (Chart.js + form-submit
 * round-trip) is tracked separately if the operator asks.
 */
final class FrontendReportsLauncherView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }

        // #0077 M11 — when ?type= is set, delegate to the detail view.
        $type = isset( $_GET['type'] ) ? sanitize_key( (string) wp_unslash( $_GET['type'] ) ) : '';
        $native_types = [ 'team_ratings', 'coach_activity' ];
        if ( in_array( $type, $native_types, true ) ) {
            FrontendReportDetailView::render( $type );
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Reports', 'talenttrack' ) );
        self::renderHeader( __( 'Reports', 'talenttrack' ) );

        $base_url = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        $tiles = [
            [
                'slug'  => 'team_ratings',
                'label' => __( 'Team rating averages', 'talenttrack' ),
                'desc'  => __( 'Average rating per team across all main categories.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'reports', 'type' => 'team_ratings' ], $base_url ),
            ],
            [
                'slug'  => 'coach_activity',
                'label' => __( 'Coach activity', 'talenttrack' ),
                'desc'  => __( 'Per-coach evaluation count and recent cadence.', 'talenttrack' ),
                'url'   => add_query_arg( [ 'tt_view' => 'reports', 'type' => 'coach_activity' ], $base_url ),
            ],
        ];

        echo '<p style="color:#5b6e75; margin-bottom:16px;">';
        esc_html_e( 'Pick a report.', 'talenttrack' );
        echo '</p>';

        echo '<div class="tt-cfg-tile-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;">';
        foreach ( $tiles as $tile ) {
            ?>
            <a class="tt-cfg-tile" href="<?php echo esc_url( (string) $tile['url'] ); ?>"
               style="display:block; background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px; text-decoration:none; color:#1a1d21; min-height:76px;">
                <div style="font-weight:600; font-size:14px; line-height:1.25; margin-bottom:4px;"><?php echo esc_html( (string) $tile['label'] ); ?></div>
                <div style="color:#6b7280; font-size:12px; line-height:1.35;"><?php echo esc_html( (string) $tile['desc'] ); ?></div>
            </a>
            <?php
        }
        echo '</div>';
    }
}
