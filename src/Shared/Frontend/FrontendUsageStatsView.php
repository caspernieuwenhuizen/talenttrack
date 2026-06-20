<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Usage\UsageTracker;

/**
 * FrontendUsageStatsView — Application KPIs dashboard.
 *
 * Engagement / adoption signals only — is the tool being used, by whom,
 * how much, and for what? Headline tiles: active users, logins per user,
 * stickiness (DAU/MAU), average session, observed time online, actions
 * per user. Panels: daily-active line, active-by-role, top features used
 * (frontend views + wp-admin pages), and dormant users to nudge.
 *
 * Deliberately NOT here: attendance %, goal completion, ratings — those
 * are football *outcomes* (report content) and live in the Reports
 * launcher, not on a usage dashboard.
 *
 * Period is selectable (30 / 60 / 90 days, default 30). The route slug
 * stays `usage-stats` so existing URLs / tiles / docs links don't break.
 */
class FrontendUsageStatsView extends FrontendViewBase {

    /** Period choices in days. */
    private const PERIODS = [ 30, 60, 90 ];

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Application KPIs', 'talenttrack' ) );
        self::renderHeader( __( 'Application KPIs', 'talenttrack' ) );

        $days = self::periodFromQuery();

        wp_enqueue_script( 'tt-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );

        // #app-kpis — engagement metrics only. Football outcomes
        // (attendance %, goal completion, ratings) are report content and
        // live in the Reports launcher, not on a usage dashboard.
        $active_users = UsageTracker::uniqueActiveUsers( $days );
        $logins       = UsageTracker::countEvents( 'login', $days );
        $time_stats   = UsageTracker::sessionStats( $days );
        $total_events = UsageTracker::totalEvents( $days );

        $dau   = UsageTracker::dailyActiveUsers( $days );
        $roles = UsageTracker::activeByRole( $days );

        // Stickiness — average daily-active / monthly-active, the classic
        // "how habitual is the tool?" ratio. MAU is always the 30-day
        // window regardless of the selected period.
        $avg_dau    = ( $dau !== [] ) ? array_sum( $dau ) / count( $dau ) : 0.0;
        $mau        = UsageTracker::uniqueActiveUsers( 30 );
        $stickiness = $mau > 0 ? (int) round( ( $avg_dau / $mau ) * 100 ) : 0;

        $logins_per_user  = $active_users > 0 ? round( $logins / $active_users, 1 ) : 0.0;
        $actions_per_user = $active_users > 0 ? round( $total_events / $active_users, 1 ) : 0.0;

        $admin_url = admin_url( 'admin.php?page=tt-usage-stats' );
        $base_url  = remove_query_arg( 'days' );

        ?>
        <p style="color:var(--tt-muted); max-width:760px; margin:0 0 var(--tt-sp-3);">
            <?php esc_html_e( 'Application-level KPIs over the selected window. Events older than 90 days are deleted automatically. No IP addresses or user agents are recorded.', 'talenttrack' ); ?>
        </p>

        <div class="tt-period-selector" style="display:flex; gap:6px; align-items:center; margin:0 0 var(--tt-sp-4);">
            <span style="font-size:13px; color:var(--tt-muted); margin-right:6px;"><?php esc_html_e( 'Period:', 'talenttrack' ); ?></span>
            <?php foreach ( self::PERIODS as $opt ) :
                $active = $opt === $days;
                $url    = add_query_arg( 'days', $opt, $base_url );
                $cls    = $active ? 'tt-btn tt-btn-primary' : 'tt-btn tt-btn-secondary';
                ?>
                <a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $url ); ?>"><?php
                    /* translators: %d is the number of days */
                    printf( esc_html__( 'Last %d days', 'talenttrack' ), (int) $opt );
                ?></a>
            <?php endforeach; ?>
            <a class="tt-btn tt-btn-secondary" style="margin-left:auto;" href="<?php echo esc_url( $admin_url ); ?>">
                <?php esc_html_e( 'Open in wp-admin', 'talenttrack' ); ?>
            </a>
        </div>

        <div class="tt-grid tt-grid-3" style="margin-bottom:var(--tt-sp-4);">
            <?php
            self::kpi( __( 'Active users',          'talenttrack' ), (string) $active_users, '' );
            self::kpi( __( 'Logins / user',         'talenttrack' ), self::formatNumber( $logins_per_user, 1 ), '' );
            self::kpi( __( 'Stickiness (DAU/MAU)',  'talenttrack' ), (string) $stickiness . '%', '' );
            self::kpi( __( 'Avg session',           'talenttrack' ), self::formatMinutes( (float) $time_stats['avg_session_minutes'] ), '' );
            self::kpi( __( 'Time online (observed)', 'talenttrack' ), self::formatHours( (int) $time_stats['total_minutes'] ), '' );
            self::kpi( __( 'Actions / user',        'talenttrack' ), self::formatNumber( $actions_per_user, 1 ), '' );
            ?>
        </div>

        <div class="tt-panel">
            <h3 class="tt-panel-title"><?php
                /* translators: %d is the number of days */
                printf( esc_html__( 'Daily active users (%d days)', 'talenttrack' ), (int) $days );
            ?></h3>
            <div style="position:relative; height:240px;">
                <canvas id="tt-fe-dau-chart"></canvas>
            </div>
        </div>

        <?php if ( $roles ) : ?>
            <div class="tt-panel">
                <h3 class="tt-panel-title"><?php
                    /* translators: %d is the number of days */
                    printf( esc_html__( 'Active users by role (%d days)', 'talenttrack' ), (int) $days );
                ?></h3>
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

        <?php
        // What features people actually use — frontend views + wp-admin
        // pages, most-used first. The "is the tool being used as designed"
        // signal, replacing the old domain panels.
        $top_features = UsageTracker::topFeatures( $days, 10 );
        $total_views  = array_sum( array_map( static fn( $r ) => (int) $r['count'], $top_features ) );
        ?>
        <div class="tt-panel">
            <h3 class="tt-panel-title"><?php esc_html_e( 'Top features used', 'talenttrack' ); ?></h3>
            <?php if ( empty( $top_features ) ) : ?>
                <p><em><?php esc_html_e( 'No views recorded in this window yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="tt-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Feature', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Views', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Share', 'talenttrack' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $top_features as $row ) :
                        $count = (int) $row['count'];
                        $pct   = $total_views > 0 ? ( $count / $total_views ) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html( self::featureLabel( (string) $row['target'] ) ); ?></td>
                            <td style="text-align:right; font-variant-numeric: tabular-nums;"><?php echo (int) $count; ?></td>
                            <td style="text-align:right; font-variant-numeric: tabular-nums;"><?php echo esc_html( number_format_i18n( $pct, 0 ) ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php
        // Dormant users — invited people who haven't logged in for the
        // selected window. Who to nudge.
        $dormant = UsageTracker::inactiveUsers( $days, 15 );
        ?>
        <div class="tt-panel">
            <h3 class="tt-panel-title"><?php
                /* translators: %d is the number of days */
                printf( esc_html__( 'Dormant users (no login in %d days)', 'talenttrack' ), (int) $days );
            ?></h3>
            <?php if ( empty( $dormant ) ) : ?>
                <p><em><?php esc_html_e( 'Everyone with an account has logged in recently.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="tt-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Last login', 'talenttrack' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $dormant as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $row['display_name'] ); ?></td>
                            <td style="text-align:right;"><?php
                                $ts = strtotime( (string) $row['last_login'] );
                                echo esc_html( $ts ? (string) wp_date( 'j M Y', $ts ) : '—' );
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            var dauLabels = <?php echo wp_json_encode( array_keys( (array) $dau ) ); ?>;
            var dauValues = <?php echo wp_json_encode( array_values( (array) $dau ) ); ?>;
            function ready(fn){ if (window.Chart) fn(); else setTimeout(function(){ ready(fn); }, 50); }
            ready(function(){
                var c = document.getElementById('tt-fe-dau-chart');
                if (!c) return;
                new Chart(c.getContext('2d'), {
                    type: 'line',
                    data: { labels: dauLabels, datasets: [{
                        label: '<?php echo esc_js( __( 'Active users', 'talenttrack' ) ); ?>',
                        data: dauValues,
                        borderColor: 'rgba(11, 61, 46, 0.85)',
                        backgroundColor: 'rgba(11, 61, 46, 0.18)',
                        fill: true, tension: 0.25
                    }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } }
                    }
                });
            });
        })();
        </script>
        <?php
    }

    private static function periodFromQuery(): int {
        $raw = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
        return in_array( $raw, self::PERIODS, true ) ? $raw : 30;
    }

    private static function kpi( string $label, string $value, string $href = '' ): void {
        $tag_open  = $href !== ''
            ? '<a class="tt-panel" style="text-align:center; display:block; text-decoration:none; color:inherit;" href="' . esc_url( $href ) . '">'
            : '<div class="tt-panel" style="text-align:center;">';
        $tag_close = $href !== '' ? '</a>' : '</div>';
        echo $tag_open;
        echo '<div style="font-size:var(--tt-fs-xl); font-weight:700; color:var(--tt-primary);">' . esc_html( $value ) . '</div>';
        echo '<div style="font-size:var(--tt-fs-xs); color:var(--tt-muted); text-transform:uppercase; letter-spacing:0.04em;">' . esc_html( $label ) . '</div>';
        echo $tag_close;
    }

    private static function formatNumber( float $val, int $decimals = 1 ): string {
        return number_format_i18n( $val, $decimals );
    }

    /** "12.3 min" — observed average session length. */
    private static function formatMinutes( float $minutes ): string {
        /* translators: %s is a number of minutes */
        return sprintf( __( '%s min', 'talenttrack' ), number_format_i18n( $minutes, 1 ) );
    }

    /** Total observed minutes rendered as hours: "4.2 h". */
    private static function formatHours( int $minutes ): string {
        /* translators: %s is a number of hours */
        return sprintf( __( '%s h', 'talenttrack' ), number_format_i18n( $minutes / 60, 1 ) );
    }

    /**
     * Human label for a tracked feature target. Frontend `?tt_view=`
     * slugs and wp-admin `tt-…` page slugs are both kebab/prefixed; this
     * humanises them for the "Top features used" table without a full
     * slug→label registry (a verbose-but-readable fallback).
     */
    private static function featureLabel( string $target ): string {
        $t = preg_replace( '/^tt-/', '', $target );
        $t = str_replace( [ '-', '_' ], ' ', (string) $t );
        $t = trim( $t );
        if ( $t === '' ) return $target;
        return ucfirst( $t );
    }
}
