<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;

/**
 * FrontendTeamsManageView — full-CRUD frontend for teams.
 *
 * #0019 Sprint 3 session 3.2. Three modes:
 *
 *   ?tt_view=teams              — list view
 *   ?tt_view=teams&action=new   — create form
 *   ?tt_view=teams&id=<int>     — edit form (with roster + formation placeholder)
 *
 * Saves go through `TeamsRestController` (POST/PUT /teams).
 * Delete = soft-archive via DELETE /teams/{id}.
 *
 * Roster management: the edit form has an "Add player" dropdown
 * (PlayerPicker over the unaffiliated + already-on-this-team pool)
 * that hits POST /teams/{id}/players/{player_id}; the current
 * roster lists rows with a Remove button hitting DELETE
 * /teams/{id}/players/{player_id}. Per Sprint 3 plan Q4 the picker
 * is a plain dropdown (no autocomplete) — adequate for a club's
 * 100–300 player pool.
 *
 * Formation placeholder: a "Coming with #0018" panel with a link to
 * the team-development idea — no functional UI, just the placeholder
 * called for in the spec.
 */
class FrontendTeamsManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New team', 'talenttrack' ) );
            self::renderForm( $user_id, $is_admin, null );
            return;
        }

        if ( $id > 0 ) {
            $team = self::loadTeam( $id );
            self::renderHeader( $team ? sprintf( __( 'Edit team — %s', 'talenttrack' ), (string) $team->name ) : __( 'Team not found', 'talenttrack' ) );
            if ( ! $team ) {
                echo '<p class="tt-notice">' . esc_html__( 'That team no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $user_id, $is_admin, $team );
            return;
        }

        self::renderHeader( __( 'Teams', 'talenttrack' ) );
        self::renderList( $user_id, $is_admin );
    }

    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $new_url  = add_query_arg( [ 'tt_view' => 'teams', 'action' => 'new' ], $base_url );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New team', 'talenttrack' )
            . '</a></p>';

        $age_group_options = [];
        foreach ( QueryHelpers::get_lookup_names( 'age_group' ) as $ag ) {
            $age_group_options[ (string) $ag ] = (string) $ag;
        }

        $row_actions = [
            'edit' => [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'teams', 'id' => '{id}' ], $base_url ),
            ],
            'delete' => [
                'label'       => __( 'Delete', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'teams/{id}',
                'confirm'     => __( 'Delete this team? It will be archived.', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'teams',
            'columns' => [
                'name'         => [ 'label' => __( 'Team',         'talenttrack' ), 'sortable' => true ],
                'age_group'    => [ 'label' => __( 'Age group',    'talenttrack' ), 'sortable' => true ],
                'coach_name'   => [ 'label' => __( 'Head coach',   'talenttrack' ) ],
                'player_count' => [ 'label' => __( 'Players',      'talenttrack' ), 'sortable' => true ],
            ],
            'filters' => [
                'age_group' => [
                    'type'    => 'select',
                    'label'   => __( 'Age group', 'talenttrack' ),
                    'options' => $age_group_options,
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
            'search'       => [ 'placeholder' => __( 'Search team name or age group…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'name', 'order' => 'asc' ],
            'empty_state'  => __( 'No teams match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
    }

    private static function renderForm( int $user_id, bool $is_admin, ?object $team ): void {
        $is_edit   = $team !== null;
        $rest_path = $is_edit ? 'teams/' . (int) $team->id : 'teams';
        $rest_meth = $is_edit ? 'PUT' : 'POST';

        $age_groups = QueryHelpers::get_lookup_names( 'age_group' );

        // Eligible head coaches: any WP user with the tt_edit_evaluations
        // capability (matches the wp-admin team-form pattern).
        $coach_users = get_users( [
            'capability' => 'tt_edit_evaluations',
            'fields'     => [ 'ID', 'display_name' ],
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ] );

        ?>
        <form id="tt-team-form" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>"<?php if ( ! $is_edit ) : ?> data-draft-key="team-form"<?php endif; ?>>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-team-name"><?php esc_html_e( 'Team name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-team-name" class="tt-input" name="name" required value="<?php echo esc_attr( (string) ( $team->name ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-team-age-group"><?php esc_html_e( 'Age group', 'talenttrack' ); ?></label>
                    <select id="tt-team-age-group" class="tt-input" name="age_group">
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $age_groups as $ag ) : ?>
                            <option value="<?php echo esc_attr( (string) $ag ); ?>" <?php selected( (string) ( $team->age_group ?? '' ), (string) $ag ); ?>>
                                <?php echo esc_html( (string) $ag ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-team-coach"><?php esc_html_e( 'Head coach', 'talenttrack' ); ?></label>
                    <select id="tt-team-coach" class="tt-input" name="head_coach_id">
                        <option value="0"><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                        <?php foreach ( $coach_users as $u ) : ?>
                            <option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) ( $team->head_coach_id ?? 0 ), (int) $u->ID ); ?>>
                                <?php echo esc_html( (string) $u->display_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-team-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                <textarea id="tt-team-notes" class="tt-input" name="notes" rows="2"><?php echo esc_textarea( (string) ( $team->notes ?? '' ) ); ?></textarea>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update team', 'talenttrack' ) : __( 'Save team', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>

        <?php if ( $is_edit ) : ?>
            <?php self::renderRosterSection( (int) $team->id ); ?>
            <?php self::renderStaffSection( (int) $team->id ); ?>
            <?php self::renderFormationPlaceholder(); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Roster management — list current players + "Add player" dropdown.
     * Hits the team/player sub-resource endpoints; refresh on success.
     */
    private static function renderRosterSection( int $team_id ): void {
        $current = QueryHelpers::get_players( $team_id );
        // Pool of addable players: anyone NOT currently on this team
        // (admins see all, but the eligible pool is the same — the
        // entry point is what's gated, not the pool).
        $all = QueryHelpers::get_players();
        $current_ids = array_map( static function ( $p ) { return (int) $p->id; }, (array) $current );
        $addable = [];
        foreach ( (array) $all as $p ) {
            if ( in_array( (int) $p->id, $current_ids, true ) ) continue;
            $addable[ (int) $p->id ] = QueryHelpers::player_display_name( $p );
        }
        asort( $addable );
        ?>
        <h3 style="margin:24px 0 12px;"><?php esc_html_e( 'Roster', 'talenttrack' ); ?></h3>
        <div class="tt-team-roster" data-tt-team-roster="1" data-team-id="<?php echo (int) $team_id; ?>">
            <div class="tt-team-roster-add" style="display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
                <select class="tt-input" data-tt-roster-picker="1" style="max-width:300px;">
                    <option value=""><?php esc_html_e( '— Add player —', 'talenttrack' ); ?></option>
                    <?php foreach ( $addable as $pid => $name ) : ?>
                        <option value="<?php echo (int) $pid; ?>"><?php echo esc_html( (string) $name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-roster-add="1"><?php esc_html_e( 'Add', 'talenttrack' ); ?></button>
                <span class="tt-form-msg" data-tt-roster-msg="1"></span>
            </div>

            <?php if ( ! $current ) : ?>
                <p><em><?php esc_html_e( 'No players on this team yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="tt-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( '#', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Foot', 'talenttrack' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $current as $pl ) : ?>
                        <tr data-player-id="<?php echo (int) $pl->id; ?>">
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( [ 'tt_view' => 'players', 'id' => (int) $pl->id ], remove_query_arg( [ 'action', 'id' ] ) ) ); ?>">
                                    <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( (string) ( $pl->jersey_number ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $pl->preferred_foot ?? '' ) ); ?></td>
                            <td>
                                <button type="button" class="tt-list-table-action tt-list-table-action-danger" data-tt-roster-remove="1" data-player-id="<?php echo (int) $pl->id; ?>">
                                    <?php esc_html_e( 'Remove', 'talenttrack' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Read-only "who's on staff" summary for this team — Sprint 4 Q7.
     * Deep-links into the FunctionalRoles assignments view filtered by
     * this team for managing.
     */
    private static function renderStaffSection( int $team_id ): void {
        $grouped = ( new PeopleRepository() )->getTeamStaff( $team_id );
        $manage_url = add_query_arg(
            [ 'tt_view' => 'functional-roles', 'tab' => 'assignments', 'filter' => [ 'team_id' => $team_id ] ],
            remove_query_arg( [ 'action', 'id', 'tab' ] )
        );

        echo '<h3 style="margin:24px 0 12px;">' . esc_html__( 'Staff', 'talenttrack' ) . '</h3>';

        if ( ! $grouped ) {
            echo '<p><em>' . esc_html__( 'No staff assigned to this team yet.', 'talenttrack' ) . '</em></p>';
        } else {
            $roles_repo = new FunctionalRolesRepository();
            echo '<table class="tt-table"><thead><tr>';
            echo '<th>' . esc_html__( 'Role',    'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Person',  'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Email',   'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $grouped as $role_key => $rows ) {
                foreach ( $rows as $row ) {
                    $role = $roles_repo->findRoleByKey( (string) $role_key );
                    $role_label = $role ? (string) $role->label : ucwords( str_replace( '_', ' ', (string) $role_key ) );
                    $person = $row['person'] ?? null;
                    $name   = $person ? trim( ( (string) $person->first_name ) . ' ' . ( (string) $person->last_name ) ) : '';
                    $email  = $person ? (string) ( $person->email ?? '' ) : '';
                    echo '<tr>';
                    echo '<td>' . esc_html( $role_label ) . '</td>';
                    echo '<td>' . esc_html( $name ) . '</td>';
                    echo '<td>' . esc_html( $email ) . '</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table>';
        }

        if ( current_user_can( 'tt_edit_people' ) ) {
            echo '<p style="margin-top:8px;"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $manage_url ) . '">'
                . esc_html__( 'Manage team assignments', 'talenttrack' )
                . '</a></p>';
        }
    }

    private static function renderFormationPlaceholder(): void {
        ?>
        <h3 style="margin:24px 0 12px;"><?php esc_html_e( 'Formation', 'talenttrack' ); ?></h3>
        <div class="tt-panel" style="background:var(--tt-bg-soft); border-style:dashed;">
            <p style="margin:0;">
                <?php
                printf(
                    /* translators: %s: link to the team-development idea */
                    esc_html__( 'Team formation board coming with %s — team development. The placeholder lives here so the layout is ready for it.', 'talenttrack' ),
                    '<a href="https://github.com/caspernieuwenhuizen/talenttrack/blob/main/ideas/0018-epic-team-development.md" target="_blank" rel="noopener">#0018</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    private static function loadTeam( int $id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 't', 'team' );
        /** @var object|null $row */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.* FROM {$p}tt_teams t WHERE t.id = %d AND t.archived_at IS NULL {$scope}",
            $id
        ) );
        return $row ?: null;
    }
}
