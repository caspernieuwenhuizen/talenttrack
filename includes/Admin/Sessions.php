<?php
namespace TT\Admin;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Sessions {
    public static function init() {
        add_action( 'admin_post_tt_save_session', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_session', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page() {
        $action = $_GET['action'] ?? 'list'; $id = absint( $_GET['id'] ?? 0 );
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $id ); return; }
        global $wpdb; $p = $wpdb->prefix;
        $sessions = $wpdb->get_results( "SELECT s.*, t.name AS team_name, u.display_name AS coach_name FROM {$p}tt_sessions s LEFT JOIN {$p}tt_teams t ON s.team_id=t.id LEFT JOIN {$wpdb->users} u ON s.coach_id=u.ID ORDER BY s.session_date DESC LIMIT 50" );
        ?>
        <div class="wrap">
            <h1>Training Sessions <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-sessions&action=new' ) ); ?>" class="page-title-action">Add New</a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th>Date</th><th>Title</th><th>Team</th><th>Coach</th><th>Location</th><th>Actions</th></tr></thead><tbody>
            <?php if ( empty( $sessions ) ) : ?><tr><td colspan="6">No sessions.</td></tr>
            <?php else : foreach ( $sessions as $s ) : ?>
                <tr><td><?php echo esc_html( $s->session_date ); ?></td><td><?php echo esc_html( $s->title ); ?></td><td><?php echo esc_html( $s->team_name ?: '—' ); ?></td><td><?php echo esc_html( $s->coach_name ?: '—' ); ?></td><td><?php echo esc_html( $s->location ?: '—' ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-sessions&action=edit&id={$s->id}" ) ); ?>">Edit</a> | <a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_session&id={$s->id}" ), 'tt_del_sess_' . $s->id ); ?>" onclick="return confirm('Delete?')" style="color:#b32d2e;">Delete</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( $id = 0 ) {
        global $wpdb; $p = $wpdb->prefix;
        $session = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_sessions WHERE id=%d", $id ) ) : null;
        $teams = Helpers::get_teams();
        $att_statuses = Helpers::get_lookup_names( 'attendance_status' );
        $attendance = [];
        if ( $session ) { foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_attendance WHERE session_id=%d", $session->id ) ) as $r ) $attendance[ $r->player_id ] = $r; }
        $team_id = $session->team_id ?? 0;
        $players = $team_id ? Helpers::get_players( $team_id ) : Helpers::get_players();
        ?>
        <div class="wrap">
            <h1><?php echo $session ? 'Edit Session' : 'New Session'; ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_session', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_session" />
                <?php if ( $session ) : ?><input type="hidden" name="id" value="<?php echo (int) $session->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th>Title *</th><td><input type="text" name="title" value="<?php echo esc_attr( $session->title ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th>Date *</th><td><input type="date" name="session_date" value="<?php echo esc_attr( $session->session_date ?? current_time( 'Y-m-d' ) ); ?>" required /></td></tr>
                    <tr><th>Team</th><td><select name="team_id"><option value="0">— All —</option>
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $team_id, $t->id ); ?>><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Location</th><td><input type="text" name="location" value="<?php echo esc_attr( $session->location ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( $session->notes ?? '' ); ?></textarea></td></tr>
                </table>
                <?php if ( ! empty( $players ) ) : ?>
                <h3>Attendance</h3>
                <table class="widefat striped" style="max-width:600px;"><thead><tr><th>Player</th><th>Status</th><th>Notes</th></tr></thead><tbody>
                <?php foreach ( $players as $pl ) : $att = $attendance[ $pl->id ] ?? null; ?>
                    <tr><td><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></td>
                        <td><select name="att[<?php echo (int) $pl->id; ?>][status]">
                            <?php foreach ( $att_statuses as $as ) : ?><option value="<?php echo esc_attr( $as ); ?>" <?php selected( $att->status ?? 'Present', $as ); ?>><?php echo esc_html( $as ); ?></option><?php endforeach; ?>
                        </select></td>
                        <td><input type="text" name="att[<?php echo (int) $pl->id; ?>][notes]" value="<?php echo esc_attr( $att->notes ?? '' ); ?>" style="width:200px" /></td></tr>
                <?php endforeach; ?></tbody></table>
                <?php endif; ?>
                <?php submit_button( $session ? 'Update Session' : 'Save Session' ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_session', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix; $id = absint( $_POST['id'] ?? 0 );
        $data = [ 'title' => sanitize_text_field( $_POST['title'] ?? '' ), 'session_date' => sanitize_text_field( $_POST['session_date'] ?? '' ), 'team_id' => absint( $_POST['team_id'] ?? 0 ), 'coach_id' => get_current_user_id(), 'location' => sanitize_text_field( $_POST['location'] ?? '' ), 'notes' => sanitize_textarea_field( $_POST['notes'] ?? '' ) ];
        if ( $id ) $wpdb->update( "{$p}tt_sessions", $data, [ 'id' => $id ] );
        else { $wpdb->insert( "{$p}tt_sessions", $data ); $id = $wpdb->insert_id; }
        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $id ] );
        foreach ( $_POST['att'] ?? [] as $pid => $d ) { $wpdb->insert( "{$p}tt_attendance", [ 'session_id' => $id, 'player_id' => absint( $pid ), 'status' => sanitize_text_field( $d['status'] ?? 'Present' ), 'notes' => sanitize_text_field( $d['notes'] ?? '' ) ] ); }
        wp_safe_redirect( admin_url( 'admin.php?page=tt-sessions&tt_msg=saved' ) ); exit;
    }

    public static function handle_delete() {
        $id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'tt_del_sess_' . $id );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( 'Unauthorized' );
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $id ] );
        $wpdb->delete( "{$p}tt_sessions", [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-sessions&tt_msg=deleted' ) ); exit;
    }
}
