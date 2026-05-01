<?php
namespace TT\Admin;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Reports {
    public static function init() {
        add_action( 'admin_post_tt_save_report_preset', [ __CLASS__, 'handle_save_preset' ] );
        add_action( 'admin_post_tt_delete_report_preset', [ __CLASS__, 'handle_delete_preset' ] );
    }

    public static function render_page() {
        global $wpdb; $p = $wpdb->prefix;
        $teams   = Helpers::get_teams();
        $players = Helpers::get_players();
        $types   = Helpers::get_eval_types();
        $presets = $wpdb->get_results( "SELECT * FROM {$p}tt_report_presets ORDER BY name ASC" );

        $f_players = array_map( 'absint', $_GET['f_players'] ?? [] );
        $f_teams   = array_map( 'absint', $_GET['f_teams'] ?? [] );
        $f_type    = absint( $_GET['f_type'] ?? 0 );
        $f_from    = sanitize_text_field( $_GET['f_from'] ?? '' );
        $f_to      = sanitize_text_field( $_GET['f_to'] ?? '' );
        $f_report  = sanitize_text_field( $_GET['f_report'] ?? 'progress' );
        $run       = isset( $_GET['run'] );
        ?>
        <div class="wrap">
            <h1>Reports</h1>
            <form method="get">
                <input type="hidden" name="page" value="tt-reports" /><input type="hidden" name="run" value="1" />
                <table class="form-table">
                    <tr><th>Report Type</th><td><select name="f_report">
                        <option value="progress" <?php selected( $f_report, 'progress' ); ?>>Player Progress Over Time</option>
                        <option value="comparison" <?php selected( $f_report, 'comparison' ); ?>>Player Comparison (Radar)</option>
                        <option value="team_avg" <?php selected( $f_report, 'team_avg' ); ?>>Team Averages</option>
                        <option value="composite" <?php selected( $f_report, 'composite' ); ?>>Development Score Ranking</option>
                    </select></td></tr>
                    <tr><th>Player(s)</th><td><select name="f_players[]" multiple style="min-width:300px;height:100px;">
                        <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>" <?php echo in_array( (int) $pl->id, $f_players ) ? 'selected' : ''; ?>><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Team(s)</th><td><select name="f_teams[]" multiple style="min-width:200px;">
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php echo in_array( (int) $t->id, $f_teams ) ? 'selected' : ''; ?>><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Eval Type</th><td><select name="f_type"><option value="0">All</option>
                        <?php foreach ( $types as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $f_type, (int) $t->id ); ?>><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Date Range</th><td><input type="date" name="f_from" value="<?php echo esc_attr( $f_from ); ?>" /> — <input type="date" name="f_to" value="<?php echo esc_attr( $f_to ); ?>" /></td></tr>
                </table>
                <?php submit_button( 'Run Report', 'primary', 'submit', false ); ?>
            </form>
            <?php if ( $run ) self::run_report( $f_report, $f_players, $f_teams, $f_type, $f_from, $f_to ); ?>
            <hr/><h2>Saved Presets</h2>
            <?php if ( empty( $presets ) ) : ?><p>No presets.</p>
            <?php else : ?><table class="widefat striped" style="max-width:500px;"><thead><tr><th>Name</th><th></th></tr></thead><tbody>
                <?php foreach ( $presets as $pr ) : ?><tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-reports&run=1&' . $pr->config ) ); ?>"><?php echo esc_html( $pr->name ); ?></a></td>
                    <td><a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_report_preset&id={$pr->id}" ), 'tt_delpreset_' . $pr->id ); ?>" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td></tr>
                <?php endforeach; ?></tbody></table><?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
                <?php wp_nonce_field( 'tt_save_report_preset', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_report_preset" />
                <input type="hidden" name="config" value="<?php echo esc_attr( $_SERVER['QUERY_STRING'] ?? '' ); ?>" />
                <input type="text" name="name" placeholder="<?php esc_attr_e( 'Preset name', 'talenttrack' ); ?>" required style="width:200px;" />
                <button type="submit" class="button"><?php esc_html_e( 'Save Current Filters', 'talenttrack' ); ?></button>
            </form>
        </div>
        <?php
    }

    private static function run_report( $type, $pids, $tids, $etype, $from, $to ) {
        global $wpdb; $p = $wpdb->prefix;
        $categories = Helpers::get_categories();
        $max = (float) Helpers::get_config( 'rating_max', 5 );
        $labels  = wp_list_pluck( $categories, 'name' );
        $cat_ids = wp_list_pluck( $categories, 'id' );

        echo '<div style="margin-top:30px;padding:20px;background:#fff;border:1px solid #ddd;border-radius:6px;">';
        switch ( $type ) {
            case 'progress':
                echo '<h2>Player Progress Over Time</h2>';
                $pids = $pids ?: $wpdb->get_col( "SELECT id FROM {$p}tt_players WHERE status='active' LIMIT 10" );
                foreach ( $pids as $pid ) { $pl = Helpers::get_player( $pid ); if ( ! $pl ) continue;
                    $rd = Helpers::player_radar_datasets( $pid, 5 );
                    echo '<h3>' . esc_html( Helpers::player_display_name( $pl ) ) . '</h3>';
                    echo ! empty( $rd['datasets'] ) ? '<div style="max-width:350px;">' . Helpers::radar_chart_svg( $rd['labels'], $rd['datasets'], $max ) . '</div>' : '<p>No data.</p>';
                }
                break;

            case 'comparison':
                echo '<h2>Player Comparison</h2>';
                if ( count( $pids ) < 2 ) { echo '<p>Select at least 2 players.</p>'; break; }
                $datasets = [];
                foreach ( $pids as $pid ) { $pl = Helpers::get_player( $pid ); if ( ! $pl ) continue;
                    $ev = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}tt_evaluations WHERE player_id=%d ORDER BY eval_date DESC LIMIT 1", $pid ) );
                    if ( ! $ev ) continue;
                    $raw = $wpdb->get_results( $wpdb->prepare( "SELECT category_id, rating FROM {$p}tt_eval_ratings WHERE evaluation_id=%d", $ev->id ) );
                    $map = []; foreach ( $raw as $r ) $map[ $r->category_id ] = (float) $r->rating;
                    $vals = []; foreach ( $cat_ids as $cid ) $vals[] = $map[ $cid ] ?? 0;
                    $datasets[] = [ 'label' => Helpers::player_display_name( $pl ), 'values' => $vals ];
                }
                echo ! empty( $datasets ) ? '<div style="max-width:400px;">' . Helpers::radar_chart_svg( $labels, $datasets, $max ) . '</div>' : '<p>No data.</p>';
                break;

            case 'team_avg':
                echo '<h2>Team Averages</h2>';
                $teams = Helpers::get_teams(); $datasets = [];
                foreach ( $teams as $team ) { $vals = [];
                    foreach ( $cat_ids as $cid ) {
                        $avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r JOIN {$p}tt_evaluations e ON r.evaluation_id=e.id JOIN {$p}tt_players pl ON e.player_id=pl.id WHERE pl.team_id=%d AND r.category_id=%d", $team->id, $cid ) );
                        $vals[] = round( (float) $avg, 2 );
                    }
                    $datasets[] = [ 'label' => $team->name, 'values' => $vals ];
                }
                echo ! empty( $datasets ) ? '<div style="max-width:400px;">' . Helpers::radar_chart_svg( $labels, $datasets, $max ) . '</div>' : '<p>No data.</p>';
                break;

            case 'composite':
                echo '<h2>Development Score Ranking</h2>';
                $weights = json_decode( Helpers::get_config( 'composite_weights', '{}' ), true ) ?: [];
                $tw = array_sum( $weights ) ?: 100;
                $all = Helpers::get_players(); $scores = [];
                foreach ( $all as $pl ) { $score = 0;
                    foreach ( $cat_ids as $cid ) {
                        $avg = (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r JOIN {$p}tt_evaluations e ON r.evaluation_id=e.id WHERE e.player_id=%d AND r.category_id=%d", $pl->id, $cid ) );
                        $score += ( $avg / $max ) * ( ( $weights[ $cid ] ?? 25 ) / $tw ) * 100;
                    }
                    $scores[] = [ 'player' => $pl, 'score' => round( $score, 1 ) ];
                }
                usort( $scores, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
                echo '<table class="widefat striped" style="max-width:500px;"><thead><tr><th>#</th><th>Player</th><th>Score</th></tr></thead><tbody>';
                foreach ( $scores as $i => $s ) echo '<tr><td>' . ( $i + 1 ) . '</td><td>' . esc_html( Helpers::player_display_name( $s['player'] ) ) . '</td><td><strong>' . esc_html( $s['score'] ) . '</strong>/100</td></tr>';
                echo '</tbody></table>';
                break;
        }
        echo '</div>';
    }

    public static function handle_save_preset() {
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_report_preset', 'tt_nonce' );
        global $wpdb; $wpdb->insert( $wpdb->prefix . 'tt_report_presets', [ 'name' => sanitize_text_field( $_POST['name'] ?? '' ), 'config' => sanitize_text_field( $_POST['config'] ?? '' ), 'created_by' => get_current_user_id() ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-reports&tt_msg=saved' ) ); exit;
    }

    public static function handle_delete_preset() {
        $id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'tt_delpreset_' . $id );
        global $wpdb; $wpdb->delete( $wpdb->prefix . 'tt_report_presets', [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-reports&tt_msg=deleted' ) ); exit;
    }
}
