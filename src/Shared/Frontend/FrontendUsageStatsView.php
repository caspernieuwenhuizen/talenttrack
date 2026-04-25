<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Usage\UsageTracker;

/**
 * FrontendUsageStatsView — frontend mirror of the wp-admin usage page.
 *
 * #0019 Sprint 5. Per Q7: reuses Chart.js (already loaded by the
 * wp-admin page) via the same CDN. Renders the headline KPIs +
 * DAU + evaluations-per-day charts + role breakdown.
 *
 * Drill-down detail pages (per-day user lists, etc.) stay in
 * wp-admin — they're admin-tier and only HoD/admin will reach them.
 * Surfaces a "Detailed view" button that deep-links into wp-admin.
 */
class FrontendUsageStatsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::renderHeader( __( 'Usage statistics', 'talenttrack' ) );

        wp_enqueue_script( 'tt-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );

        $logins_7d  = UsageTracker::countEvents( 'login', 7 );
        $logins_30d = UsageTracker::countEvents( 'login', 30 );
        $logins_90d = UsageTracker::countEvents( 'login', 90 );
        $active_7d  = UsageTracker::uniqueActiveUsers( 7 );
        $active_30d = UsageTracker::uniqueActiveUsers( 30 );
        $active_90d = UsageTracker::uniqueActiveUsers( 90 );

        $dau   = UsageTracker::dailyActiveUsers( 90 );
        $evals = UsageTracker::evaluationsCreatedDaily( 90 );
        $roles = UsageTracker::activeByRole( 30 );

        $dau_labels = array_keys( (array) $dau );
        $dau_values = array_values( (array) $dau );
        $ev_labels  = array_keys( (array) $evals );
        $ev_values  = array_values( (array) $evals );

        $admin_url = admin_url( 'admin.php?page=tt-usage-stats' );

        ?>
        <p style="color:var(--tt-muted); max-width:760px; margin:0 0 var(--tt-sp-4);">
            <?php esc_html_e( 'Overview of app usage across the last 90 days. Events older than 90 days are deleted automatically. No IP addresses or user agents are recorded.', 'talenttrack' ); ?>
        </p>
        <p style="margin:0 0 var(--tt-sp-4);">
            <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $admin_url ); ?>">
                <?php esc_html_e( 'Open detailed view in wp-admin', 'talenttrack' ); ?>
            </a>
        </p>

        <div class="tt-grid tt-grid-3" style="margin-bottom:var(--tt-sp-4);">
            <?php self::kpi( __( 'Logins (7d)',        'talenttrack' ), $logins_7d ); ?>
            <?php self::kpi( __( 'Logins (30d)',       'talenttrack' ), $logins_30d ); ?>
            <?php self::kpi( __( 'Logins (90d)',       'talenttrack' ), $logins_90d ); ?>
            <?php self::kpi( __( 'Active users (7d)',  'talenttrack' ), $active_7d ); ?>
            <?php self::kpi( __( 'Active users (30d)', 'talenttrack' ), $active_30d ); ?>
            <?php self::kpi( __( 'Active users (90d)', 'talenttrack' ), $active_90d ); ?>
        </div>

        <div class="tt-panel">
            <h3 class="tt-panel-title"><?php esc_html_e( 'Daily active users (90 days)', 'talenttrack' ); ?></h3>
            <div style="position:relative; height:240px;">
                <canvas id="tt-fe-dau-chart"></canvas>
            </div>
        </div>

        <div class="tt-panel">
            <h3 class="tt-panel-title"><?php esc_html_e( 'Evaluations created per day (90 days)', 'talenttrack' ); ?></h3>
            <div style="position:relative; height:220px;">
                <canvas id="tt-fe-evals-chart"></canvas>
            </div>
        </div>

        <?php if ( $roles ) : ?>
            <div class="tt-panel">
                <h3 class="tt-panel-title"><?php esc_html_e( 'Active users by role (30 days)', 'talenttrack' ); ?></h3>
                <table class="tt-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Users', 'talenttrack' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $roles as $role => $count ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $role ); ?></td>
                            <td style="text-align:right;"><?php echo (int) $count; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <script>
        (function(){
            function ready(fn){ if (window.Chart) fn(); else setTimeout(function(){ ready(fn); }, 50); }
            ready(function(){
                var dauCanvas = document.getElementById('tt-fe-dau-chart');
                if (dauCanvas) {
                    new Chart(dauCanvas.getContext('2d'), {
                        type: 'line',
                        data: { labels: <?php echo wp_json_encode( $dau_labels ); ?>, datasets: [{
                            label: '<?php echo esc_js( __( 'Active users', 'talenttrack' ) ); ?>',
                            data: <?php echo wp_json_encode( $dau_values ); ?>,
                            borderColor: 'rgba(11, 61, 46, 0.85)',
                            backgroundColor: 'rgba(11, 61, 46, 0.18)',
                            fill: true, tension: 0.25
                        }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                    });
                }
                var evCanvas = document.getElementById('tt-fe-evals-chart');
                if (evCanvas) {
                    new Chart(evCanvas.getContext('2d'), {
                        type: 'bar',
                        data: { labels: <?php echo wp_json_encode( $ev_labels ); ?>, datasets: [{
                            label: '<?php echo esc_js( __( 'Evaluations', 'talenttrack' ) ); ?>',
                            data: <?php echo wp_json_encode( $ev_values ); ?>,
                            backgroundColor: 'rgba(232, 182, 36, 0.85)'
                        }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                    });
                }
            });
        })();
        </script>
        <?php
    }

    private static function kpi( string $label, int $value ): void {
        echo '<div class="tt-panel" style="text-align:center;">';
        echo '<div style="font-size:var(--tt-fs-xl); font-weight:700; color:var(--tt-primary);">' . esc_html( (string) $value ) . '</div>';
        echo '<div style="font-size:var(--tt-fs-xs); color:var(--tt-muted); text-transform:uppercase; letter-spacing:0.04em;">' . esc_html( $label ) . '</div>';
        echo '</div>';
    }
}
