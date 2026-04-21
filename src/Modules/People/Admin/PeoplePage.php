<?php
namespace TT\Modules\People\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\People\PeopleRepository;
use TT\Shared\Validation\CustomFieldValidator;
use TT\Shared\Admin\BackButton;

/**
 * PeoplePage — admin UI for the People module.
 *
 * v2.7.2 changes from v2.7.1:
 *   - handleSave(): on create AND update, redirect to the list page with
 *     tt_msg=saved. Matches the pattern used by Evaluations/Players/Teams/etc.
 *     Previously we redirected to the edit page on create, which was
 *     inconsistent with the rest of the admin and made success less visible.
 *   - renderMessages(): simplified to match other modules — a single
 *     "Saved." notice on tt_msg=saved and "Deleted." on tt_msg=deleted.
 */
class PeoplePage {

    private const CAP = 'tt_view_people';

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;

        if ( $action === 'new' ) {
            self::renderForm( null );
            return;
        }
        if ( $action === 'edit' && $id > 0 ) {
            $repo   = new PeopleRepository();
            $person = $repo->find( $id );
            if ( ! $person ) {
                echo '<div class="wrap"><p>' . esc_html__( 'Person not found.', 'talenttrack' ) . '</p></div>';
                return;
            }
            self::renderForm( $person );
            return;
        }

        self::renderList();
    }

    /* ═══════════════ Views ═══════════════ */

    private static function renderList(): void {
        $repo = new PeopleRepository();

        $only_staff = isset( $_GET['only_staff'] ) && $_GET['only_staff'] === '1';
        $search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        $status     = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '';

        // v2.17.0: archive view + bulk actions. PeopleRepository doesn't
        // yet support the archive filter; we fetch via direct SQL for the
        // archive/all views and fall back to the repo for active. A full
        // PeopleRepository refactor is deferred to a future sprint.
        $view        = \TT\Infrastructure\Archive\ArchiveRepository::sanitizeView( $_GET['tt_view'] ?? 'active' );
        $view_clause = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( $view );

        if ( $view === 'active' ) {
            $people = $repo->list( [
                'only_staff' => $only_staff,
                'search'     => $search,
                'status'     => $status !== '' ? $status : null,
            ] );
        } else {
            global $wpdb; $p = $wpdb->prefix;
            $people = $wpdb->get_results( "SELECT pe.*, 0 AS team_count FROM {$p}tt_people pe WHERE {$view_clause} ORDER BY pe.last_name, pe.first_name ASC" );
        }

        $new_url  = admin_url( 'admin.php?page=tt-people&action=new' );
        $base_url = admin_url( 'admin.php?page=tt-people' );
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'People', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a>
                <?php \TT\Shared\Admin\HelpLink::render( 'people-staff' ); ?>
            </h1>

            <?php self::renderMessages(); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderBulkMessage(); ?>

            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="tt-people" />
                <input type="hidden" name="tt_view" value="<?php echo esc_attr( $view ); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name or email', 'talenttrack' ); ?>" />
                <select name="status">
                    <option value=""><?php esc_html_e( 'All statuses', 'talenttrack' ); ?></option>
                    <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'talenttrack' ); ?></option>
                    <option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'talenttrack' ); ?></option>
                </select>
                <label style="margin-left:8px;">
                    <input type="checkbox" name="only_staff" value="1" <?php checked( $only_staff ); ?> />
                    <?php esc_html_e( 'Only staff (assigned to at least one team)', 'talenttrack' ); ?>
                </label>
                <?php submit_button( __( 'Filter', 'talenttrack' ), '', '', false ); ?>
            </form>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderStatusTabs( 'person', $view, $base_url ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::openForm( 'person', $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th class="check-column" style="width:30px;"><?php \TT\Shared\Admin\BulkActionsHelper::selectAllCheckbox(); ?></th>
                        <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Phone', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Teams', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $people ) ) : ?>
                    <tr><td colspan="8"><?php esc_html_e( 'No people yet.', 'talenttrack' ); ?></td></tr>
                <?php else : foreach ( $people as $p ) :
                    $edit_url   = admin_url( 'admin.php?page=tt-people&action=edit&id=' . (int) $p->id );
                    $toggle_to  = $p->status === 'active' ? 'inactive' : 'active';
                    $toggle_lbl = $p->status === 'active' ? __( 'Deactivate', 'talenttrack' ) : __( 'Activate', 'talenttrack' );
                    $team_count = (int) ( $p->team_count ?? 0 );
                    $is_archived = isset( $p->archived_at ) && $p->archived_at !== null;
                    ?>
                    <tr <?php echo $is_archived ? 'style="opacity:0.6;background:#fafafa;"' : ''; ?>>
                        <td class="check-column"><?php \TT\Shared\Admin\BulkActionsHelper::rowCheckbox( (int) $p->id ); ?></td>
                        <td>
                            <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php
                                echo esc_html( trim( $p->first_name . ' ' . $p->last_name ) );
                            ?></a></strong>
                            <?php if ( $is_archived ) : ?><span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#e0e0e0;border-radius:2px;font-size:10px;text-transform:uppercase;color:#555;"><?php esc_html_e( 'Archived', 'talenttrack' ); ?></span><?php endif; ?>
                        </td>
                        <td><?php echo esc_html( self::roleLabel( (string) $p->role_type ) ); ?></td>
                        <td><?php echo $p->email ? '<a href="mailto:' . esc_attr( $p->email ) . '">' . esc_html( $p->email ) . '</a>' : '—'; ?></td>
                        <td><?php echo esc_html( $p->phone ?? '—' ); ?></td>
                        <td>
                            <?php if ( $team_count > 0 ) : ?>
                                <span class="awaiting-mod count-<?php echo (int) $team_count; ?>"><span class="pending-count"><?php echo (int) $team_count; ?></span></span>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $p->status === 'active' ) : ?>
                                <span style="color:#00a32a;">●</span> <?php esc_html_e( 'Active', 'talenttrack' ); ?>
                            <?php else : ?>
                                <span style="color:#999;">●</span> <?php esc_html_e( 'Inactive', 'talenttrack' ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::closeForm(); ?>
        </div>
        <?php
    }

    private static function renderForm( ?object $person ): void {
        $is_edit = $person !== null;
        $data = [
            'first_name' => $person->first_name ?? '',
            'last_name'  => $person->last_name ?? '',
            'email'      => $person->email ?? '',
            'phone'      => $person->phone ?? '',
            'role_type'  => $person->role_type ?? 'other',
            'wp_user_id' => (int) ( $person->wp_user_id ?? 0 ),
            'status'     => $person->status ?? 'active',
        ];
        ?>
        <div class="wrap">
            
            <?php BackButton::render( admin_url( 'admin.php?page=tt-people' ) ); ?>
            <h1><?php echo $is_edit ? esc_html__( 'Edit Person', 'talenttrack' ) : esc_html__( 'Add Person', 'talenttrack' ); ?></h1>

            <?php if ( ! empty( $_GET['tt_cf_error'] ) ) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e( 'The person was saved, but one or more custom fields had invalid values and were not updated.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_person', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_person" />
                <?php if ( $is_edit ) : ?>
                    <input type="hidden" name="id" value="<?php echo (int) $person->id; ?>" />
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="first_name"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label> *</th>
                        <td><input type="text" name="first_name" id="first_name" value="<?php echo esc_attr( $data['first_name'] ); ?>" class="regular-text" required /></td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ), 'first_name' ); ?>
                    <tr>
                        <th><label for="last_name"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label> *</th>
                        <td><input type="text" name="last_name" id="last_name" value="<?php echo esc_attr( $data['last_name'] ); ?>" class="regular-text" required /></td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ), 'last_name' ); ?>
                    <tr>
                        <th><label for="email"><?php esc_html_e( 'Email', 'talenttrack' ); ?></label></th>
                        <td><input type="email" name="email" id="email" value="<?php echo esc_attr( $data['email'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ), 'email' ); ?>
                    <tr>
                        <th><label for="phone"><?php esc_html_e( 'Phone', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="phone" id="phone" value="<?php echo esc_attr( $data['phone'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ), 'phone' ); ?>
                    <tr>
                        <th><label for="role_type"><?php esc_html_e( 'Primary role', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="role_type" id="role_type">
                                <?php foreach ( PeopleRepository::ROLE_TYPES as $r ) : ?>
                                    <option value="<?php echo esc_attr( $r ); ?>" <?php selected( $data['role_type'], $r ); ?>>
                                        <?php echo esc_html( self::roleLabel( $r ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( "Default role if this person isn't assigned to a specific team. Team assignments can override this per-team.", 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ), 'role_type' ); ?>
                    <tr>
                        <th><label for="wp_user_id"><?php esc_html_e( 'Linked WordPress user', 'talenttrack' ); ?></label></th>
                        <td>
                            <?php wp_dropdown_users( [
                                'name'            => 'wp_user_id',
                                'id'              => 'wp_user_id',
                                'selected'        => $data['wp_user_id'],
                                'show_option_none' => __( '— None —', 'talenttrack' ),
                                'option_none_value' => 0,
                            ] ); ?>
                            <p class="description"><?php esc_html_e( 'Optional. Link to a WordPress user account for login access.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ), 'wp_user_id' ); ?>
                    <tr>
                        <th><label for="status"><?php esc_html_e( 'Status', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="active" <?php selected( $data['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'talenttrack' ); ?></option>
                                <option value="inactive" <?php selected( $data['status'], 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'talenttrack' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ), 'status' ); ?>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_PERSON, (int) ( $person->id ?? 0 ) ); ?>
                </table>

                <?php submit_button( $is_edit ? __( 'Update Person', 'talenttrack' ) : __( 'Add Person', 'talenttrack' ) ); ?>
            </form>

            <?php if ( $is_edit ) : ?>
                <?php self::renderPersonTeams( (int) $person->id ); ?>
                <?php if ( class_exists( '\\TT\\Modules\\Authorization\\Admin\\RoleGrantPanel' ) ) : ?>
                    <?php \TT\Modules\Authorization\Admin\RoleGrantPanel::render( (int) $person->id ); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderPersonTeams( int $person_id ): void {
        $repo  = new PeopleRepository();
        $teams = $repo->getPersonTeams( $person_id );
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e( 'Team assignments', 'talenttrack' ); ?></h2>
        <?php if ( empty( $teams ) ) : ?>
            <p><?php esc_html_e( 'This person is not currently assigned to any team.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'To add new assignments, edit the team and use the Staff section there.', 'talenttrack' ); ?></p>
            <table class="widefat striped" style="max-width:700px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'From', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Until', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $teams as $a ) :
                        $role_key = ! empty( $a->functional_role_key )
                            ? (string) $a->functional_role_key
                            : (string) $a->role_in_team;
                        ?>
                        <tr>
                            <td><?php echo esc_html( $a->team_name ); ?> <?php if ( ! empty( $a->age_group ) ) echo '<small style="color:#999;">(' . esc_html( $a->age_group ) . ')</small>'; ?></td>
                            <td><?php echo esc_html( \TT\Modules\Authorization\Admin\FunctionalRolesPage::roleLabel( $role_key ) ); ?></td>
                            <td><?php echo esc_html( $a->start_date ?: '—' ); ?></td>
                            <td><?php echo esc_html( $a->end_date ?: '—' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /* ═══════════════ Handlers ═══════════════ */

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_save_person', 'tt_nonce' );

        $id   = isset( $_POST['id'] ) ? absint( wp_unslash( (string) $_POST['id'] ) ) : 0;
        $data = [
            'first_name' => $_POST['first_name'] ?? '',
            'last_name'  => $_POST['last_name']  ?? '',
            'email'      => $_POST['email']      ?? '',
            'phone'      => $_POST['phone']      ?? '',
            'role_type'  => $_POST['role_type']  ?? 'other',
            'wp_user_id' => $_POST['wp_user_id'] ?? 0,
            'status'     => $_POST['status']     ?? 'active',
        ];

        $repo = new PeopleRepository();

        if ( $id > 0 ) {
            $ok = $repo->update( $id, $data );
        } else {
            $new_id = $repo->create( $data );
            $ok = $new_id !== false;
            if ( $ok ) $id = (int) $new_id;
        }

        if ( ! $ok ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tt-people&tt_msg=error' ) );
            exit;
        }

        // Persist custom field values. Errors accumulate without rolling
        // back the native save; surface them via notice on the edit form.
        $cf_errors = CustomFieldValidator::persistFromPost( CustomFieldsRepository::ENTITY_PERSON, $id, $_POST );

        $redirect_args = [ 'page' => 'tt-people', 'tt_msg' => 'saved' ];
        if ( ! empty( $cf_errors ) ) {
            $redirect_args['tt_cf_error'] = 1;
            $redirect_args['action']      = 'edit';
            $redirect_args['id']          = $id;
        }
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleSetStatus(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $id = isset( $_POST['id'] ) ? absint( wp_unslash( (string) $_POST['id'] ) ) : 0;
        check_admin_referer( 'tt_set_person_status_' . $id, 'tt_nonce' );

        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : '';

        $repo = new PeopleRepository();
        $ok = $id > 0 && $repo->setStatus( $id, $status );

        wp_safe_redirect( admin_url( 'admin.php?page=tt-people&tt_msg=' . ( $ok ? 'saved' : 'error' ) ) );
        exit;
    }

    public static function handleUnassignStaff(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $assignment_id = isset( $_POST['assignment_id'] ) ? absint( wp_unslash( (string) $_POST['assignment_id'] ) ) : 0;
        $team_id       = isset( $_POST['team_id'] ) ? absint( wp_unslash( (string) $_POST['team_id'] ) ) : 0;
        check_admin_referer( 'tt_unassign_staff_' . $assignment_id, 'tt_nonce' );

        $repo = new PeopleRepository();
        $ok = $assignment_id > 0 && $repo->unassign( $assignment_id );

        $redirect = $team_id > 0
            ? admin_url( 'admin.php?page=tt-teams&action=edit&id=' . $team_id . '&tt_msg=' . ( $ok ? 'saved' : 'error' ) )
            : admin_url( 'admin.php?page=tt-teams' );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ═══════════════ Helpers ═══════════════ */

    public static function roleLabel( string $role ): string {
        $map = [
            'coach'           => __( 'Coach', 'talenttrack' ),
            'assistant_coach' => __( 'Assistant coach', 'talenttrack' ),
            'head_coach'      => __( 'Head coach', 'talenttrack' ),
            'manager'         => __( 'Manager', 'talenttrack' ),
            'staff'           => __( 'Staff', 'talenttrack' ),
            'physio'          => __( 'Physio', 'talenttrack' ),
            'scout'           => __( 'Scout', 'talenttrack' ),
            'parent'          => __( 'Parent', 'talenttrack' ),
            'other'           => __( 'Other', 'talenttrack' ),
        ];
        return $map[ $role ] ?? ucwords( str_replace( '_', ' ', $role ) );
    }

    /**
     * Render a success/error notice at the top of the list page.
     * Matches the pattern used by EvaluationsPage, PlayersPage, etc.
     */
    private static function renderMessages(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        if ( $msg === '' ) return;

        if ( $msg === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'talenttrack' ) . '</p></div>';
        } elseif ( $msg === 'deleted' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'talenttrack' ) . '</p></div>';
        } elseif ( $msg === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Something went wrong.', 'talenttrack' ) . '</p></div>';
        }
    }
}
