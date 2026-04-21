<?php
namespace TT\Modules\Teams\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\People\Admin\TeamStaffPanel;
use TT\Shared\Validation\CustomFieldValidator;

class TeamsPage {
    public static function init(): void {
        add_action( 'admin_post_tt_save_team', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_team', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'edit' || $action === 'new' ) {
            self::render_form( $action === 'edit' ? QueryHelpers::get_team( $id ) : null );
            return;
        }
        global $wpdb; $p = $wpdb->prefix;

        // v2.17.0: archive view filter + bulk actions.
        $view        = \TT\Infrastructure\Archive\ArchiveRepository::sanitizeView( $_GET['tt_view'] ?? 'active' );
        $view_clause = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( $view );

        $teams = $wpdb->get_results( "SELECT t.* FROM {$p}tt_teams t WHERE t.{$view_clause} ORDER BY t.name ASC" );
        $base_url = admin_url( 'admin.php?page=tt-teams' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Teams', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-teams&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderBulkMessage(); ?>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderStatusTabs( 'team', $view, $base_url ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::openForm( 'team', $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>

            <table class="widefat striped"><thead><tr>
                <th class="check-column" style="width:30px;"><?php \TT\Shared\Admin\BulkActionsHelper::selectAllCheckbox(); ?></th>
                <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Age Group', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Staff', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Players', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
            </tr></thead><tbody>
            <?php if ( empty( $teams ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No teams.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $teams as $t ) :
                $pc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_players WHERE team_id=%d AND status='active'", $t->id ) );
                $sc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_team_people WHERE team_id=%d", $t->id ) );
                $is_archived = $t->archived_at !== null;
                ?>
                <tr <?php echo $is_archived ? 'style="opacity:0.6;background:#fafafa;"' : ''; ?>>
                    <td class="check-column"><?php \TT\Shared\Admin\BulkActionsHelper::rowCheckbox( (int) $t->id ); ?></td>
                    <td><strong><?php echo esc_html( (string) $t->name ); ?></strong>
                        <?php if ( $is_archived ) : ?><span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#e0e0e0;border-radius:2px;font-size:10px;text-transform:uppercase;color:#555;"><?php esc_html_e( 'Archived', 'talenttrack' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( (string) $t->age_group ); ?></td>
                    <td><?php echo (int) $sc; ?></td><td><?php echo (int) $pc; ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-teams&action=edit&id={$t->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_team&id={$t->id}" ), 'tt_delete_team_' . $t->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td>
                </tr>
            <?php endforeach; endif; ?></tbody></table>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::closeForm(); ?>
        </div>
        <?php
    }

    private static function render_form( ?object $team ): void {
        $is_edit    = $team !== null;
        $age_groups = QueryHelpers::get_lookup_names( 'age_group' );
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? esc_html__( 'Edit Team', 'talenttrack' ) : esc_html__( 'Add Team', 'talenttrack' ); ?></h1>
            <?php if ( ! empty( $_GET['tt_cf_error'] ) ) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e( 'The team was saved, but one or more custom fields had invalid values and were not updated.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_team', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_team" />
                <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $team->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Name', 'talenttrack' ); ?> *</th><td><input type="text" name="name" value="<?php echo esc_attr( $team->name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_TEAM, (int) ( $team->id ?? 0 ), 'name' ); ?>
                    <tr><th><?php esc_html_e( 'Age Group', 'talenttrack' ); ?></th><td><select name="age_group"><option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $age_groups as $ag ) : ?><option value="<?php echo esc_attr( $ag ); ?>" <?php selected( $team->age_group ?? '', $ag ); ?>><?php echo esc_html( $ag ); ?></option><?php endforeach; ?>
                    </select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_TEAM, (int) ( $team->id ?? 0 ), 'age_group' ); ?>
                    <tr>
                        <th><?php esc_html_e( 'Head Coach', 'talenttrack' ); ?></th>
                        <td>
                            <?php wp_dropdown_users( [ 'name' => 'head_coach_id', 'selected' => $team->head_coach_id ?? 0, 'show_option_none' => __( '— None —', 'talenttrack' ), 'option_none_value' => 0 ] ); ?>
                            <?php if ( $is_edit ) : ?>
                                <p class="description">
                                    <?php esc_html_e( 'This is the legacy head coach field (kept for display only). As of v2.10.0 it no longer drives permissions — the Staff section below is the source of truth. The head coach from this field was automatically added to the Staff list on upgrade.', 'talenttrack' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_TEAM, (int) ( $team->id ?? 0 ), 'head_coach_id' ); ?>
                    <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( $team->notes ?? '' ); ?></textarea></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_TEAM, (int) ( $team->id ?? 0 ), 'notes' ); ?>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_TEAM, (int) ( $team->id ?? 0 ) ); ?>
                </table>
                <?php submit_button( $is_edit ? __( 'Update Team', 'talenttrack' ) : __( 'Add Team', 'talenttrack' ) ); ?>
            </form>

            <?php if ( $is_edit && $team && class_exists( TeamStaffPanel::class ) ) : ?>
                <?php TeamStaffPanel::render( (int) $team->id ); ?>
                <?php TeamStaffPanel::renderAddForm( (int) $team->id ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save(): void {
        check_admin_referer( 'tt_save_team', 'tt_nonce' );
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        // v2.8.0: entity-scoped auth when editing existing team. Head coach /
        // manager of THIS team can edit it; admins can edit any. Creating a
        // new team requires the base capability.
        if ( $id > 0 ) {
            if ( ! AuthorizationService::canManageTeam( get_current_user_id(), $id ) ) {
                wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
            }
        } else {
            if ( ! current_user_can( 'tt_manage_players' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
            }
        }

        global $wpdb;
        $data = [
            'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
            'age_group' => isset( $_POST['age_group'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['age_group'] ) ) : '',
            'head_coach_id' => isset( $_POST['head_coach_id'] ) ? absint( $_POST['head_coach_id'] ) : 0,
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
        ];
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id ) {
            $wpdb->update( $wpdb->prefix . 'tt_teams', $data, [ 'id' => $id ] );
        } else {
            $wpdb->insert( $wpdb->prefix . 'tt_teams', $data );
            $id = (int) $wpdb->insert_id;
        }

        // Persist any submitted custom field values. Validation errors are
        // collected but not blocking — the native save already succeeded.
        // Fields that fail validation retain their previously-stored value.
        $cf_errors = CustomFieldValidator::persistFromPost( CustomFieldsRepository::ENTITY_TEAM, $id, $_POST );

        $redirect_args = [ 'page' => 'tt-teams', 'tt_msg' => 'saved' ];
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
        check_admin_referer( 'tt_delete_team_' . $id );
        // v2.8.0: delete remains capability-only. Destructive ops should stay
        // with users who have global tt_manage_players; coaches of a team
        // shouldn't be able to delete the team they coach.
        if ( ! current_user_can( 'tt_manage_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        // Also clean up any staff assignments pointing at this team, to avoid orphans.
        $wpdb->delete( $wpdb->prefix . 'tt_team_people', [ 'team_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'tt_teams', [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-teams&tt_msg=deleted' ) );
        exit;
    }
}
