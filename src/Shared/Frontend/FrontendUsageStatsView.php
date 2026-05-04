<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Usage\UsageTracker;

/**
 * FrontendUsageStatsView — Application KPIs dashboard (#0012 v2).
 *
 * Renamed from "Usage statistics" because what's shown here are
 * application-level KPIs the head of academy uses to judge whether
 * the tool is being used as designed: active users, evaluations
 * per coach, attendance %, top-5 most-evaluated players, goal
 * completion rate, plus a logins counter as the activity sanity
 * check. Period is selectable (30 / 60 / 90 days, default 30).
 *
 * The route slug stays `usage-stats` so existing URLs / tiles /
 * docs links don't break.
 */
class FrontendUsageStatsView extends FrontendViewBase {

    /** Period choices in days. */
    private const PERIODS = [ 30, 60, 90 ];

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Application KPIs', 'talenttrack' ) );
        self::renderHeader( __( 'Application KPIs', 'talenttrack' ) );

        $days = self::periodFromQuery();

        wp_enqueue_script( 'tt-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );

        $kpis = [
            'active_users'  => UsageTracker::uniqueActiveUsers( $days ),
            'logins'        => UsageTracker::countEvents( 'login', $days ),
            'evals_per_coach'  => self::evaluationsPerActiveCoach( $days ),
            'attendance_pct'   => self::attendancePercentage( $days ),
            'goal_completion'  => self::goalCompletionRate( $days ),
        ];
        $top_players = self::topEvaluatedPlayers( $days, 5 );

        $dau   = UsageTracker::dailyActiveUsers( $days );
        $roles = UsageTracker::activeByRole( $days );

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
            self::kpi( __( 'Active users',          'talenttrack' ), (string) $kpis['active_users'],     '' );
            self::kpi( __( 'Logins',                'talenttrack' ), (string) $kpis['logins'],           '' );
            self::kpi( __( 'Evaluations / coach',   'talenttrack' ), self::formatNumber( $kpis['evals_per_coach'], 1 ),     '' );
            self::kpi( __( 'Attendance %',          'talenttrack' ), self::formatPct( $kpis['attendance_pct'] ),            '' );
            self::kpi( __( 'Goal completion %',     'talenttrack' ), self::formatPct( $kpis['goal_completion'] ),           '' );
            self::renderTopPlayersTile( $top_players );
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
        // Player distribution by team — answers "where are my players?"
        // for the head of academy in one glance. Counts active players
        // grouped by team; un-rostered active players collapse into
        // "Unassigned". Most-populous team first.
        $distribution = self::playersByTeam();
        $total_active = array_sum( array_map( static fn( $r ) => (int) $r['count'], $distribution ) );
        ?>
        <div class="tt-panel">
            <h3 class="tt-panel-title"><?php esc_html_e( 'Players by team', 'talenttrack' ); ?></h3>
            <p style="color:var(--tt-muted); font-size:13px; margin: 0 0 8px;">
                <?php echo esc_html( sprintf(
                    /* translators: %d active players */
                    __( '%d active players across the academy.', 'talenttrack' ),
                    (int) $total_active
                ) ); ?>
            </p>
            <?php if ( empty( $distribution ) ) : ?>
                <p><em><?php esc_html_e( 'No active players yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="tt-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Players', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Share', 'talenttrack' ); ?></th>
                        <th><span class="screen-reader-text"><?php esc_html_e( 'Distribution', 'talenttrack' ); ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $distribution as $row ) :
                        $count = (int) $row['count'];
                        $pct = $total_active > 0 ? ( $count / $total_active ) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html( (string) $row['team_name'] ); ?></td>
                            <td style="text-align:right; font-variant-numeric: tabular-nums;"><?php echo (int) $count; ?></td>
                            <td style="text-align:right; font-variant-numeric: tabular-nums;"><?php echo esc_html( number_format_i18n( $pct, 0 ) ); ?>%</td>
                            <td style="width: 30%;">
                                <div style="background:#eef0f2; border-radius:4px; height:10px; overflow:hidden;">
                                    <div style="background:#0b3d2e; height:100%; width:<?php echo esc_attr( (string) $pct ); ?>%;"></div>
                                </div>
                            </td>
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

    /**
     * @param array<int, array{name: string, count: int}> $players
     */
    private static function renderTopPlayersTile( array $players ): void {
        echo '<div class="tt-panel" style="text-align:left;">';
        echo '<div style="font-size:var(--tt-fs-xs); color:var(--tt-muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:6px;">'
            . esc_html__( 'Top 5 most-evaluated', 'talenttrack' ) . '</div>';
        if ( empty( $players ) ) {
            echo '<div style="font-size:13px; color:var(--tt-muted); font-style:italic;">'
                . esc_html__( 'No evaluations in this window.', 'talenttrack' ) . '</div>';
        } else {
            echo '<ol style="margin:0; padding-left:18px; font-size:13px; line-height:1.6;">';
            foreach ( $players as $p ) {
                printf(
                    '<li>%s <span style="color:var(--tt-muted);">(%d)</span></li>',
                    esc_html( $p['name'] ),
                    (int) $p['count']
                );
            }
            echo '</ol>';
        }
        echo '</div>';
    }

    private static function formatNumber( float $val, int $decimals = 1 ): string {
        return number_format_i18n( $val, $decimals );
    }

    private static function formatPct( float $val ): string {
        return number_format_i18n( $val, 1 ) . '%';
    }

    /**
     * Evaluations created in the period divided by the count of distinct
     * coaches who created at least one. Returns 0.0 when no coaches
     * were active.
     */
    private static function evaluationsPerActiveCoach( int $days ): float {
        global $wpdb;
        $p     = $wpdb->prefix;
        $cutoff = gmdate( 'Y-m-d 00:00:00', time() - $days * DAY_IN_SECONDS );

        $rows = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS evals, COUNT(DISTINCT coach_id) AS coaches
              FROM {$p}tt_evaluations
              WHERE created_at >= %s AND archived_at IS NULL",
            $cutoff
        ) );
        if ( ! $rows || (int) $rows->coaches === 0 ) return 0.0;
        return round( ( (int) $rows->evals ) / max( 1, (int) $rows->coaches ), 1 );
    }

    /**
     * Average attendance % across all activities in the period —
     * "present" rows divided by all attendance rows on those activities.
     */
    private static function attendancePercentage( int $days ): float {
        global $wpdb;
        $p      = $wpdb->prefix;
        $cutoff = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_rows,
                COUNT(*) AS total_rows
              FROM {$p}tt_attendance a
              INNER JOIN {$p}tt_activities act ON act.id = a.activity_id
              WHERE act.session_date >= %s AND act.archived_at IS NULL",
            $cutoff
        ) );
        if ( ! $row || (int) $row->total_rows === 0 ) return 0.0;
        return round( ( (int) $row->present_rows / (int) $row->total_rows ) * 100, 1 );
    }

    /**
     * % of goals due in the period (or open during it) that reached a
     * "completed" status. Goal status comes from the goal_status lookup
     * — "Completed" is the canonical row.
     */
    private static function goalCompletionRate( int $days ): float {
        global $wpdb;
        $p      = $wpdb->prefix;
        $cutoff = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
                COUNT(*) AS total
              FROM {$p}tt_goals
              WHERE archived_at IS NULL
                AND ( due_date >= %s OR ( due_date IS NULL AND created_at >= %s ) )",
            $cutoff,
            gmdate( 'Y-m-d 00:00:00', time() - $days * DAY_IN_SECONDS )
        ) );
        if ( ! $row || (int) $row->total === 0 ) return 0.0;
        return round( ( (int) $row->completed / (int) $row->total ) * 100, 1 );
    }

    /**
     * @return array<int, array{name: string, count: int}>
     */
    private static function topEvaluatedPlayers( int $days, int $limit = 5 ): array {
        global $wpdb;
        $p      = $wpdb->prefix;
        $cutoff = gmdate( 'Y-m-d 00:00:00', time() - $days * DAY_IN_SECONDS );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, COUNT(e.id) AS eval_count
              FROM {$p}tt_evaluations e
              INNER JOIN {$p}tt_players pl ON pl.id = e.player_id
              WHERE e.created_at >= %s AND e.archived_at IS NULL
              GROUP BY pl.id, pl.first_name, pl.last_name
              ORDER BY eval_count DESC, pl.last_name ASC
              LIMIT %d",
            $cutoff,
            $limit
        ) );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $r ) {
            $name = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
            if ( $name === '' ) $name = '#' . (int) $r->id;
            $out[] = [ 'name' => $name, 'count' => (int) $r->eval_count ];
        }
        return $out;
    }

    /**
     * Active players grouped by team. Un-rostered active players (no
     * team_id, or team_id pointing at a deleted team) collapse into a
     * single "Unassigned" bucket so the head of academy can spot
     * orphans at a glance.
     *
     * @return list<array{team_name:string, count:int}>
     */
    private static function playersByTeam(): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT t.name AS team_name, COUNT(pl.id) AS player_count
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
              WHERE pl.archived_at IS NULL
                AND ( pl.status IS NULL OR pl.status = '' OR pl.status = 'active' )
              GROUP BY t.id, t.name
              ORDER BY player_count DESC, t.name ASC"
        );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $name = trim( (string) ( $r->team_name ?? '' ) );
            if ( $name === '' ) $name = __( 'Unassigned', 'talenttrack' );
            $out[] = [ 'team_name' => $name, 'count' => (int) $r->player_count ];
        }
        return $out;
    }
}
