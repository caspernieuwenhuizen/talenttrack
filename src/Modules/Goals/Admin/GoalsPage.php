<?php
namespace TT\Modules\Goals\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * GoalsPage — admin CRUD for goals.
 *
 * v2.6.2: fail-loud on goal save (the v1.x tt_goals table lacked priority
 * column until the v2.6.2 migration ran).
 */
class GoalsPage {

    private const TRANSIENT_PREFIX = 'tt_goal_form_state_';

    public static function init(): void {
        add_action( 'admin_post_tt_save_goal', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_goal', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $id ); return; }
        global $wpdb; $p = $wpdb->prefix;
        $goals = $wpdb->get_results( "SELECT g.*, CONCAT(pl.first_name,' ',pl.last_name) AS player_name FROM {$p}tt_goals g LEFT JOIN {$p}tt_players pl ON g.player_id=pl.id ORDER BY g.created_at DESC LIMIT 50" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Goals', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-goals&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Goal', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th></tr></thead><tbody>
            <?php if ( empty( $goals ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No goals.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $goals as $g ) : ?>
                <tr><td><?php echo esc_html( $g->player_name ?: '—' ); ?></td><td><strong><?php echo esc_html( (string) $g->title ); ?></strong></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $g->status ) ) ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-goals&action=edit&id={$g->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_goal&id={$g->id}" ), 'tt_del_goal_' . $g->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( int $id ): void {
        global $wpdb; $p = $wpdb->prefix;
        $goal = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_goals WHERE id=%d", $id ) ) : null;
        $players = QueryHelpers::get_players();
        $statuses = QueryHelpers::get_lookup_names( 'goal_status' );
        $priorities = QueryHelpers::get_lookup_names( 'goal_priority' );
        $state = self::popFormState();
        ?>
        <div class="wrap">
            <h1><?php echo $goal ? esc_html__( 'Edit Goal', 'talenttrack' ) : esc_html__( 'Add Goal', 'talenttrack' ); ?></h1>

            <?php if ( $state && ! empty( $state['db_error'] ) ) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e( 'The database rejected the save. No goal was created.', 'talenttrack' ); ?></strong></p>
                    <p style="font-family:monospace;font-size:12px;"><?php echo esc_html( (string) $state['db_error'] ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_goal', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_goal" />
                <?php if ( $goal ) : ?><input type="hidden" name="id" value="<?php echo (int) $goal->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?> *</th><td><select name="player_id" required>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>" <?php selected( $goal->player_id ?? 0, $pl->id ); ?>><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</th><td><input type="text" name="title" value="<?php echo esc_attr( $goal->title ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $goal->description ?? '' ); ?></textarea></td></tr>
                    <tr><th><?php esc_html_e( 'Priority', 'talenttrack' ); ?></th><td><select name="priority">
                        <?php foreach ( $priorities as $pr ) : ?><option value="<?php echo esc_attr( strtolower( $pr ) ); ?>" <?php selected( $goal->priority ?? 'medium', strtolower( $pr ) ); ?>><?php echo esc_html( $pr ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><td><select name="status">
                        <?php foreach ( $statuses as $st ) : $v = strtolower( str_replace( ' ', '_', $st ) ); ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $goal->status ?? 'pending', $v ); ?>><?php echo esc_html( $st ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Due Date', 'talenttrack' ); ?></th><td><input type="date" name="due_date" value="<?php echo esc_attr( $goal->due_date ?? '' ); ?>" /></td></tr>
                </table>
                <?php submit_button( $goal ? __( 'Update', 'talenttrack' ) : __( 'Add', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_goal', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $data = [
            'player_id' => isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0,
            'title' => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '',
            'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '',
            'status' => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : 'pending',
            'priority' => isset( $_POST['priority'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['priority'] ) ) : 'medium',
            'due_date' => ! empty( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['due_date'] ) ) : null,
            'created_by' => get_current_user_id(),
        ];

        if ( $id ) {
            $ok = $wpdb->update( "{$p}tt_goals", $data, [ 'id' => $id ] );
        } else {
            $ok = $wpdb->insert( "{$p}tt_goals", $data );
        }

        if ( $ok === false ) {
            Logger::error( 'admin.goal.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'is_update' => (bool) $id ] );
            self::saveFormState( [ 'db_error' => $wpdb->last_error ?: __( 'Unknown database error.', 'talenttrack' ) ] );
            $back = add_query_arg(
                [ 'page' => 'tt-goals', 'action' => $id ? 'edit' : 'new', 'id' => $id ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $back );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-goals&tt_msg=saved' ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_del_goal_' . $id );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_goals', [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-goals&tt_msg=deleted' ) );
        exit;
    }

    private static function saveFormState( array $state ): void {
        set_transient( self::TRANSIENT_PREFIX . get_current_user_id(), $state, 60 );
    }

    private static function popFormState(): ?array {
        $key   = self::TRANSIENT_PREFIX . get_current_user_id();
        $state = get_transient( $key );
        if ( $state === false ) return null;
        delete_transient( $key );
        return is_array( $state ) ? $state : null;
    }
}
