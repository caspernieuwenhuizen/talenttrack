<?php
namespace TT\Modules\Sessions\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

class SessionsPage {
    public static function init(): void {
        add_action( 'admin_post_tt_save_session', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_session', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $id ); return; }
        global $wpdb; $p = $wpdb->prefix;
        $sessions = $wpdb->get_results( "SELECT s.*, t.name AS team_name, u.display_name AS coach_name FROM {$p}tt_sessions s LEFT JOIN {$p}tt_teams t ON s.team_id=t.id LEFT JOIN {$wpdb->users} u ON s.coach_id=u.ID ORDER BY s.session_date DESC LIMIT 50" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Training Sessions', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-sessions&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th></tr></thead><tbody>
            <?php if ( empty( $sessions ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No sessions.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $sessions as $s ) : ?>
                <tr><td><?php echo esc_html( (string) $s->session_date ); ?></td><td><?php echo esc_html( (string) $s->title ); ?></td><td><?php echo esc_html( $s->team_name ?: '—' ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-sessions&action=edit&id={$s->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_session&id={$s->id}" ), 'tt_del_sess_' . $s->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( int $id ): void {
        global $wpdb; $p = $wpdb->prefix;
        $session = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_sessions WHERE id=%d", $id ) ) : null;
        $teams = QueryHelpers::get_teams();
        $att_statuses = QueryHelpers::get_lookup_names( 'attendance_status' );
        $attendance = [];
        if ( $session ) foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_attendance WHERE session_id=%d", $session->id ) ) as $r ) $attendance[ (int) $r->player_id ] = $r;
        $team_id = (int) ( $session->team_id ?? 0 );
        $players = $team_id ? QueryHelpers::get_players( $team_id ) : QueryHelpers::get_players();
        ?>
        <div class="wrap">
            <h1><?php echo $session ? esc_html__( 'Edit Session', 'talenttrack' ) : esc_html__( 'New Session', 'talenttrack' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_session', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_session" />
                <?php if ( $session ) : ?><input type="hidden" name="id" value="<?php echo (int) $session->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</th><td><input type="text" name="title" value="<?php echo esc_attr( $session->title ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</th><td><input type="date" name="session_date" value="<?php echo esc_attr( $session->session_date ?? current_time( 'Y-m-d' ) ); ?>" required /></td></tr>
                    <tr><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><td><select name="team_id"><option value="0"><?php esc_html_e( '— All —', 'talenttrack' ); ?></option>
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $team_id, $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Location', 'talenttrack' ); ?></th><td><input type="text" name="location" value="<?php echo esc_attr( $session->location ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( $session->notes ?? '' ); ?></textarea></td></tr>
                </table>
                <?php if ( ! empty( $players ) ) : ?>
                <h3><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h3>
                <table class="widefat striped" style="max-width:600px;"><thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th></tr></thead><tbody>
                <?php foreach ( $players as $pl ) : $att = $attendance[ (int) $pl->id ] ?? null; ?>
                    <tr><td><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></td>
                        <td><select name="att[<?php echo (int) $pl->id; ?>][status]"><?php foreach ( $att_statuses as $as ) : ?><option value="<?php echo esc_attr( $as ); ?>" <?php selected( $att->status ?? 'Present', $as ); ?>><?php echo esc_html( $as ); ?></option><?php endforeach; ?></select></td>
                        <td><input type="text" name="att[<?php echo (int) $pl->id; ?>][notes]" value="<?php echo esc_attr( $att->notes ?? '' ); ?>" style="width:200px" /></td></tr>
                <?php endforeach; ?></tbody></table>
                <?php endif; ?>
                <?php submit_button( $session ? __( 'Update', 'talenttrack' ) : __( 'Save', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_session', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $data = [
            'title' => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '',
            'session_date' => isset( $_POST['session_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['session_date'] ) ) : '',
            'team_id' => isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0,
            'coach_id' => get_current_user_id(),
            'location' => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['location'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        ];
        if ( $id ) $wpdb->update( "{$p}tt_sessions", $data, [ 'id' => $id ] );
        else { $wpdb->insert( "{$p}tt_sessions", $data ); $id = (int) $wpdb->insert_id; }
        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $id ] );
        $att_raw = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? $_POST['att'] : [];
        foreach ( $att_raw as $pid => $d ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'session_id' => $id, 'player_id' => absint( $pid ),
                'status' => isset( $d['status'] ) ? sanitize_text_field( wp_unslash( (string) $d['status'] ) ) : 'Present',
                'notes' => isset( $d['notes'] ) ? sanitize_text_field( wp_unslash( (string) $d['notes'] ) ) : '',
            ]);
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tt-sessions&tt_msg=saved' ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_del_sess_' . $id );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $id ] );
        $wpdb->delete( "{$p}tt_sessions", [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-sessions&tt_msg=deleted' ) );
        exit;
    }
}
