<?php
namespace TT\Modules\Teams\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\People\Admin\TeamStaffPanel;
use TT\Shared\Validation\CustomFieldValidator;
use TT\Shared\Admin\BackButton;

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

        $scope = QueryHelpers::apply_demo_scope( 't', 'team' );
        $teams = $wpdb->get_results( $wpdb->prepare( "SELECT t.* FROM {$p}tt_teams t WHERE t.{$view_clause} AND t.club_id = %d {$scope} ORDER BY t.name ASC", CurrentClub::id() ) );
        $base_url = admin_url( 'admin.php?page=tt-teams' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Teams', 'talenttrack' ); ?><?php if ( current_user_can( 'tt_edit_teams' ) ) : ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-teams&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a><?php endif; ?> <?php \TT\Shared\Admin\HelpLink::render( 'teams-players' ); ?></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderBulkMessage(); ?>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderStatusTabs( 'team', $view, $base_url ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::openForm( 'team', $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>

            <table class="widefat striped tt-table-sortable"><thead><tr>
                <th class="check-column" style="width:30px;" data-tt-sort="off"><?php \TT\Shared\Admin\BulkActionsHelper::selectAllCheckbox(); ?></th>
                <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Age Group', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Head coach', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Assistant coach', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Team manager', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Players', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
            </tr></thead><tbody>
            <?php
            // #0063 — pre-resolve staff via tt_team_people so the table
            // shows head coach / assistant / manager from real staff
            // assignments rather than the legacy tt_teams.head_coach_id
            // column. One repo call per team is fine here (small N).
            $people_repo = new \TT\Infrastructure\People\PeopleRepository();
            $render_staff = function ( int $team_id, string $functional_role_key ) use ( $people_repo ) {
                $rows = $people_repo->getTeamStaff( $team_id );
                $matches = array_values( array_filter( $rows, static function ( $r ) use ( $functional_role_key ) {
                    return ( $r->functional_role_key ?? null ) === $functional_role_key
                        || ( $r->role_in_team ?? null )         === $functional_role_key;
                } ) );
                if ( empty( $matches ) ) { echo '—'; return; }
                $links = [];
                foreach ( $matches as $m ) {
                    $person_id = (int) ( $m->person_id ?? 0 );
                    $name      = trim( ( (string) ( $m->first_name ?? '' ) ) . ' ' . ( (string) ( $m->last_name ?? '' ) ) );
                    if ( $person_id <= 0 || $name === '' ) continue;
                    $links[] = \TT\Shared\Frontend\Components\RecordLink::inline(
                        $name,
                        \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'people', $person_id )
                    );
                }
                echo $links === [] ? '—' : implode( ', ', $links );
            };
            ?>
            <?php if ( empty( $teams ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'No teams.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $teams as $t ) :
                $player_scope = QueryHelpers::apply_demo_scope( 'p', 'player' );
                $pc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_players p WHERE p.team_id=%d AND p.status='active' AND p.club_id = %d {$player_scope}", $t->id, CurrentClub::id() ) );
                $is_archived = $t->archived_at !== null;
                ?>
                <tr <?php echo $is_archived ? 'style="opacity:0.6;background:#fafafa;"' : ''; ?>>
                    <td class="check-column"><?php \TT\Shared\Admin\BulkActionsHelper::rowCheckbox( (int) $t->id ); ?></td>
                    <td><strong><?php
                        // #0063 — name links to frontend team detail.
                        echo \TT\Shared\Frontend\Components\RecordLink::inline(
                            (string) $t->name,
                            \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'teams', (int) $t->id )
                        );
                    ?></strong>
                        <?php if ( $is_archived ) : ?><span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#e0e0e0;border-radius:2px;font-size:10px;text-transform:uppercase;color:#555;"><?php esc_html_e( 'Archived', 'talenttrack' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( (string) $t->age_group ); ?></td>
                    <td><?php $render_staff( (int) $t->id, 'head_coach' ); ?></td>
                    <td><?php $render_staff( (int) $t->id, 'assistant_coach' ); ?></td>
                    <td><?php $render_staff( (int) $t->id, 'team_manager' ); ?></td>
                    <td><?php echo (int) $pc; ?></td>
                    <td><?php if ( current_user_can( 'tt_edit_teams' ) ) : ?><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-teams&action=edit&id={$t->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_team&id={$t->id}" ), 'tt_delete_team_' . $t->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a><?php else : ?><span style="color:#999;">—</span><?php endif; ?></td>
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
            
            <?php BackButton::render( admin_url( 'admin.php?page=tt-teams' ) ); ?>
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
                    <?php
                    // #0062 — Spond integration. Per-team `spond_group_id`
                    // (UUID, not secret) replaces the per-team iCal URL
                    // from #0031. Per-club credentials are managed
                    // separately on the Spond admin overview page.
                    $spond_group_id   = (string) ( $team->spond_group_id ?? '' );
                    $spond_status     = (string) ( $team->spond_last_sync_status ?? '' );
                    $spond_last_at    = (string) ( $team->spond_last_sync_at ?? '' );
                    $spond_message    = (string) ( $team->spond_last_sync_message ?? '' );
                    $spond_has_creds  = \TT\Modules\Spond\CredentialsManager::hasCredentials();
                    $spond_groups     = [];
                    if ( $spond_has_creds ) {
                        $g = \TT\Modules\Spond\SpondClient::fetchGroups();
                        if ( ! empty( $g['ok'] ) ) $spond_groups = (array) $g['groups'];
                    }
                    ?>
                    <tr>
                        <th><?php esc_html_e( 'Spond group', 'talenttrack' ); ?></th>
                        <td>
                            <?php if ( ! $spond_has_creds ) : ?>
                                <p class="description" style="color:#5b6e75;">
                                    <?php
                                    printf(
                                        /* translators: %s: link URL to Spond admin page */
                                        wp_kses(
                                            __( 'Connect a Spond account first on the <a href="%s">Spond integration</a> page, then come back to pick a group.', 'talenttrack' ),
                                            [ 'a' => [ 'href' => [] ] ]
                                        ),
                                        esc_url( admin_url( 'admin.php?page=tt-spond' ) )
                                    );
                                    ?>
                                </p>
                                <input type="hidden" name="spond_group_id" value="<?php echo esc_attr( $spond_group_id ); ?>" />
                            <?php else : ?>
                                <select name="spond_group_id" class="regular-text">
                                    <option value=""><?php esc_html_e( '— Not connected —', 'talenttrack' ); ?></option>
                                    <?php foreach ( $spond_groups as $g ) :
                                        $gid = (string) ( $g['id']   ?? '' );
                                        $gnm = (string) ( $g['name'] ?? '' );
                                        if ( $gid === '' ) continue;
                                        ?>
                                        <option value="<?php echo esc_attr( $gid ); ?>" <?php selected( $spond_group_id, $gid ); ?>>
                                            <?php echo esc_html( $gnm !== '' ? $gnm : $gid ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'Pick the Spond group that matches this team. Sync runs hourly. Leave on "Not connected" to disable.', 'talenttrack' ); ?>
                                </p>
                            <?php endif; ?>
                            <?php if ( $is_edit && $spond_group_id !== '' ) : ?>
                                <p style="margin:6px 0 0;">
                                    <strong><?php esc_html_e( 'Last sync:', 'talenttrack' ); ?></strong>
                                    <?php echo $spond_last_at !== '' ? esc_html( $spond_last_at ) : esc_html__( 'never', 'talenttrack' ); ?>
                                    <?php if ( $spond_status === 'failed' ) : ?>
                                        <span style="display:inline-block;margin-left:6px;padding:1px 8px;background:#fef2f2;color:#b91c1c;border-radius:4px;font-size:11px;">
                                            <?php echo esc_html( $spond_message !== '' ? $spond_message : __( 'failed', 'talenttrack' ) ); ?>
                                        </span>
                                    <?php elseif ( $spond_status === 'ok' ) : ?>
                                        <span style="display:inline-block;margin-left:6px;padding:1px 8px;background:#dcfce7;color:#166534;border-radius:4px;font-size:11px;">
                                            <?php echo esc_html( $spond_message !== '' ? $spond_message : __( 'ok', 'talenttrack' ) ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <button type="button" class="button button-secondary" data-tt-spond-refresh="<?php echo (int) $team->id; ?>" style="margin-left:8px;min-height:32px;">
                                        <?php esc_html_e( 'Refresh now', 'talenttrack' ); ?>
                                    </button>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_TEAM, (int) ( $team->id ?? 0 ) ); ?>
                </table>
                <?php if ( $is_edit && $team ) : ?>
                <script>
                (function(){
                    var btn = document.querySelector('[data-tt-spond-refresh="<?php echo (int) $team->id; ?>"]');
                    if ( ! btn || typeof window.TT === 'undefined' ) return;
                    btn.addEventListener('click', function(){
                        btn.disabled = true;
                        btn.textContent = '<?php echo esc_js( __( 'Syncing…', 'talenttrack' ) ); ?>';
                        var headers = { 'Accept': 'application/json' };
                        if ( window.TT && window.TT.rest && window.TT.rest.nonce ) headers['X-WP-Nonce'] = window.TT.rest.nonce;
                        fetch( ( window.TT.rest.url || '/wp-json/talenttrack/v1/' ) + 'teams/<?php echo (int) $team->id; ?>/spond/sync', {
                            method: 'POST',
                            headers: headers,
                            credentials: 'same-origin'
                        }).then(function(r){ return r.json(); }).then(function(){
                            window.location.reload();
                        }).catch(function(){
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js( __( 'Refresh now', 'talenttrack' ) ); ?>';
                        });
                    });
                })();
                </script>
                <?php endif; ?>
                <?php submit_button( $is_edit ? __( 'Update Team', 'talenttrack' ) : __( 'Add Team', 'talenttrack' ) ); ?>
            </form>

            <?php if ( $is_edit && $team && class_exists( TeamStaffPanel::class ) ) : ?>
                <?php TeamStaffPanel::render( (int) $team->id ); ?>
                <?php TeamStaffPanel::renderAddForm( (int) $team->id ); ?>
            <?php endif; ?>

            <?php if ( $is_edit && $team ) : ?>
                <?php TeamPlayersPanel::render( (int) $team->id ); ?>
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
            if ( ! current_user_can( 'tt_edit_teams' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
            }
            // #0011 — free-tier cap. Block creation past the team cap.
            if ( class_exists( '\TT\Modules\License\LicenseGate' )
                 && \TT\Modules\License\LicenseGate::capsExceeded( 'teams' )
            ) {
                wp_safe_redirect( add_query_arg(
                    [ 'page' => 'tt-account', 'tt_msg' => 'cap_teams' ],
                    admin_url( 'admin.php' )
                ) );
                exit;
            }
        }

        global $wpdb;
        $spond_group_id = isset( $_POST['spond_group_id'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['spond_group_id'] ) )
            : '';

        $data = [
            'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
            'age_group' => isset( $_POST['age_group'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['age_group'] ) ) : '',
            'head_coach_id' => isset( $_POST['head_coach_id'] ) ? absint( $_POST['head_coach_id'] ) : 0,
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
            'spond_group_id' => $spond_group_id !== '' ? $spond_group_id : null,
        ];
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id ) {
            $wpdb->update( $wpdb->prefix . 'tt_teams', $data, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        } else {
            $data['club_id'] = CurrentClub::id();
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
        if ( ! current_user_can( 'tt_edit_teams' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        // Also clean up any staff assignments pointing at this team, to avoid orphans.
        $wpdb->delete( $wpdb->prefix . 'tt_team_people', [ 'team_id' => $id, 'club_id' => CurrentClub::id() ] );
        $wpdb->delete( $wpdb->prefix . 'tt_teams', [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-teams&tt_msg=deleted' ) );
        exit;
    }
}
