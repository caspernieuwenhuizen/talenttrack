<?php
namespace TT\Modules\Reports\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

class ReportsPage {
    public static function init(): void {}

    public static function render_page(): void {
        global $wpdb; $p = $wpdb->prefix;
        $teams = QueryHelpers::get_teams();
        $players = QueryHelpers::get_players();
        $f_players = array_map( 'absint', $_GET['f_players'] ?? [] );
        $f_report = isset( $_GET['f_report'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['f_report'] ) ) : 'progress';
        $run = isset( $_GET['run'] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Reports', 'talenttrack' ); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="tt-reports" /><input type="hidden" name="run" value="1" />
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Report Type', 'talenttrack' ); ?></th><td><select name="f_report">
                        <option value="progress" <?php selected( $f_report, 'progress' ); ?>><?php esc_html_e( 'Player Progress', 'talenttrack' ); ?></option>
                        <option value="comparison" <?php selected( $f_report, 'comparison' ); ?>><?php esc_html_e( 'Player Comparison', 'talenttrack' ); ?></option>
                        <option value="team_avg" <?php selected( $f_report, 'team_avg' ); ?>><?php esc_html_e( 'Team Averages', 'talenttrack' ); ?></option>
                    </select></td></tr>
                    <tr><th><?php esc_html_e( 'Player(s)', 'talenttrack' ); ?></th><td><select name="f_players[]" multiple style="min-width:300px;height:100px;">
                        <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>" <?php echo in_array( (int) $pl->id, $f_players, true ) ? 'selected' : ''; ?>><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?></select></td></tr>
                </table>
                <?php submit_button( __( 'Run Report', 'talenttrack' ), 'primary', 'submit', false ); ?>
            </form>
            <?php if ( $run ) self::run_report( $f_report, $f_players ); ?>
        </div>
        <?php
    }

    /** @param int[] $player_ids */
    private static function run_report( string $type, array $player_ids ): void {
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
}
