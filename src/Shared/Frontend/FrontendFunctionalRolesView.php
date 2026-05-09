<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\PlayerPickerComponent;
use TT\Shared\Frontend\Components\TeamPickerComponent;

/**
 * FrontendFunctionalRolesView — Functional Roles tile destination.
 *
 * #0019 Sprint 4. One tile, two tabs (Q1 in Sprint 4 shaping):
 *
 *   ?tt_view=functional-roles                      — Assignments list (default)
 *   ?tt_view=functional-roles&tab=types            — Role types CRUD
 *   ?tt_view=functional-roles&tab=assignments      — Assignments list (explicit)
 *   ?tt_view=functional-roles&action=new           — New assignment form
 *   ?tt_view=functional-roles&tab=types&type_id=N  — Edit role type
 *
 * Tabs shown only to users with the relevant capability:
 *   - Roles   → tt_manage_functional_roles
 *   - Assignments → tt_view_people OR tt_edit_people
 *
 * Reorder uses up/down arrow buttons (Q2) — no DragReorder; works
 * on every viewport.
 */
class FrontendFunctionalRolesView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        // v3.85.5 — Functional roles is a Standard-tier feature per
        // FeatureMap. Free-tier installs run on the legacy WP role
        // model alone; functional-role assignment / mapping requires
        // an upgrade.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'functional_roles' )
        ) {
            self::renderHeader( __( 'Functional roles', 'talenttrack' ) );
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Functional roles', 'talenttrack' ), 'standard' );
            return;
        }

        $can_manage_types = current_user_can( 'tt_manage_functional_roles' );
        $can_view_assign  = current_user_can( 'tt_view_people' ) || current_user_can( 'tt_edit_people' );
        if ( ! $can_manage_types && ! $can_view_assign ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        $tab    = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : ( $can_view_assign ? 'assignments' : 'types' );
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['type_id'] ) ? absint( $_GET['type_id'] ) : 0;

        // v3.92.1 — breadcrumb. The view has two tabs and an action;
        // chain reflects the most-current state.
        $fr_label = __( 'Functional roles', 'talenttrack' );
        if ( $tab === 'assignments' && $action === 'new' ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'New assignment', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'functional-roles', $fr_label, [ 'tab' => 'assignments' ] ) ]
            );
        } elseif ( $tab === 'types' && $id > 0 ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Edit role', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'functional-roles', $fr_label, [ 'tab' => 'types' ] ) ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $fr_label );
        }

        if ( $tab === 'assignments' && $action === 'new' ) {
            self::renderHeader( __( 'New assignment', 'talenttrack' ) );
            self::renderAssignmentForm( $user_id, $is_admin );
            return;
        }

        if ( $tab === 'types' && $id > 0 ) {
            $role = ( new FunctionalRolesRepository() )->findRole( $id );
            $role_label = $role ? ( \TT\Infrastructure\Query\LabelTranslator::functionalRoleLabel( (string) ( $role->role_key ?? '' ), (int) $role->id ) ?? (string) $role->label ) : '';
            self::renderHeader( $role ? sprintf( __( 'Edit role type — %s', 'talenttrack' ), $role_label ) : __( 'Role type not found', 'talenttrack' ) );
            if ( ! $role ) {
                echo '<p class="tt-notice">' . esc_html__( 'That role type no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderRoleTypeForm( $role );
            return;
        }

        if ( $tab === 'types' && $action === 'new' ) {
            self::renderHeader( __( 'New role type', 'talenttrack' ) );
            self::renderRoleTypeForm( null );
            return;
        }

        self::renderHeader( __( 'Functional roles', 'talenttrack' ) );
        echo '<p class="tt-meta" style="margin:0 0 var(--tt-sp-3, 12px); color: var(--tt-muted, #5b6e75);">';
        echo esc_html__( 'Per-team staff assignments — head coach, assistant, manager, physio. A user can hold many at once. Different from academy-wide ', 'talenttrack' );
        $auth_url = admin_url( 'admin.php?page=tt-roles' );
        echo '<a href="' . esc_url( $auth_url ) . '">' . esc_html__( 'Roles & rights', 'talenttrack' ) . '</a>.';
        echo '</p>';
        self::renderTabs( $tab, $can_manage_types, $can_view_assign );

        if ( $tab === 'types' ) {
            self::renderRoleTypesList();
        } else {
            self::renderAssignmentsList( $user_id, $is_admin );
        }
    }

    private static function renderTabs( string $tab, bool $can_types, bool $can_assignments ): void {
        $base = remove_query_arg( [ 'action', 'id', 'type_id', 'tab' ] );
        echo '<div class="tt-tabs" style="margin-bottom:var(--tt-sp-4);">';
        if ( $can_assignments ) {
            $href = add_query_arg( [ 'tt_view' => 'functional-roles', 'tab' => 'assignments' ], $base );
            $cls  = $tab === 'assignments' ? ' tt-tab-active' : '';
            echo '<a class="tt-tab' . $cls . '" href="' . esc_url( $href ) . '">' . esc_html__( 'Assignments', 'talenttrack' ) . '</a>';
        }
        if ( $can_types ) {
            $href = add_query_arg( [ 'tt_view' => 'functional-roles', 'tab' => 'types' ], $base );
            $cls  = $tab === 'types' ? ' tt-tab-active' : '';
            echo '<a class="tt-tab' . $cls . '" href="' . esc_url( $href ) . '">' . esc_html__( 'Role types', 'talenttrack' ) . '</a>';
        }
        echo '</div>';
    }

    // Role types tab

    private static function renderRoleTypesList(): void {
        $base    = remove_query_arg( [ 'action', 'type_id' ] );
        $new_url = add_query_arg( [ 'tt_view' => 'functional-roles', 'tab' => 'types', 'action' => 'new' ], $base );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New role type', 'talenttrack' )
            . '</a></p>';

        $roles = ( new FunctionalRolesRepository() )->listRoles();
        if ( ! $roles ) {
            echo '<p><em>' . esc_html__( 'No role types defined yet.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<div class="tt-fnrole-types" data-tt-fnrole-types="1">';
        echo '<table class="tt-table"><thead><tr>';
        echo '<th></th>';
        echo '<th>' . esc_html__( 'Label',       'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Key',         'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Assignments', 'talenttrack' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        $last_idx = count( $roles ) - 1;
        foreach ( $roles as $idx => $role ) {
            $edit_url = add_query_arg( [ 'tt_view' => 'functional-roles', 'tab' => 'types', 'type_id' => (int) $role->id ], $base );
            $is_first = $idx === 0;
            $is_last  = $idx === $last_idx;
            ?>
            <tr data-role-id="<?php echo (int) $role->id; ?>">
                <td style="white-space:nowrap;">
                    <button type="button" class="tt-list-table-action" data-tt-fnrole-move="up"   <?php disabled( $is_first ); ?>>↑</button>
                    <button type="button" class="tt-list-table-action" data-tt-fnrole-move="down" <?php disabled( $is_last  ); ?>>↓</button>
                </td>
                <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( \TT\Infrastructure\Query\LabelTranslator::functionalRoleLabel( (string) ( $role->role_key ?? '' ), (int) $role->id ) ?? (string) $role->label ); ?></a>
                    <?php if ( ! empty( $role->is_system ) ) : ?>
                        <span class="tt-badge" style="margin-left:6px; padding:1px 6px; background:var(--tt-bg-soft); border:1px solid var(--tt-line); border-radius:999px; font-size:var(--tt-fs-xs); color:var(--tt-muted);">
                            <?php esc_html_e( 'system', 'talenttrack' ); ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><code><?php echo esc_html( (string) $role->role_key ); ?></code></td>
                <td>
                    <?php
                    // #0077 M7 — empty-state CTA: if a role has zero
                    // assignments, surface a quick path to add one
                    // instead of a bare "0".
                    $count = (int) ( $role->assignment_count ?? 0 );
                    if ( $count > 0 ) {
                        echo (int) $count;
                    } else {
                        $assign_url = add_query_arg(
                            [ 'tt_view' => 'functional-roles', 'tab' => 'assignments', 'action' => 'new', 'role_id' => (int) $role->id ],
                            $base
                        );
                        echo '<a class="tt-list-table-action" href="' . esc_url( $assign_url ) . '">'
                            . esc_html__( '+ Assign', 'talenttrack' )
                            . '</a>';
                    }
                    ?>
                </td>
                <td>
                    <a class="tt-list-table-action" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                    <?php if ( empty( $role->is_system ) && (int) ( $role->assignment_count ?? 0 ) === 0 ) : ?>
                        <button type="button" class="tt-list-table-action tt-list-table-action-danger" data-tt-fnrole-delete="<?php echo (int) $role->id; ?>"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
        echo '</tbody></table>';
        echo '<p class="tt-form-msg" data-tt-fnrole-msg="1" style="margin-top:8px;"></p>';
        echo '</div>';
    }

    private static function renderRoleTypeForm( ?object $role ): void {
        $is_edit   = $role !== null;
        $rest_path = $is_edit ? 'functional-roles/' . (int) $role->id : 'functional-roles';
        $rest_meth = $is_edit ? 'PUT' : 'POST';

        ?>
        <form id="tt-fnrole-form" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>">
            <?php if ( ! $is_edit ) : ?>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-fnrole-key"><?php esc_html_e( 'Key', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-fnrole-key" class="tt-input" name="role_key" required pattern="[a-z0-9_]+" placeholder="head_coach" />
                    <span class="tt-field-hint"><?php esc_html_e( 'Lowercase, digits, and underscores only. Cannot be changed later.', 'talenttrack' ); ?></span>
                </div>
            <?php else : ?>
                <div class="tt-field">
                    <label class="tt-field-label"><?php esc_html_e( 'Key', 'talenttrack' ); ?></label>
                    <p><code><?php echo esc_html( (string) $role->role_key ); ?></code></p>
                </div>
            <?php endif; ?>
            <div class="tt-field">
                <label class="tt-field-label tt-field-required" for="tt-fnrole-label"><?php esc_html_e( 'Label', 'talenttrack' ); ?></label>
                <input type="text" id="tt-fnrole-label" class="tt-input" name="label" required value="<?php echo esc_attr( (string) ( $role->label ?? '' ) ); ?>" />
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-fnrole-desc"><?php esc_html_e( 'Description', 'talenttrack' ); ?></label>
                <textarea id="tt-fnrole-desc" class="tt-input" name="description" rows="3"><?php echo esc_textarea( (string) ( $role->description ?? '' ) ); ?></textarea>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update role type', 'talenttrack' ) : __( 'Save role type', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'tt_view' => 'functional-roles', 'tab' => 'types' ], remove_query_arg( [ 'action', 'type_id' ] ) ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    // Assignments tab

    private static function renderAssignmentsList( int $user_id, bool $is_admin ): void {
        $base    = remove_query_arg( [ 'action', 'id', 'type_id' ] );
        $new_url = add_query_arg( [ 'tt_view' => 'functional-roles', 'tab' => 'assignments', 'action' => 'new' ], $base );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New assignment', 'talenttrack' )
            . '</a></p>';

        $role_options = [];
        foreach ( ( new FunctionalRolesRepository() )->listRoles() as $r ) {
            $role_options[ (int) $r->id ] = (string) $r->label;
        }

        $row_actions = [
            'delete' => [
                'label'       => __( 'Unassign', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'functional-roles/assignments/{id}',
                'confirm'     => __( 'Remove this assignment?', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'functional-roles/assignments',
            'columns' => [
                'team_name'   => [ 'label' => __( 'Team',   'talenttrack' ), 'sortable' => true ],
                'role'        => [ 'label' => __( 'Role',   'talenttrack' ), 'sortable' => true ],
                'person_name' => [ 'label' => __( 'Person', 'talenttrack' ), 'sortable' => true ],
                'start_date'  => [ 'label' => __( 'Start',  'talenttrack' ), 'sortable' => true, 'render' => 'date' ],
            ],
            'filters' => [
                'team_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Team', 'talenttrack' ),
                    'options' => TeamPickerComponent::filterOptions( $user_id, $is_admin ),
                ],
                'functional_role_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Role', 'talenttrack' ),
                    'options' => $role_options,
                ],
            ],
            'row_actions'  => $row_actions,
            'search'       => [ 'placeholder' => __( 'Search team, person, role…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'team_name', 'order' => 'asc' ],
            'empty_state'  => __( 'No assignments match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function renderAssignmentForm( int $user_id, bool $is_admin ): void {
        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $roles = ( new FunctionalRolesRepository() )->listRoles();
        $people = ( new PeopleRepository() )->list( [ 'status' => 'active' ] );

        $preselected_team   = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $preselected_person = isset( $_GET['person_id'] ) ? absint( $_GET['person_id'] ) : 0;

        ?>
        <form id="tt-fnrole-assignment-form" class="tt-ajax-form" data-rest-path="functional-roles/assignments" data-rest-method="POST" data-redirect-after-save="list">
            <div class="tt-grid tt-grid-2">
                <?php echo TeamPickerComponent::render( [
                    'name'     => 'team_id',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'required' => true,
                    'teams'    => $teams,
                    'selected' => $preselected_team,
                ] ); ?>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-fnrole-assign-role"><?php esc_html_e( 'Role', 'talenttrack' ); ?></label>
                    <select id="tt-fnrole-assign-role" class="tt-input" name="functional_role_id" required>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $roles as $r ) :
                            $r_translated = \TT\Infrastructure\Query\LabelTranslator::functionalRoleLabel( (string) ( $r->role_key ?? '' ), (int) $r->id );
                            $r_label      = $r_translated !== null ? $r_translated : (string) $r->label;
                            ?>
                            <option value="<?php echo (int) $r->id; ?>"><?php echo esc_html( $r_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-fnrole-assign-person"><?php esc_html_e( 'Person', 'talenttrack' ); ?></label>
                    <select id="tt-fnrole-assign-person" class="tt-input" name="person_id" required>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $people as $p ) :
                            $name = trim( ( (string) $p->first_name ) . ' ' . ( (string) $p->last_name ) );
                            ?>
                            <option value="<?php echo (int) $p->id; ?>" <?php selected( $preselected_person, (int) $p->id ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php echo DateInputComponent::render( [
                    'name'  => 'start_date',
                    'label' => __( 'Start date', 'talenttrack' ),
                    'value' => '',
                ] ); ?>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save assignment', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'tt_view' => 'functional-roles', 'tab' => 'assignments' ], remove_query_arg( [ 'action', 'id', 'team_id', 'person_id' ] ) ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }
}
