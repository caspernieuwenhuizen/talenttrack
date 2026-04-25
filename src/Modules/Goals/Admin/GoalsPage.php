<?php
namespace TT\Modules\Goals\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Validation\CustomFieldValidator;
use TT\Shared\Admin\BackButton;

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

        // v2.17.0: archive view filter + bulk actions.
        $view        = \TT\Infrastructure\Archive\ArchiveRepository::sanitizeView( $_GET['tt_view'] ?? 'active' );
        $view_clause = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( $view );

        $scope = QueryHelpers::apply_demo_scope( 'g', 'goal' );
        $goals = $wpdb->get_results( "SELECT g.*, CONCAT(pl.first_name,' ',pl.last_name) AS player_name FROM {$p}tt_goals g LEFT JOIN {$p}tt_players pl ON g.player_id=pl.id WHERE g.{$view_clause} {$scope} ORDER BY g.created_at DESC LIMIT 50" );
        $base_url = admin_url( 'admin.php?page=tt-goals' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Goals', 'talenttrack' ); ?><?php if ( current_user_can( 'tt_edit_goals' ) ) : ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-goals&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a><?php endif; ?> <?php \TT\Shared\Admin\HelpLink::render( 'goals' ); ?></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderBulkMessage(); ?>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderStatusTabs( 'goal', $view, $base_url ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::openForm( 'goal', $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>

            <table class="widefat striped tt-table-sortable"><thead><tr>
                <th class="check-column" style="width:30px;" data-tt-sort="off"><?php \TT\Shared\Admin\BulkActionsHelper::selectAllCheckbox(); ?></th>
                <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Goal', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><th data-tt-sort="off"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th></tr></thead><tbody>
            <?php if ( empty( $goals ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No goals.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $goals as $g ) :
                $is_archived = $g->archived_at !== null;
                ?>
                <tr <?php echo $is_archived ? 'style="opacity:0.6;background:#fafafa;"' : ''; ?>>
                    <td class="check-column"><?php \TT\Shared\Admin\BulkActionsHelper::rowCheckbox( (int) $g->id ); ?></td>
                    <td><?php
                        $goal_player_name = (string) ( $g->player_name ?? '' );
                        $goal_player_id   = (int) ( $g->player_id ?? 0 );
                        if ( $goal_player_name !== '' && $goal_player_id > 0 && current_user_can( 'tt_view_players' ) ) {
                            echo '<a href="' . esc_url( admin_url( 'admin.php?page=tt-players&action=edit&id=' . $goal_player_id ) ) . '">'
                                . esc_html( $goal_player_name ) . '</a>';
                        } else {
                            echo esc_html( $goal_player_name !== '' ? $goal_player_name : '—' );
                        }
                    ?></td>
                    <td><strong><?php echo esc_html( (string) $g->title ); ?></strong>
                        <?php if ( $is_archived ) : ?><span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#e0e0e0;border-radius:2px;font-size:10px;text-transform:uppercase;color:#555;"><?php esc_html_e( 'Archived', 'talenttrack' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $g->status ) ) ); ?></td>
                    <td><?php if ( current_user_can( 'tt_edit_goals' ) ) : ?><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-goals&action=edit&id={$g->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_goal&id={$g->id}" ), 'tt_del_goal_' . $g->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a><?php else : ?><span style="color:#999;">—</span><?php endif; ?></td></tr>
            <?php endforeach; endif; ?></tbody></table>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::closeForm(); ?>
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
            
            <?php BackButton::render( admin_url( 'admin.php?page=tt-goals' ) ); ?>
            <h1><?php echo $goal ? esc_html__( 'Edit Goal', 'talenttrack' ) : esc_html__( 'Add Goal', 'talenttrack' ); ?></h1>

            <?php if ( ! empty( $_GET['tt_cf_error'] ) ) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e( 'The goal was saved, but one or more custom fields had invalid values and were not updated.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

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
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_GOAL, (int) ( $goal->id ?? 0 ), 'player_id' ); ?>
                    <tr><th><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</th><td><input type="text" name="title" value="<?php echo esc_attr( $goal->title ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_GOAL, (int) ( $goal->id ?? 0 ), 'title' ); ?>
                    <tr><th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $goal->description ?? '' ); ?></textarea></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_GOAL, (int) ( $goal->id ?? 0 ), 'description' ); ?>
                    <tr><th><?php esc_html_e( 'Priority', 'talenttrack' ); ?></th><td><select name="priority">
                        <?php foreach ( $priorities as $pr ) : ?><option value="<?php echo esc_attr( strtolower( $pr ) ); ?>" <?php selected( $goal->priority ?? 'medium', strtolower( $pr ) ); ?>><?php echo esc_html( $pr ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_GOAL, (int) ( $goal->id ?? 0 ), 'priority' ); ?>
                    <tr><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><td><select name="status">
                        <?php foreach ( $statuses as $st ) : $v = strtolower( str_replace( ' ', '_', $st ) ); ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $goal->status ?? 'pending', $v ); ?>><?php echo esc_html( $st ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_GOAL, (int) ( $goal->id ?? 0 ), 'status' ); ?>
                    <tr><th><?php esc_html_e( 'Due Date', 'talenttrack' ); ?></th><td><input type="date" name="due_date" value="<?php echo esc_attr( $goal->due_date ?? '' ); ?>" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_GOAL, (int) ( $goal->id ?? 0 ), 'due_date' ); ?>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_GOAL, (int) ( $goal->id ?? 0 ) ); ?>
                </table>
                <?php submit_button( $goal ? __( 'Update', 'talenttrack' ) : __( 'Add', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'tt_edit_goals' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
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
            // v2.11.0: previous versions didn't capture insert_id here, which
            // meant any post-save integration keyed on the goal ID (e.g.
            // custom fields, audit log) silently failed for new goals.
            if ( $ok !== false ) $id = (int) $wpdb->insert_id;
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

        // Persist custom field values. Native save already succeeded; any
        // validation errors on custom fields are surfaced via notice but
        // don't undo the save.
        $cf_errors = CustomFieldValidator::persistFromPost( CustomFieldsRepository::ENTITY_GOAL, $id, $_POST );
        $redirect_args = [ 'page' => 'tt-goals', 'tt_msg' => 'saved' ];
        if ( ! empty( $cf_errors ) ) {
            $redirect_args['tt_cf_error'] = 1;
            $redirect_args['action']      = 'edit';
            $redirect_args['id']          = $id;
        }
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_del_goal_' . $id );
        if ( ! current_user_can( 'tt_edit_goals' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
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
