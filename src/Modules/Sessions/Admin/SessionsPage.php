<?php
namespace TT\Modules\Sessions\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Validation\CustomFieldValidator;

/**
 * SessionsPage — admin CRUD for training sessions.
 *
 * v2.6.2: fail-loud on session save (the primary case where the v1.x
 * tt_attendance.status column mismatch would silently lose data).
 */
class SessionsPage {

    private const TRANSIENT_PREFIX = 'tt_sess_form_state_';

    public static function init(): void {
        add_action( 'admin_post_tt_save_session', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_session', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $id ); return; }
        global $wpdb; $p = $wpdb->prefix;

        // v2.17.0: archive view filter + bulk actions.
        $view        = \TT\Infrastructure\Archive\ArchiveRepository::sanitizeView( $_GET['tt_view'] ?? 'active' );
        $view_clause = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( $view );

        $sessions = $wpdb->get_results( "SELECT s.*, t.name AS team_name, u.display_name AS coach_name FROM {$p}tt_sessions s LEFT JOIN {$p}tt_teams t ON s.team_id=t.id LEFT JOIN {$wpdb->users} u ON s.coach_id=u.ID WHERE s.{$view_clause} ORDER BY s.session_date DESC LIMIT 50" );
        $base_url = admin_url( 'admin.php?page=tt-sessions' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Training Sessions', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-sessions&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderBulkMessage(); ?>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderStatusTabs( 'session', $view, $base_url ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::openForm( 'session', $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>

            <table class="widefat striped"><thead><tr>
                <th class="check-column" style="width:30px;"><?php \TT\Shared\Admin\BulkActionsHelper::selectAllCheckbox(); ?></th>
                <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th></tr></thead><tbody>
            <?php if ( empty( $sessions ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No sessions.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $sessions as $s ) :
                $is_archived = $s->archived_at !== null;
                ?>
                <tr <?php echo $is_archived ? 'style="opacity:0.6;background:#fafafa;"' : ''; ?>>
                    <td class="check-column"><?php \TT\Shared\Admin\BulkActionsHelper::rowCheckbox( (int) $s->id ); ?></td>
                    <td><?php echo esc_html( (string) $s->session_date ); ?></td>
                    <td><?php echo esc_html( (string) $s->title ); ?>
                        <?php if ( $is_archived ) : ?><span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#e0e0e0;border-radius:2px;font-size:10px;text-transform:uppercase;color:#555;"><?php esc_html_e( 'Archived', 'talenttrack' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $s->team_name ?: '—' ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-sessions&action=edit&id={$s->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_session&id={$s->id}" ), 'tt_del_sess_' . $s->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td></tr>
            <?php endforeach; endif; ?></tbody></table>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::closeForm(); ?>
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
        $state = self::popFormState();
        ?>
        <div class="wrap">
            <h1><?php echo $session ? esc_html__( 'Edit Session', 'talenttrack' ) : esc_html__( 'New Session', 'talenttrack' ); ?></h1>

            <?php if ( ! empty( $_GET['tt_cf_error'] ) ) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e( 'The session was saved, but one or more custom fields had invalid values and were not updated.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $state && ! empty( $state['db_error'] ) ) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e( 'The database rejected the save. No session was created.', 'talenttrack' ); ?></strong></p>
                    <p style="font-family:monospace;font-size:12px;"><?php echo esc_html( (string) $state['db_error'] ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_session', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_session" />
                <?php if ( $session ) : ?><input type="hidden" name="id" value="<?php echo (int) $session->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</th><td><input type="text" name="title" value="<?php echo esc_attr( $session->title ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_SESSION, (int) ( $session->id ?? 0 ), 'title' ); ?>
                    <tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</th><td><input type="date" name="session_date" value="<?php echo esc_attr( $session->session_date ?? current_time( 'Y-m-d' ) ); ?>" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_SESSION, (int) ( $session->id ?? 0 ), 'session_date' ); ?>
                    <tr><th><?php esc_html_e( 'Location', 'talenttrack' ); ?></th><td><input type="text" name="location" value="<?php echo esc_attr( $session->location ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_SESSION, (int) ( $session->id ?? 0 ), 'location' ); ?>
                    <tr><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><td><select name="team_id"><option value="0"><?php esc_html_e( '— All —', 'talenttrack' ); ?></option>
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $team_id, $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_SESSION, (int) ( $session->id ?? 0 ), 'team_id' ); ?>
                    <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( $session->notes ?? '' ); ?></textarea></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_SESSION, (int) ( $session->id ?? 0 ), 'notes' ); ?>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_SESSION, (int) ( $session->id ?? 0 ) ); ?>
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

        if ( $id ) {
            $ok = $wpdb->update( "{$p}tt_sessions", $data, [ 'id' => $id ] );
        } else {
            $ok = $wpdb->insert( "{$p}tt_sessions", $data );
            if ( $ok !== false ) $id = (int) $wpdb->insert_id;
        }

        if ( $ok === false ) {
            Logger::error( 'admin.session.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'is_update' => (bool) $id ] );
            self::saveFormState( [ 'db_error' => $wpdb->last_error ?: __( 'Unknown database error.', 'talenttrack' ) ] );
            $back = add_query_arg(
                [ 'page' => 'tt-sessions', 'action' => $id ? 'edit' : 'new', 'id' => $id ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $back );
            exit;
        }

        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $id ] );
        $att_raw = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? $_POST['att'] : [];
        foreach ( $att_raw as $pid => $d ) {
            $ok_att = $wpdb->insert( "{$p}tt_attendance", [
                'session_id' => $id, 'player_id' => absint( $pid ),
                'status' => isset( $d['status'] ) ? sanitize_text_field( wp_unslash( (string) $d['status'] ) ) : 'Present',
                'notes' => isset( $d['notes'] ) ? sanitize_text_field( wp_unslash( (string) $d['notes'] ) ) : '',
            ]);
            if ( $ok_att === false ) {
                Logger::error( 'admin.session.attendance.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'session_id' => $id, 'player_id' => absint( $pid ) ] );
            }
        }

        // Persist custom field values for the session.
        $cf_errors = CustomFieldValidator::persistFromPost( CustomFieldsRepository::ENTITY_SESSION, $id, $_POST );
        $redirect_args = [ 'page' => 'tt-sessions', 'tt_msg' => 'saved' ];
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
        check_admin_referer( 'tt_del_sess_' . $id );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_attendance", [ 'session_id' => $id ] );
        $wpdb->delete( "{$p}tt_sessions", [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-sessions&tt_msg=deleted' ) );
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
