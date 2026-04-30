<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendReportsLauncherView — frontend mirror of the wp-admin
 * reports launcher (#0063). Closes the parity gap the user
 * flagged: "some reports available in wp-admin are not available on
 * frontend".
 *
 * The three reports themselves (Player Progress & Radar / Team
 * rating averages / Coach activity) render on the wp-admin side and
 * lean on its form-submit + Chart.js infrastructure, so this
 * launcher links into them directly with `target="_blank"` rather
 * than re-rendering them on the frontend. The user gets discovery
 * + click-through from the dashboard.
 */
final class FrontendReportsLauncherView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }
        self::enqueueAssets();
        self::renderHeader( __( 'Reports', 'talenttrack' ) );

        $tiles = [
            [
                'slug'  => 'legacy',
                'label' => __( 'Player Progress & Radar', 'talenttrack' ),
                'desc'  => __( 'Player progress over time, radar comparisons, team-average radar.', 'talenttrack' ),
                'color' => '#2271b1',
            ],
            [
                'slug'  => 'team_ratings',
                'label' => __( 'Team rating averages', 'talenttrack' ),
                'desc'  => __( 'Average rating per team across all main categories.', 'talenttrack' ),
                'color' => '#00a32a',
            ],
            [
                'slug'  => 'coach_activity',
                'label' => __( 'Coach activity', 'talenttrack' ),
                'desc'  => __( 'Per-coach evaluation count and recent cadence.', 'talenttrack' ),
                'color' => '#7c3a9e',
            ],
        ];

        echo '<p style="color:#5b6e75; margin-bottom:16px;">';
        esc_html_e( 'Pick a report. The detail view opens in a new tab so you can keep this dashboard open.', 'talenttrack' );
        echo '</p>';

        echo '<div class="tt-cfg-tile-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;">';
        foreach ( $tiles as $tile ) {
            $url = admin_url( 'admin.php?page=tt-reports&report=' . sanitize_key( (string) $tile['slug'] ) );
            ?>
            <a class="tt-cfg-tile" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"
               style="display:block; background:#fff; border:1px solid #e5e7ea; border-left:4px solid <?php echo esc_attr( (string) $tile['color'] ); ?>; border-radius:8px; padding:14px; text-decoration:none; color:#1a1d21; min-height:76px;">
                <div style="font-weight:600; font-size:14px; line-height:1.25; margin-bottom:4px;"><?php echo esc_html( (string) $tile['label'] ); ?> &#8599;</div>
                <div style="color:#6b7280; font-size:12px; line-height:1.35;"><?php echo esc_html( (string) $tile['desc'] ); ?></div>
            </a>
            <?php
        }
        echo '</div>';
    }
}
