<?php
namespace TT\Admin;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Evaluations {
    public static function init() {
        add_action( 'admin_post_tt_save_evaluation', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_evaluation', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page() {
        $action = $_GET['action'] ?? 'list';
        $id     = absint( $_GET['id'] ?? 0 );
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $action === 'edit' ? $id : 0 ); return; }
        if ( $action === 'view' && $id ) { self::render_view( $id ); return; }
        self::render_list();
    }

    private static function render_list() {
        global $wpdb; $p = $wpdb->prefix;
        $evals = $wpdb->get_results(
            "SELECT e.*, lt.name AS type_name, CONCAT(pl.first_name,' ',pl.last_name) AS player_name, u.display_name AS coach_name
             FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id AND lt.lookup_type='eval_type'
             LEFT JOIN {$p}tt_players pl ON e.player_id=pl.id
             LEFT JOIN {$wpdb->users} u ON e.coach_id=u.ID
             ORDER BY e.eval_date DESC LIMIT 50"
        );
        ?>
        <div class="wrap">
            <h1>Evaluations <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations&action=new' ) ); ?>" class="page-title-action">Add New</a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th>Date</th><th>Player</th><th>Type</th><th>Coach</th><th>Opponent</th><th>Actions</th></tr></thead><tbody>
            <?php if ( empty( $evals ) ) : ?><tr><td colspan="6">No evaluations.</td></tr>
            <?php else : foreach ( $evals as $ev ) : ?>
                <tr><td><?php echo esc_html( $ev->eval_date ); ?></td><td><?php echo esc_html( $ev->player_name ); ?></td>
                    <td><?php echo esc_html( $ev->type_name ?: '—' ); ?></td><td><?php echo esc_html( $ev->coach_name ); ?></td>
                    <td><?php echo esc_html( $ev->opponent ?: '—' ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=view&id={$ev->id}" ) ); ?>">View</a> |
                        <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=edit&id={$ev->id}" ) ); ?>">Edit</a> |
                        <a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_evaluation&id={$ev->id}" ), 'tt_del_eval_' . $ev->id ); ?>" onclick="return confirm('Delete?')" style="color:#b32d2e;">Delete</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( $eval_id = 0 ) {
        $eval = $eval_id ? Helpers::get_evaluation( $eval_id ) : null;
        $players    = Helpers::get_players();
        $categories = Helpers::get_categories();
        $types      = Helpers::get_eval_types();
        $rmin  = (float) Helpers::get_config( 'rating_min', 1 );
        $rmax  = (float) Helpers::get_config( 'rating_max', 5 );
        $rstep = (float) Helpers::get_config( 'rating_step', 0.5 );
        $existing = [];
        if ( $eval && ! empty( $eval->ratings ) ) {
            foreach ( $eval->ratings as $r ) $existing[ $r->category_id ] = (float) $r->rating;
        }

        // Build JSON map of which types require match details
        $type_meta = [];
        foreach ( $types as $t ) {
            $m = Helpers::lookup_meta( $t );
            $type_meta[ $t->id ] = ! empty( $m['requires_match_details'] ) ? 1 : 0;
        }
        ?>
        <div class="wrap">
            <h1><?php echo $eval ? 'Edit Evaluation' : 'New Evaluation'; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations' ) ); ?>" class="page-title-action">← Back</a></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_evaluation', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_evaluation" />
                <?php if ( $eval ) : ?><input type="hidden" name="id" value="<?php echo (int) $eval->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th>Player *</th><td><select name="player_id" required><option value="">— Select —</option>
                        <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>" <?php selected( $eval->player_id ?? 0, $pl->id ); ?>><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Evaluation Type *</th><td><select name="eval_type_id" id="tt_eval_type" required>
                        <option value="">— Select —</option>
                        <?php foreach ( $types as $t ) : ?>
                            <option value="<?php echo (int) $t->id; ?>" data-match="<?php echo $type_meta[ $t->id ]; ?>" <?php selected( $eval->eval_type_id ?? 0, $t->id ); ?>><?php echo esc_html( $t->name ); ?></option>
                        <?php endforeach; ?></select></td></tr>
                    <tr><th>Date *</th><td><input type="date" name="eval_date" value="<?php echo esc_attr( $eval->eval_date ?? current_time( 'Y-m-d' ) ); ?>" required /></td></tr>
                </table>

                <table class="form-table" id="tt-match-fields" style="display:none;">
                    <tr><th>Opponent</th><td><input type="text" name="opponent" value="<?php echo esc_attr( $eval->opponent ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th>Competition</th><td><input type="text" name="competition" value="<?php echo esc_attr( $eval->competition ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th>Result</th><td><input type="text" name="match_result" value="<?php echo esc_attr( $eval->match_result ?? '' ); ?>" placeholder="e.g. 2-1" style="width:80px" /></td></tr>
                    <tr><th>Home / Away</th><td><select name="home_away"><option value="">—</option>
                        <option value="home" <?php selected( $eval->home_away ?? '', 'home' ); ?>>Home</option>
                        <option value="away" <?php selected( $eval->home_away ?? '', 'away' ); ?>>Away</option></select></td></tr>
                    <tr><th>Minutes Played</th><td><input type="number" name="minutes_played" value="<?php echo esc_attr( $eval->minutes_played ?? '' ); ?>" min="0" max="120" /></td></tr>
                </table>

                <table class="form-table">
                    <tr><th>Ratings</th><td>
                        <?php if ( empty( $categories ) ) : ?>
                            <p style="color:#b32d2e;"><strong>No evaluation categories configured.</strong> Please add categories in <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-config&tab=eval_categories' ) ); ?>">Configuration → Evaluation Categories</a>.</p>
                        <?php else : foreach ( $categories as $cat ) : ?>
                            <p><label><strong><?php echo esc_html( $cat->name ); ?></strong></label><br/>
                            <input type="number" name="ratings[<?php echo (int) $cat->id; ?>]" min="<?php echo $rmin; ?>" max="<?php echo $rmax; ?>" step="<?php echo $rstep; ?>"
                                   value="<?php echo esc_attr( $existing[ $cat->id ] ?? '' ); ?>" style="width:80px" required />
                            <span class="description">(<?php echo $rmin; ?>–<?php echo $rmax; ?>)</span></p>
                        <?php endforeach; endif; ?>
                    </td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="4" class="large-text"><?php echo esc_textarea( $eval->notes ?? '' ); ?></textarea></td></tr>
                </table>
                <?php submit_button( $eval ? 'Update Evaluation' : 'Save Evaluation' ); ?>
            </form>
        </div>
        <script>
        jQuery(function($){
            var typeMeta = <?php echo wp_json_encode( $type_meta ); ?>;
            function toggleMatch(){
                var val = $('#tt_eval_type').val();
                $('#tt-match-fields').toggle( val && typeMeta[val] == 1 );
            }
            $('#tt_eval_type').on('change', toggleMatch);
            toggleMatch();
        });
        </script>
        <?php
    }

    private static function render_view( $id ) {
        $eval = Helpers::get_evaluation( $id );
        if ( ! $eval ) { echo '<div class="wrap"><p>Not found.</p></div>'; return; }
        $player = Helpers::get_player( $eval->player_id );
        $max    = (float) Helpers::get_config( 'rating_max', 5 );
        $labels = []; $values = [];
        if ( ! empty( $eval->ratings ) ) {
            foreach ( $eval->ratings as $r ) { $labels[] = $r->category_name; $values[] = (float) $r->rating; }
        }
        ?>
        <div class="wrap">
            <h1>Evaluation — <?php echo esc_html( $player ? Helpers::player_display_name( $player ) : 'Unknown' ); ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=edit&id={$id}" ) ); ?>" class="page-title-action">Edit</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations' ) ); ?>" class="page-title-action">← Back</a></h1>
            <div style="display:flex;gap:30px;flex-wrap:wrap;margin-top:20px;">
                <div style="flex:1;min-width:260px;">
                    <table class="form-table">
                        <tr><th>Date</th><td><?php echo esc_html( $eval->eval_date ); ?></td></tr>
                        <tr><th>Type</th><td><?php echo esc_html( $eval->type_name ?: '—' ); ?></td></tr>
                        <?php if ( $eval->requires_match_details ) : ?>
                            <tr><th>Opponent</th><td><?php echo esc_html( $eval->opponent ?: '—' ); ?></td></tr>
                            <tr><th>Competition</th><td><?php echo esc_html( $eval->competition ?: '—' ); ?></td></tr>
                            <tr><th>Result</th><td><?php echo esc_html( $eval->match_result ?: '—' ); ?></td></tr>
                            <tr><th>Home/Away</th><td><?php echo esc_html( $eval->home_away ?: '—' ); ?></td></tr>
                            <tr><th>Minutes</th><td><?php echo $eval->minutes_played ? $eval->minutes_played . "'" : '—'; ?></td></tr>
                        <?php endif; ?>
                        <tr><th>Notes</th><td><?php echo nl2br( esc_html( $eval->notes ?: '—' ) ); ?></td></tr>
                    </table>
                    <h3>Ratings</h3>
                    <table class="widefat"><tbody>
                    <?php foreach ( $eval->ratings ?? [] as $r ) : ?>
                        <tr><td><strong><?php echo esc_html( $r->category_name ); ?></strong></td><td><?php echo esc_html( $r->rating ); ?> / <?php echo $max; ?></td></tr>
                    <?php endforeach; ?></tbody></table>
                </div>
                <div style="flex:1;min-width:320px;">
                    <h3>Radar Chart</h3>
                    <?php echo ! empty( $labels ) ? Helpers::radar_chart_svg( $labels, [ [ 'label' => $eval->eval_date, 'values' => $values ] ], $max ) : '<p>No data.</p>'; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_evaluation', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $header = [
            'player_id' => absint( $_POST['player_id'] ?? 0 ), 'coach_id' => get_current_user_id(),
            'eval_type_id' => absint( $_POST['eval_type_id'] ?? 0 ), 'eval_date' => sanitize_text_field( $_POST['eval_date'] ?? '' ),
            'notes' => sanitize_textarea_field( $_POST['notes'] ?? '' ), 'opponent' => sanitize_text_field( $_POST['opponent'] ?? '' ),
            'competition' => sanitize_text_field( $_POST['competition'] ?? '' ), 'match_result' => sanitize_text_field( $_POST['match_result'] ?? '' ),
            'home_away' => sanitize_text_field( $_POST['home_away'] ?? '' ), 'minutes_played' => absint( $_POST['minutes_played'] ?? 0 ) ?: null,
        ];
        if ( $id ) {
            $wpdb->update( "{$p}tt_evaluations", $header, [ 'id' => $id ] );
            $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
        } else {
            do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );
            $wpdb->insert( "{$p}tt_evaluations", $header );
            $id = $wpdb->insert_id;
        }
        $rmin = (float) Helpers::get_config( 'rating_min', 1 );
        $rmax = (float) Helpers::get_config( 'rating_max', 5 );
        foreach ( $_POST['ratings'] ?? [] as $cat_id => $rating ) {
            $rating = max( $rmin, min( $rmax, floatval( $rating ) ) );
            $wpdb->insert( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id, 'category_id' => absint( $cat_id ), 'rating' => $rating ] );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tt-evaluations&tt_msg=saved' ) ); exit;
    }

    public static function handle_delete() {
        $id = absint( $_GET['id'] ?? 0 );
        check_admin_referer( 'tt_del_eval_' . $id );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( 'Unauthorized' );
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
        $wpdb->delete( "{$p}tt_evaluations", [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-evaluations&tt_msg=deleted' ) ); exit;
    }
}
