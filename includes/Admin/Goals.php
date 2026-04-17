<?php
namespace TT\Admin;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Goals {
    public static function init() {
        add_action( 'admin_post_tt_save_goal', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_goal', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page() {
        $action = $_GET['action'] ?? 'list'; $id = absint( $_GET['id'] ?? 0 );
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $id ); return; }
        global $wpdb; $p = $wpdb->prefix;
        $goals = $wpdb->get_results( "SELECT g.*, CONCAT(pl.first_name,' ',pl.last_name) AS player_name, u.display_name AS created_by_name FROM {$p}tt_goals g LEFT JOIN {$p}tt_players pl ON g.player_id=pl.id LEFT JOIN {$wpdb->users} u ON g.created_by=u.ID ORDER BY g.created_at DESC LIMIT 50" );
        ?>
        <div class="wrap">
            <h1>Goals <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-goals&action=new' ) ); ?>" class="page-title-action">Add New</a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th>Player</th><th>Goal</th><th>Priority</th><th>Status</th><th>Due</th><th>Actions</th></tr></thead><tbody>
            <?php if ( empty( $goals ) ) : ?><tr><td colspan="6">No goals.</td></tr>
            <?php else : foreach ( $goals as $g ) : ?>
                <tr><td><?php echo esc_html( $g->player_name ); ?></td><td><strong><?php echo esc_html( $g->title ); ?></strong></td>
                    <td><?php echo esc_html( ucfirst( $g->priority ) ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $g->status ) ) ); ?></td><td><?php echo esc_html( $g->due_date ?: '—' ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-goals&action=edit&id={$g->id}" ) ); ?>">Edit</a> | <a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_goal&id={$g->id}" ), 'tt_del_goal_' . $g->id ); ?>" onclick="return confirm('Delete?')" style="color:#b32d2e;">Delete</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( $id = 0 ) {
        global $wpdb; $p = $wpdb->prefix;
        $goal = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_goals WHERE id=%d", $id ) ) : null;
        $players    = Helpers::get_players();
        $statuses   = Helpers::get_lookup_names( 'goal_status' );
        $priorities = Helpers::get_lookup_names( 'goal_priority' );
        ?>
        <div class="wrap">
            <h1><?php echo $goal ? 'Edit Goal' : 'Add Goal'; ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_goal', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_goal" />
                <?php if ( $goal ) : ?><input type="hidden" name="id" value="<?php echo (int) $goal->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th>Player *</th><td><select name="player_id" required><option value="">— Select —</option>
                        <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>" <?php selected( $goal->player_id ?? 0, $pl->id ); ?>><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Title *</th><td><input type="text" name="title" value="<?php echo esc_attr( $goal->title ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th>Description</th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $goal->description ?? '' ); ?></textarea></td></tr>
                    <tr><th>Priority</th><td><select name="priority">
                        <?php foreach ( $priorities as $pr ) : ?><option value="<?php echo esc_attr( strtolower( $pr ) ); ?>" <?php selected( $goal->priority ?? 'medium', strtolower( $pr ) ); ?>><?php echo esc_html( $pr ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Status</th><td><select name="status">
                        <?php foreach ( $statuses as $st ) : $v = strtolower( str_replace( ' ', '_', $st ) ); ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $goal->status ?? 'pending', $v ); ?>><?php echo esc_html( $st ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Due Date</th><td><input type="date" name="due_date" value="<?php echo esc_attr( $goal->due_date ?? '' ); ?>" /></td></tr>
                </table>
                <?php submit_button( $goal ? 'Update Goal' : 'Add Goal' ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_goal', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix; $id = absint( $_POST['id'] ?? 0 );
        $data = [ 'player_id' => absint( $_POST['player_id'] ?? 0 ), 'title' => sanitize_text_field( $_POST['title'] ?? '' ), 'description' => sanitize_textarea_field( $_POST['description'] ?? '' ), 'status' => sanitize_text_field( $_POST['status'] ?? 'pending' ), 'priority' => sanitize_text_field( $_POST['priority'] ?? 'medium' ), 'due_date' => sanitize_text_field( $_POST['due_date'] ?? '' ) ?: null, 'created_by' => get_current_user_id() ];
        if ( $id ) $wpdb->update( "{$p}tt_goals", $data, [ 'id' => $id ] );
        else $wpdb->insert( "{$p}tt_goals", $data );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-goals&tt_msg=saved' ) ); exit;
    }

    public static function handle_delete() {
        $id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'tt_del_goal_' . $id );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( 'Unauthorized' );
        global $wpdb; $wpdb->delete( $wpdb->prefix . 'tt_goals', [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-goals&tt_msg=deleted' ) ); exit;
    }
}
