<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\TeamPickerComponent;

/**
 * FrontendPeopleManageView — full-CRUD frontend for People.
 *
 * #0019 Sprint 4. People are staff records — head coaches, assistant
 * coaches, physios, team managers, parents, scouts, etc. Distinct
 * from `tt_players`; may or may not be linked to a WP user.
 *
 * Three modes via query string:
 *
 *   ?tt_view=people             — list (FrontendListTable)
 *   ?tt_view=people&action=new  — create form
 *   ?tt_view=people&id=<int>    — edit form (with assignments summary)
 *
 * The list's "Current roles" column shows every active assignment
 * concatenated (`Head coach @ U13 · Physio @ U15`) per Q5 in the
 * Sprint 4 shaping. The edit form has a read-only assignments
 * summary at the bottom and a deep-link into the FunctionalRoles
 * assignments view filtered by this person.
 */
class FrontendPeopleManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New person', 'talenttrack' ) );
            self::renderForm( null );
            return;
        }

        if ( $id > 0 ) {
            $person = ( new PeopleRepository() )->find( $id );
            self::renderHeader( $person ? sprintf( __( 'Edit person — %s', 'talenttrack' ), trim( $person->first_name . ' ' . $person->last_name ) ) : __( 'Person not found', 'talenttrack' ) );
            if ( ! $person ) {
                echo '<p class="tt-notice">' . esc_html__( 'That person no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $person );
            return;
        }

        self::renderHeader( __( 'People', 'talenttrack' ) );
        self::renderList( $user_id, $is_admin );
    }

    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $new_url  = add_query_arg( [ 'tt_view' => 'people', 'action' => 'new' ], $base_url );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New person', 'talenttrack' )
            . '</a></p>';

        $role_type_options = [];
        foreach ( PeopleRepository::ROLE_TYPES as $rt ) {
            $role_type_options[ $rt ] = self::humanRoleTypeLabel( $rt );
        }

        $row_actions = [
            'edit' => [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'people', 'id' => '{id}' ], $base_url ),
            ],
            'delete' => [
                'label'       => __( 'Archive', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'people/{id}',
                'confirm'     => __( 'Archive this person? They can be restored later by a site admin.', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'people',
            'columns' => [
                // #0070 — name links to the person detail; email links to
                // the in-product mail composer so the send is audited.
                'last_name'     => [ 'label' => __( 'Name',          'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'name_link_html' ],
                'email'         => [ 'label' => __( 'Email',         'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'email_link_html' ],
                'role_type'     => [ 'label' => __( 'Type',          'talenttrack' ), 'sortable' => true ],
                'current_roles' => [ 'label' => __( 'Current roles', 'talenttrack' ) ],
            ],
            'filters' => [
                'role_type' => [
                    'type'    => 'select',
                    'label'   => __( 'Type', 'talenttrack' ),
                    'options' => $role_type_options,
                ],
                'team_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Team', 'talenttrack' ),
                    'options' => TeamPickerComponent::filterOptions( $user_id, $is_admin ),
                ],
                'archived' => [
                    'type'    => 'select',
                    'label'   => __( 'Status', 'talenttrack' ),
                    'options' => [
                        'active'   => __( 'Active',   'talenttrack' ),
                        'archived' => __( 'Archived', 'talenttrack' ),
                    ],
                ],
            ],
            'row_actions'  => $row_actions,
            'search'       => [ 'placeholder' => __( 'Search name or email…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'last_name', 'order' => 'asc' ],
            'empty_state'  => __( 'No people match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function renderForm( ?object $person ): void {
        $is_edit   = $person !== null;
        $rest_path = $is_edit ? 'people/' . (int) $person->id : 'people';
        $rest_meth = $is_edit ? 'PUT' : 'POST';
        $form_id   = 'tt-person-form';
        $draft_key = $is_edit ? '' : 'person-form';

        // Eligible WP users for linkage — anyone with `read` (every WP
        // user), excluding users already linked to a player to avoid
        // double-binding.
        global $wpdb;
        $linked_to_player = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE wp_user_id > 0 AND club_id = %d",
            CurrentClub::id()
        ) );
        $wp_users = get_users( [
            'fields'  => [ 'ID', 'display_name', 'user_email' ],
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'exclude' => $linked_to_player,
        ] );

        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>"<?php if ( $draft_key !== '' ) : ?> data-draft-key="<?php echo esc_attr( $draft_key ); ?>"<?php endif; ?>>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-person-first"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-person-first" class="tt-input" name="first_name" required value="<?php echo esc_attr( (string) ( $person->first_name ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-person-last"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-person-last" class="tt-input" name="last_name" required value="<?php echo esc_attr( (string) ( $person->last_name ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-person-email"><?php esc_html_e( 'Email', 'talenttrack' ); ?></label>
                    <input type="email" id="tt-person-email" class="tt-input" name="email" value="<?php echo esc_attr( (string) ( $person->email ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-person-phone"><?php esc_html_e( 'Phone', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-person-phone" class="tt-input" name="phone" value="<?php echo esc_attr( (string) ( $person->phone ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-person-role-type"><?php esc_html_e( 'Type', 'talenttrack' ); ?></label>
                    <select id="tt-person-role-type" class="tt-input" name="role_type">
                        <?php foreach ( PeopleRepository::ROLE_TYPES as $rt ) : ?>
                            <option value="<?php echo esc_attr( $rt ); ?>" <?php selected( (string) ( $person->role_type ?? 'other' ), $rt ); ?>>
                                <?php echo esc_html( self::humanRoleTypeLabel( $rt ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-person-wp-user"><?php esc_html_e( 'WordPress user', 'talenttrack' ); ?></label>
                    <select id="tt-person-wp-user" class="tt-input" name="wp_user_id">
                        <option value="0"><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                        <?php foreach ( $wp_users as $u ) :
                            $label = $u->display_name . ( $u->user_email ? ' (' . $u->user_email . ')' : '' );
                            ?>
                            <option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) ( $person->wp_user_id ?? 0 ), (int) $u->ID ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="tt-field-hint"><?php esc_html_e( 'Optional. Links this person to a login on the site.', 'talenttrack' ); ?></span>
                </div>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update person', 'talenttrack' ) : __( 'Save person', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>

        <?php if ( $is_edit ) self::renderAssignmentsSummary( (int) $person->id ); ?>
        <?php
    }

    /**
     * Read-only summary of this person's current functional role
     * assignments, with a deep-link into the FunctionalRoles
     * assignments view filtered by this person.
     */
    private static function renderAssignmentsSummary( int $person_id ): void {
        $assignments = ( new PeopleRepository() )->getPersonTeams( $person_id );
        $roles_repo  = new FunctionalRolesRepository();

        $manage_url = add_query_arg(
            [ 'tt_view' => 'functional-roles', 'tab' => 'assignments', 'filter' => [ 'person_id' => $person_id ] ],
            remove_query_arg( [ 'action', 'id', 'tab' ] )
        );

        echo '<h3 style="margin:24px 0 12px;">' . esc_html__( 'Current team assignments', 'talenttrack' ) . '</h3>';

        if ( empty( $assignments ) ) {
            echo '<p><em>' . esc_html__( 'Not assigned to any team.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<table class="tt-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Team', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Role', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Start', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'End', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $assignments as $a ) {
            $role = $a->functional_role_id ? $roles_repo->findRole( (int) $a->functional_role_id ) : null;
            $role_label = $role ? (string) $role->label : (string) ( $a->role_in_team ?? '' );
            echo '<tr>';
            echo '<td>' . esc_html( (string) ( $a->team_name ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( $role_label ) . '</td>';
            echo '<td>' . esc_html( (string) ( $a->start_date ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $a->end_date ?? '—' ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:8px;"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $manage_url ) . '">'
            . esc_html__( 'Manage assignments', 'talenttrack' )
            . '</a></p>';
    }

    private static function humanRoleTypeLabel( string $key ): string {
        $map = [
            'coach'           => __( 'Coach',           'talenttrack' ),
            'assistant_coach' => __( 'Assistant coach', 'talenttrack' ),
            'manager'         => __( 'Manager',         'talenttrack' ),
            'staff'           => __( 'Staff',           'talenttrack' ),
            'physio'          => __( 'Physio',          'talenttrack' ),
            'scout'           => __( 'Scout',           'talenttrack' ),
            'parent'          => __( 'Parent',          'talenttrack' ),
            'other'           => __( 'Other',           'talenttrack' ),
        ];
        return $map[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
    }
}
