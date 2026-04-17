<?php
namespace TT\Admin;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Teams {
    public static function init() {
        add_action( 'admin_post_tt_save_team', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_team', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page() {
        $action = $_GET['action'] ?? 'list';
        $id     = absint( $_GET['id'] ?? 0 );
        if ( $action === 'edit' || $action === 'new' ) { self::render_form( $action === 'edit' ? Helpers::get_team( $id ) : null ); return; }
        global $wpdb; $p = $wpdb->prefix;
        $teams = $wpdb->get_results( "SELECT t.*, u.display_name AS coach_name FROM {$p}tt_teams t LEFT JOIN {$wpdb->users} u ON t.head_coach_id = u.ID ORDER BY t.name ASC" );
        ?>
        <div class="wrap">
            <h1>Teams <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-teams&action=new' ) ); ?>" class="page-title-action">Add New</a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th>Name</th><th>Age Group</th><th>Head Coach</th><th>Players</th><th>Actions</th></tr></thead><tbody>
            <?php if ( empty( $teams ) ) : ?><tr><td colspan="5">No teams.</td></tr>
            <?php else : foreach ( $teams as $t ) :
                $pc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_players WHERE team_id=%d AND status='active'", $t->id ) ); ?>
                <tr><td><strong><?php echo esc_html( $t->name ); ?></strong></td><td><?php echo esc_html( $t->age_group ); ?></td><td><?php echo esc_html( $t->coach_name ?: '—' ); ?></td><td><?php echo $pc; ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-teams&action=edit&id={$t->id}" ) ); ?>">Edit</a> | <a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_team&id={$t->id}" ), 'tt_delete_team_' . $t->id ); ?>" onclick="return confirm('Delete?')" style="color:#b32d2e;">Delete</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( $team = null ) {
        $is_edit    = $team !== null;
        $age_groups = Helpers::get_lookup_names( 'age_group' );
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? 'Edit Team' : 'Add Team'; ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_team', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_team" />
                <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $team->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th>Name *</th><td><input type="text" name="name" value="<?php echo esc_attr( $team->name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th>Age Group</th><td><select name="age_group"><option value="">— Select —</option>
                        <?php foreach ( $age_groups as $ag ) : ?><option value="<?php echo esc_attr( $ag ); ?>" <?php selected( $team->age_group ?? '', $ag ); ?>><?php echo esc_html( $ag ); ?></option><?php endforeach; ?>
                    </select></td></tr>
                    <tr><th>Head Coach</th><td><?php wp_dropdown_users( [ 'name' => 'head_coach_id', 'selected' => $team->head_coach_id ?? 0, 'show_option_none' => '— None —', 'option_none_value' => 0 ] ); ?></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( $team->notes ?? '' ); ?></textarea></td></tr>
                </table>
                <?php submit_button( $is_edit ? 'Update Team' : 'Add Team' ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'tt_manage_players' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_team', 'tt_nonce' );
        global $wpdb;
        $data = [ 'name' => sanitize_text_field( $_POST['name'] ?? '' ), 'age_group' => sanitize_text_field( $_POST['age_group'] ?? '' ), 'head_coach_id' => absint( $_POST['head_coach_id'] ?? 0 ), 'notes' => sanitize_textarea_field( $_POST['notes'] ?? '' ) ];
        $id = absint( $_POST['id'] ?? 0 );
        if ( $id ) $wpdb->update( $wpdb->prefix . 'tt_teams', $data, [ 'id' => $id ] );
        else $wpdb->insert( $wpdb->prefix . 'tt_teams', $data );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-teams&tt_msg=saved' ) ); exit;
    }

    public static function handle_delete() {
        $id = absint( $_GET['id'] ?? 0 );
        check_admin_referer( 'tt_delete_team_' . $id );
        if ( ! current_user_can( 'tt_manage_players' ) ) wp_die( 'Unauthorized' );
        global $wpdb; $wpdb->delete( $wpdb->prefix . 'tt_teams', [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-teams&tt_msg=deleted' ) ); exit;
    }
}
