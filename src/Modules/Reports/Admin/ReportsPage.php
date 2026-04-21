<?php
namespace TT\Modules\Reports\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Admin\BackButton;

/**
 * ReportsPage — Analytics → Reports (v2.20.0 tile launcher redesign).
 *
 * Previously a single form with "report type" dropdown buried inside.
 * Now a tile launcher — discoverable entry points to distinct report
 * flavors. Each tile links to its own ?report=X route on this page.
 *
 * Current report types:
 *   - legacy               Pre-2.20 combined form (Player Progress /
 *                          Comparison / Team Averages radar charts).
 *                          Kept for backward compatibility; existing
 *                          muscle memory.
 *   - team_ratings         NEW. Per-team average rating across main
 *                          categories, as a table.
 *   - coach_activity       NEW. Evaluations saved per coach over a
 *                          configurable window.
 *
 * Future report types add tiles here without disturbing what exists.
 */
class ReportsPage {

    public static function init(): void {}

    public static function render_page(): void {
        $report = isset( $_GET['report'] ) ? sanitize_key( (string) $_GET['report'] ) : '';
        if ( $report !== '' ) {
            self::renderReportView( $report );
            return;
        }
        self::renderLauncher();
    }

    /* ═══════════════ Launcher ═══════════════ */

    private static function renderLauncher(): void {
        $tiles = [
            [
                'slug'  => 'legacy',
                'label' => __( 'Player Progress & Radar', 'talenttrack' ),
                'icon'  => 'dashicons-chart-line',
                'desc'  => __( 'Player progress over time, radar comparisons, and team-average radar.', 'talenttrack' ),
                'color' => '#2271b1',
            ],
            [
                'slug'  => 'team_ratings',
                'label' => __( 'Team rating averages', 'talenttrack' ),
                'icon'  => 'dashicons-shield',
                'desc'  => __( 'Average rating per team across all main categories.', 'talenttrack' ),
                'color' => '#00a32a',
            ],
            [
                'slug'  => 'coach_activity',
                'label' => __( 'Coach activity', 'talenttrack' ),
                'icon'  => 'dashicons-welcome-write-blog',
                'desc'  => __( 'Evaluations saved per coach, last 30 / 90 days.', 'talenttrack' ),
                'color' => '#7c3a9e',
            ],
        ];
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Reports', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-docs&topic=reports' ) ); ?>" style="margin-left:12px; font-size:12px; font-weight:normal; color:#2271b1; text-decoration:none;">
                    <?php esc_html_e( '? Help on this topic', 'talenttrack' ); ?>
                </a>
            </h1>
            <p style="color:#666; max-width:760px;">
                <?php esc_html_e( 'Pick a report to run. Each tile opens a dedicated view with its own filters.', 'talenttrack' ); ?>
            </p>

            <style>
            .tt-report-tile {
                display: flex;
                gap: 14px;
                align-items: flex-start;
                background: #fff;
                border: 1px solid #e5e7ea;
                border-radius: 8px;
                padding: 14px 16px;
                text-decoration: none;
                color: #1a1d21;
                transition: transform 200ms ease, box-shadow 200ms ease;
            }
            .tt-report-tile:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
                color: #1a1d21;
            }
            .tt-report-tile-icon {
                width: 40px; height: 40px; border-radius: 8px;
                display: flex; align-items: center; justify-content: center;
                color: #fff; flex-shrink: 0;
            }
            .tt-report-tile-icon .dashicons {
                font-size: 20px; width: 20px; height: 20px; line-height: 20px;
            }
            .tt-report-tile-body { flex: 1; min-width: 0; }
            .tt-report-tile-label { font-weight: 600; font-size: 14px; margin-bottom: 3px; }
            .tt-report-tile-desc { color: #666; font-size: 12px; line-height: 1.4; }
            </style>

            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px; margin-top:20px;">
                <?php foreach ( $tiles as $t ) : ?>
                    <a class="tt-report-tile" href="<?php echo esc_url( add_query_arg( 'report', $t['slug'], admin_url( 'admin.php?page=tt-reports' ) ) ); ?>">
                        <span class="tt-report-tile-icon" style="background:<?php echo esc_attr( $t['color'] ); ?>;">
                            <span class="dashicons <?php echo esc_attr( $t['icon'] ); ?>"></span>
                        </span>
                        <div class="tt-report-tile-body">
                            <div class="tt-report-tile-label"><?php echo esc_html( $t['label'] ); ?></div>
                            <div class="tt-report-tile-desc"><?php echo esc_html( $t['desc'] ); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ═══════════════ Report views ═══════════════ */

    private static function renderReportView( string $report ): void {
        echo '<div class="wrap">';
        BackButton::render( admin_url( 'admin.php?page=tt-reports' ) );
        switch ( $report ) {
            case 'legacy':         self::renderLegacy();       break;
            case 'team_ratings':   self::renderTeamRatings();  break;
            case 'coach_activity': self::renderCoachActivity(); break;
            default:
                echo '<h1>' . esc_html__( 'Reports', 'talenttrack' ) . '</h1>';
                echo '<p>' . esc_html__( 'Unknown report type.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';
    }

    /* ═══════════════ Legacy combined form ═══════════════ */

    private static function renderLegacy(): void {
        global $wpdb; $p = $wpdb->prefix;
        $players   = QueryHelpers::get_players();
        $f_players = array_map( 'absint', (array) ( $_GET['f_players'] ?? [] ) );
        $f_report  = isset( $_GET['f_report'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['f_report'] ) ) : 'progress';
        $run = isset( $_GET['run'] );
        ?>
        <h1><?php esc_html_e( 'Player Progress & Radar', 'talenttrack' ); ?></h1>
        <form method="get">
            <input type="hidden" name="page" value="tt-reports" />
            <input type="hidden" name="report" value="legacy" />
            <input type="hidden" name="run" value="1" />
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Report Type', 'talenttrack' ); ?></th><td><select name="f_report">
                    <option value="progress" <?php selected( $f_report, 'progress' ); ?>><?php esc_html_e( 'Player Progress', 'talenttrack' ); ?></option>
                    <option value="comparison" <?php selected( $f_report, 'comparison' ); ?>><?php esc_html_e( 'Player Comparison (radar)', 'talenttrack' ); ?></option>
                    <option value="team_avg" <?php selected( $f_report, 'team_avg' ); ?>><?php esc_html_e( 'Team Averages (radar)', 'talenttrack' ); ?></option>
                </select></td></tr>
                <tr><th><?php esc_html_e( 'Player(s)', 'talenttrack' ); ?></th><td><select name="f_players[]" multiple style="min-width:300px;height:100px;">
                    <?php foreach ( $players as $pl ) : ?>
                        <option value="<?php echo (int) $pl->id; ?>" <?php echo in_array( (int) $pl->id, $f_players, true ) ? 'selected' : ''; ?>>
                            <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select></td></tr>
            </table>
            <?php submit_button( __( 'Run Report', 'talenttrack' ), 'primary', 'submit', false ); ?>
        </form>
        <?php if ( $run ) self::runLegacy( $f_report, $f_players );
    }

    /** @param int[] $player_ids */
    private static function runLegacy( string $type, array $player_ids ): void {
        global $wpdb; $p = $wpdb->prefix;
        $categories = QueryHelpers::get_categories();
        $max = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $labels = wp_list_pluck( $categories, 'name' );
        $cat_ids = wp_list_pluck( $categories, 'id' );
        echo '<div style="margin-top:30px;padding:20px;background:#fff;border:1px solid #ddd;border-radius:6px;">';
        if ( $type === 'progress' ) {
            echo '<h2>' . esc_html__( 'Player Progress Over Time', 'talenttrack' ) . '</h2>';
            $pids = $player_ids ?: $wpdb->get_col( "SELECT id FROM {$p}tt_players WHERE status='active' LIMIT 10" );
            foreach ( $pids as $pid ) {
                $pl = QueryHelpers::get_player( (int) $pid ); if ( ! $pl ) continue;
                $rd = QueryHelpers::player_radar_datasets( (int) $pid, 5 );
                echo '<h3>' . esc_html( QueryHelpers::player_display_name( $pl ) ) . '</h3>';
                echo ! empty( $rd['datasets'] ) ? '<div style="max-width:350px;">' . QueryHelpers::radar_chart_svg( $rd['labels'], $rd['datasets'], $max ) . '</div>' : '<p>' . esc_html__( 'No data.', 'talenttrack' ) . '</p>';
            }
        } elseif ( $type === 'comparison' ) {
            echo '<h2>' . esc_html__( 'Player Comparison', 'talenttrack' ) . '</h2>';
            if ( count( $player_ids ) < 2 ) { echo '<p>' . esc_html__( 'Select at least 2 players.', 'talenttrack' ) . '</p>'; }
            else {
                $datasets = [];
                foreach ( $player_ids as $pid ) {
                    $pl = QueryHelpers::get_player( (int) $pid ); if ( ! $pl ) continue;
                    $ev = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}tt_evaluations WHERE player_id=%d ORDER BY eval_date DESC LIMIT 1", $pid ) );
                    if ( ! $ev ) continue;
                    $raw = $wpdb->get_results( $wpdb->prepare( "SELECT category_id, rating FROM {$p}tt_eval_ratings WHERE evaluation_id=%d", $ev->id ) );
                    $map = []; foreach ( $raw as $r ) $map[ (int) $r->category_id ] = (float) $r->rating;
                    $vals = []; foreach ( $cat_ids as $cid ) $vals[] = $map[ (int) $cid ] ?? 0;
                    $datasets[] = [ 'label' => QueryHelpers::player_display_name( $pl ), 'values' => $vals ];
                }
                echo ! empty( $datasets ) ? '<div style="max-width:400px;">' . QueryHelpers::radar_chart_svg( $labels, $datasets, $max ) . '</div>' : '<p>' . esc_html__( 'No data.', 'talenttrack' ) . '</p>';
            }
        } elseif ( $type === 'team_avg' ) {
            echo '<h2>' . esc_html__( 'Team Averages', 'talenttrack' ) . '</h2>';
            $teams = QueryHelpers::get_teams(); $datasets = [];
            foreach ( $teams as $team ) {
                $vals = [];
                foreach ( $cat_ids as $cid ) {
                    $avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r JOIN {$p}tt_evaluations e ON r.evaluation_id=e.id JOIN {$p}tt_players pl ON e.player_id=pl.id WHERE pl.team_id=%d AND r.category_id=%d", $team->id, $cid ) );
                    $vals[] = round( (float) $avg, 2 );
                }
                $datasets[] = [ 'label' => (string) $team->name, 'values' => $vals ];
            }
            echo ! empty( $datasets ) ? '<div style="max-width:400px;">' . QueryHelpers::radar_chart_svg( $labels, $datasets, $max ) . '</div>' : '<p>' . esc_html__( 'No data.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';
    }

    /* ═══════════════ Team rating averages ═══════════════ */

    private static function renderTeamRatings(): void {
        global $wpdb; $p = $wpdb->prefix;
        $categories = QueryHelpers::get_categories();
        $teams      = QueryHelpers::get_teams();
        ?>
        <h1><?php esc_html_e( 'Team rating averages', 'talenttrack' ); ?></h1>
        <p style="color:#666; max-width:800px;">
            <?php esc_html_e( 'Average rating per team across main categories, computed from all evaluations of players currently assigned to each team. Archived players and archived evaluations are excluded.', 'talenttrack' ); ?>
        </p>

        <?php if ( empty( $teams ) ) : ?>
            <p><em><?php esc_html_e( 'No teams configured.', 'talenttrack' ); ?></em></p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                    <?php foreach ( $categories as $cat ) : ?>
                        <th><?php echo esc_html( (string) $cat->name ); ?></th>
                    <?php endforeach; ?>
                    <th><?php esc_html_e( 'Evaluations', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $teams as $team ) :
                    if ( isset( $team->archived_at ) && $team->archived_at !== null ) continue;
                    $eval_count = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(e.id)
                         FROM {$p}tt_evaluations e
                         JOIN {$p}tt_players pl ON e.player_id = pl.id
                         WHERE pl.team_id = %d AND pl.archived_at IS NULL AND e.archived_at IS NULL",
                        $team->id
                    ) );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $team->name ); ?></strong><?php if ( ! empty( $team->age_group ) ) : ?> <span style="color:#888;">(<?php echo esc_html( (string) $team->age_group ); ?>)</span><?php endif; ?></td>
                        <?php foreach ( $categories as $cat ) :
                            $avg = $wpdb->get_var( $wpdb->prepare(
                                "SELECT AVG(r.rating)
                                 FROM {$p}tt_eval_ratings r
                                 JOIN {$p}tt_evaluations e ON r.evaluation_id = e.id
                                 JOIN {$p}tt_players pl ON e.player_id = pl.id
                                 WHERE pl.team_id = %d
                                   AND r.category_id = %d
                                   AND pl.archived_at IS NULL
                                   AND e.archived_at IS NULL",
                                $team->id, $cat->id
                            ) );
                            ?>
                            <td style="font-variant-numeric:tabular-nums;">
                                <?php echo $avg === null ? '—' : esc_html( (string) round( (float) $avg, 2 ) ); ?>
                            </td>
                        <?php endforeach; ?>
                        <td style="font-variant-numeric:tabular-nums; color:#666;"><?php echo $eval_count; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ═══════════════ Coach activity ═══════════════ */

    private static function renderCoachActivity(): void {
        global $wpdb; $p = $wpdb->prefix;

        $days = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : 30;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.coach_id,
                    COUNT(*) AS total_in_window,
                    MAX(e.created_at) AS last_eval
             FROM {$p}tt_evaluations e
             WHERE e.created_at >= %s AND e.archived_at IS NULL
             GROUP BY e.coach_id
             ORDER BY total_in_window DESC, last_eval DESC",
            $cutoff
        ) );
        ?>
        <h1><?php esc_html_e( 'Coach activity', 'talenttrack' ); ?></h1>
        <p style="color:#666; max-width:800px;">
            <?php
            /* translators: %d is number of days */
            printf( esc_html__( 'Evaluations saved per coach in the last %d days. Archived evaluations are excluded.', 'talenttrack' ), $days );
            ?>
        </p>

        <form method="get" style="margin:12px 0;">
            <input type="hidden" name="page" value="tt-reports" />
            <input type="hidden" name="report" value="coach_activity" />
            <label style="font-size:13px;">
                <?php esc_html_e( 'Window', 'talenttrack' ); ?>:
                <select name="days" onchange="this.form.submit()">
                    <?php foreach ( [ 7, 30, 90, 180, 365 ] as $d ) : ?>
                        <option value="<?php echo $d; ?>" <?php selected( $days, $d ); ?>>
                            <?php
                            /* translators: %d is number of days */
                            printf( esc_html__( 'Last %d days', 'talenttrack' ), $d );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No evaluations saved in this window.', 'talenttrack' ); ?></em></p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Evaluations', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Last evaluation', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $r ) :
                    $user = get_userdata( (int) $r->coach_id );
                    $name = $user ? $user->display_name : sprintf( '(user %d)', (int) $r->coach_id );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $name ); ?></td>
                        <td style="font-variant-numeric:tabular-nums;"><?php echo (int) $r->total_in_window; ?></td>
                        <td><?php echo esc_html( (string) $r->last_eval ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
