<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Usage\UsageTracker;

/**
 * UsageStatsPage — TalentTrack → Usage Statistics.
 *
 * Sprint v2.18.0. Admin-only dashboard summarizing app usage:
 * logins, unique active users, per-role breakdown, top admin pages,
 * daily active users chart, evaluations created chart, inactive users
 * nudge list.
 *
 * Data sourced from tt_usage_events (populated by UsageTracker hooks)
 * + tt_evaluations (for the evaluations-created series — more accurate
 * than an event that only exists post-instrumentation).
 *
 * 90-day rolling retention — older events are pruned nightly.
 */
class UsageStatsPage {

    private const CAP = 'tt_manage_settings';

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        // Enqueue Chart.js (same CDN/version used by the rate card).
        wp_enqueue_script(
            'tt-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        // Headline counts — 7, 30, 90 day windows.
        $logins_7d  = UsageTracker::countEvents( 'login', 7 );
        $logins_30d = UsageTracker::countEvents( 'login', 30 );
        $logins_90d = UsageTracker::countEvents( 'login', 90 );
        $active_7d  = UsageTracker::uniqueActiveUsers( 7 );
        $active_30d = UsageTracker::uniqueActiveUsers( 30 );
        $active_90d = UsageTracker::uniqueActiveUsers( 90 );

        $dau_90d   = UsageTracker::dailyActiveUsers( 90 );
        $evals_90d = UsageTracker::evaluationsCreatedDaily( 90 );
        $by_role   = UsageTracker::activeByRole( 30 );
        $top_pages = UsageTracker::topAdminPages( 30, 10 );
        $inactive  = UsageTracker::inactiveUsers( 30, 20 );

        $page_labels = self::adminPageLabels();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Usage Statistics', 'talenttrack' ); ?> <?php \TT\Shared\Admin\HelpLink::render( 'usage-statistics' ); ?></h1>
            <p style="color:#666; max-width:760px;">
                <?php esc_html_e( 'Overview of app usage across the last 90 days. Events older than 90 days are deleted automatically. No IP addresses or user agents are recorded.', 'talenttrack' ); ?>
            </p>

            <style>
            .tt-kpi-tile:hover, .tt-kpi-tile:focus {
                transform: translateY(-1px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.08);
                color: #1a1d21 !important;
            }
            .tt-role-bar-link {
                display: block;
                text-decoration: none;
                color: inherit;
                padding: 3px;
                margin: 3px -3px 7px;
                border-radius: 4px;
                transition: background 150ms ease;
            }
            .tt-role-bar-link:hover {
                background: #f6f7f7;
                color: inherit;
            }
            .tt-toppage-row { cursor: pointer; }
            .tt-toppage-row:hover td { background: #f6f7f7; }
            .tt-inactive-row { cursor: pointer; }
            .tt-inactive-row:hover td { background: #f6f7f7; }
            .tt-chart-hint {
                font-size: 11px;
                color: #888;
                margin: 4px 0 0;
                font-style: italic;
            }
            </style>

            <!-- Headline tiles — each links to its drill-down -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin:20px 0;">
                <?php self::headlineTile( __( 'Logins (7 days)', 'talenttrack' ),       $logins_7d,  '#2271b1', self::detailUrl( 'logins',       [ 'days' => 7 ] )  ); ?>
                <?php self::headlineTile( __( 'Logins (30 days)', 'talenttrack' ),      $logins_30d, '#2271b1', self::detailUrl( 'logins',       [ 'days' => 30 ] ) ); ?>
                <?php self::headlineTile( __( 'Logins (90 days)', 'talenttrack' ),      $logins_90d, '#2271b1', self::detailUrl( 'logins',       [ 'days' => 90 ] ) ); ?>
                <?php self::headlineTile( __( 'Active users (7 days)', 'talenttrack' ), $active_7d,  '#00a32a', self::detailUrl( 'active_users', [ 'days' => 7 ] )  ); ?>
                <?php self::headlineTile( __( 'Active users (30 days)', 'talenttrack' ),$active_30d, '#00a32a', self::detailUrl( 'active_users', [ 'days' => 30 ] ) ); ?>
                <?php self::headlineTile( __( 'Active users (90 days)', 'talenttrack' ),$active_90d, '#00a32a', self::detailUrl( 'active_users', [ 'days' => 90 ] ) ); ?>
            </div>

            <!-- DAU chart -->
            <div style="background:#fff; border:1px solid #dcdcde; padding:18px 22px; margin-bottom:16px;">
                <h2 style="margin:0 0 6px; font-size:16px;"><?php esc_html_e( 'Daily active users (90 days)', 'talenttrack' ); ?></h2>
                <p class="tt-chart-hint"><?php esc_html_e( 'Click any day to see who was active.', 'talenttrack' ); ?></p>
                <div style="position:relative; height:260px;">
                    <canvas id="tt-dau-chart"></canvas>
                </div>
            </div>

            <!-- Evaluations created chart -->
            <div style="background:#fff; border:1px solid #dcdcde; padding:18px 22px; margin-bottom:16px;">
                <h2 style="margin:0 0 6px; font-size:16px;"><?php esc_html_e( 'Evaluations created per day (90 days)', 'talenttrack' ); ?></h2>
                <p class="tt-chart-hint"><?php esc_html_e( 'Click any day to see the evaluations.', 'talenttrack' ); ?></p>
                <div style="position:relative; height:220px;">
                    <canvas id="tt-evals-chart"></canvas>
                </div>
            </div>

            <!-- Two-column: role breakdown + top pages -->
            <div style="display:grid; grid-template-columns:1fr 1.3fr; gap:16px;">

                <div style="background:#fff; border:1px solid #dcdcde; padding:18px 22px;">
                    <h2 style="margin:0 0 10px; font-size:16px;"><?php esc_html_e( 'Active users by role (30 days)', 'talenttrack' ); ?></h2>
                    <?php
                    $role_total = max( 1, array_sum( $by_role ) );
                    $role_labels = [
                        'admin'  => __( 'Admins', 'talenttrack' ),
                        'coach'  => __( 'Coaches', 'talenttrack' ),
                        'player' => __( 'Players', 'talenttrack' ),
                        'other'  => __( 'Other', 'talenttrack' ),
                    ];
                    $role_colors = [
                        'admin'  => '#b32d2e',
                        'coach'  => '#2271b1',
                        'player' => '#00a32a',
                        'other'  => '#888',
                    ];
                    foreach ( $role_labels as $k => $lbl ) :
                        $count = (int) ( $by_role[ $k ] ?? 0 );
                        $pct   = ( $count / $role_total ) * 100;
                        $url   = self::detailUrl( 'active_by_role', [ 'days' => 30, 'role' => $k ] );
                        ?>
                        <a class="tt-role-bar-link" href="<?php echo esc_url( $url ); ?>">
                            <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:3px;">
                                <span><?php echo esc_html( $lbl ); ?></span>
                                <strong><?php echo $count; ?></strong>
                            </div>
                            <div style="background:#f0f0f1; height:8px; border-radius:4px; overflow:hidden;">
                                <div style="width:<?php echo esc_attr( (string) $pct ); ?>%; height:100%; background:<?php echo esc_attr( $role_colors[ $k ] ); ?>;"></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div style="background:#fff; border:1px solid #dcdcde; padding:18px 22px;">
                    <h2 style="margin:0 0 10px; font-size:16px;"><?php esc_html_e( 'Most-visited admin pages (30 days)', 'talenttrack' ); ?></h2>
                    <?php if ( empty( $top_pages ) ) : ?>
                        <p style="color:#888;"><em><?php esc_html_e( 'No page view data yet.', 'talenttrack' ); ?></em></p>
                    <?php else :
                        $max_visits = max( array_column( $top_pages, 'count' ) );
                        ?>
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <tbody>
                            <?php foreach ( $top_pages as $row ) :
                                $slug = (string) $row['page'];
                                $lbl  = $page_labels[ $slug ] ?? $slug;
                                $bar  = ( $row['count'] / $max_visits ) * 100;
                                $url  = self::detailUrl( 'top_page', [ 'days' => 30, 'slug' => $slug ] );
                                ?>
                                <tr class="tt-toppage-row" onclick="window.location.href='<?php echo esc_js( $url ); ?>';">
                                    <td style="padding:4px 8px 4px 0; width:40%; vertical-align:middle;">
                                        <a href="<?php echo esc_url( $url ); ?>" style="text-decoration:none; color:inherit;"><?php echo esc_html( $lbl ); ?></a>
                                    </td>
                                    <td style="padding:4px 0; vertical-align:middle;">
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <div style="flex:1; background:#f0f0f1; height:6px; border-radius:3px; overflow:hidden;">
                                                <div style="width:<?php echo esc_attr( (string) $bar ); ?>%; height:100%; background:#2271b1;"></div>
                                            </div>
                                            <span style="color:#666; font-variant-numeric:tabular-nums; min-width:40px; text-align:right;"><?php echo (int) $row['count']; ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Inactive users -->
            <div style="background:#fff; border:1px solid #dcdcde; padding:18px 22px; margin-top:16px;">
                <h2 style="margin:0 0 6px; font-size:16px;"><?php esc_html_e( 'Users not seen in 30+ days', 'talenttrack' ); ?></h2>
                <p style="color:#666; font-size:12px; margin:0 0 10px;">
                    <?php esc_html_e( 'Users who have logged in within the 90-day retention window but not in the last 30 days. A nudge list for follow-up.', 'talenttrack' ); ?>
                </p>
                <?php if ( empty( $inactive ) ) : ?>
                    <p style="color:#888;"><em><?php esc_html_e( 'No inactive users — everyone has been active recently.', 'talenttrack' ); ?></em></p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width:760px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                                <th style="width:180px;"><?php esc_html_e( 'Last login', 'talenttrack' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $inactive as $u ) :
                            $url = self::detailUrl( 'user_timeline', [ 'uid' => $u['user_id'] ] );
                            ?>
                            <tr class="tt-inactive-row" onclick="window.location.href='<?php echo esc_js( $url ); ?>';">
                                <td><a href="<?php echo esc_url( $url ); ?>" style="text-decoration:none; color:inherit;"><?php echo esc_html( $u['display_name'] ); ?></a></td>
                                <td><?php echo esc_html( $u['last_login'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function(){
            if (typeof Chart === 'undefined') return;

            var dauLabels = <?php echo wp_json_encode( array_keys( $dau_90d ) ); ?>;
            var dauData   = <?php echo wp_json_encode( array_values( $dau_90d ) ); ?>;

            new Chart(document.getElementById('tt-dau-chart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: dauLabels,
                    datasets: [{
                        label: <?php echo wp_json_encode( __( 'Daily active users', 'talenttrack' ) ); ?>,
                        data: dauData,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34, 113, 177, 0.15)',
                        fill: true,
                        tension: 0.2,
                        pointRadius: 0,
                        pointHoverRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    onClick: function(evt, elements){
                        if (!elements || elements.length === 0) return;
                        var idx = elements[0].index;
                        var date = dauLabels[idx];
                        if (!date) return;
                        window.location.href = <?php echo wp_json_encode( admin_url( 'admin.php?page=tt-usage-stats-details&metric=dau_day&date=' ) ); ?> + encodeURIComponent(date);
                    },
                    onHover: function(evt, elements){
                        evt.native.target.style.cursor = elements && elements.length ? 'pointer' : 'default';
                    },
                    scales: {
                        x: { ticks: { maxTicksLimit: 10, autoSkip: true } },
                        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                    },
                    plugins: { legend: { display: false } }
                }
            });

            var evLabels = <?php echo wp_json_encode( array_keys( $evals_90d ) ); ?>;
            var evData   = <?php echo wp_json_encode( array_values( $evals_90d ) ); ?>;

            new Chart(document.getElementById('tt-evals-chart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: evLabels,
                    datasets: [{
                        label: <?php echo wp_json_encode( __( 'Evaluations created', 'talenttrack' ) ); ?>,
                        data: evData,
                        backgroundColor: '#00a32a'
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    onClick: function(evt, elements){
                        if (!elements || elements.length === 0) return;
                        var idx = elements[0].index;
                        var date = evLabels[idx];
                        if (!date) return;
                        window.location.href = <?php echo wp_json_encode( admin_url( 'admin.php?page=tt-usage-stats-details&metric=evals_day&date=' ) ); ?> + encodeURIComponent(date);
                    },
                    onHover: function(evt, elements){
                        evt.native.target.style.cursor = elements && elements.length ? 'pointer' : 'default';
                    },
                    scales: {
                        x: { ticks: { maxTicksLimit: 10, autoSkip: true } },
                        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        })();
        </script>
        <?php
    }

    private static function headlineTile( string $label, int $value, string $color, string $url = '' ): void {
        $style = 'background:#fff; border:1px solid #dcdcde; border-left:4px solid ' . esc_attr( $color ) . '; padding:12px 16px; display:block; text-decoration:none; color:#1a1d21; transition:transform 150ms ease, box-shadow 150ms ease;';
        if ( $url !== '' ) {
            ?>
            <a href="<?php echo esc_url( $url ); ?>" class="tt-kpi-tile" style="<?php echo esc_attr( $style ); ?>">
                <div style="font-size:11px; text-transform:uppercase; color:#666; letter-spacing:0.04em;"><?php echo esc_html( $label ); ?></div>
                <div style="font-size:28px; font-weight:700; color:<?php echo esc_attr( $color ); ?>; line-height:1.2;"><?php echo esc_html( (string) $value ); ?></div>
                <div style="font-size:11px; color:#888; margin-top:2px;"><?php esc_html_e( 'See details →', 'talenttrack' ); ?></div>
            </a>
            <?php
        } else {
            ?>
            <div style="<?php echo esc_attr( $style ); ?>">
                <div style="font-size:11px; text-transform:uppercase; color:#666; letter-spacing:0.04em;"><?php echo esc_html( $label ); ?></div>
                <div style="font-size:28px; font-weight:700; color:<?php echo esc_attr( $color ); ?>; line-height:1.2;"><?php echo esc_html( (string) $value ); ?></div>
            </div>
            <?php
        }
    }

    private static function detailUrl( string $metric, array $args = [] ): string {
        $args = array_merge( [ 'page' => 'tt-usage-stats-details', 'metric' => $metric ], $args );
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    /**
     * Map admin page slugs to human labels for the top-pages list.
     */
    private static function adminPageLabels(): array {
        return [
            'talenttrack'          => __( 'Dashboard', 'talenttrack' ),
            'tt-teams'             => __( 'Teams', 'talenttrack' ),
            'tt-players'           => __( 'Players', 'talenttrack' ),
            'tt-people'            => __( 'People', 'talenttrack' ),
            'tt-evaluations'       => __( 'Evaluations', 'talenttrack' ),
            'tt-sessions'          => __( 'Sessions', 'talenttrack' ),
            'tt-goals'             => __( 'Goals', 'talenttrack' ),
            'tt-reports'           => __( 'Reports', 'talenttrack' ),
            'tt-rate-cards'        => __( 'Player Rate Cards', 'talenttrack' ),
            'tt-config'            => __( 'Configuration', 'talenttrack' ),
            'tt-custom-fields'     => __( 'Custom Fields', 'talenttrack' ),
            'tt-eval-categories'   => __( 'Evaluation Categories', 'talenttrack' ),
            'tt-category-weights'  => __( 'Category Weights', 'talenttrack' ),
            'tt-usage-stats'       => __( 'Usage Statistics', 'talenttrack' ),
            'tt-docs'              => __( 'Help & Docs', 'talenttrack' ),
        ];
    }
}
