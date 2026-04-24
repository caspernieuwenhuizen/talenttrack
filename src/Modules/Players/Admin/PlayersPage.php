<?php
namespace TT\Modules\Players\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Validation\CustomFieldValidator;
use TT\Shared\Admin\BackButton;

/**
 * PlayersPage — admin CRUD for players.
 *
 * v2.6.2: adds fail-loud $wpdb->insert/update return-value checks. If a write
 * fails (e.g., schema drift, constraint violation), we now log via Logger and
 * redirect back to the form with a visible error banner instead of silently
 * pretending success.
 */
class PlayersPage {

    private const TRANSIENT_PREFIX = 'tt_player_form_state_';

    public static function init(): void {
        add_action( 'admin_post_tt_save_player', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_player', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'edit' || $action === 'new' ) { self::render_form( $action === 'edit' ? QueryHelpers::get_player( $id ) : null ); return; }
        if ( $action === 'view' && $id ) { self::render_view( $id ); return; }
        self::render_list();
    }

    private static function render_list(): void {
        global $wpdb; $p = $wpdb->prefix;
        $ft = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;

        // v2.17.0: archive view filter.
        $view        = \TT\Infrastructure\Archive\ArchiveRepository::sanitizeView( $_GET['tt_view'] ?? 'active' );
        $view_clause = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( $view );

        $scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $where = "WHERE pl.status='active' AND pl.{$view_clause}" . ( $ft ? $wpdb->prepare( " AND pl.team_id=%d", $ft ) : '' ) . $scope;
        $players = $wpdb->get_results( "SELECT pl.*, t.name AS team_name FROM {$p}tt_players pl LEFT JOIN {$p}tt_teams t ON pl.team_id=t.id $where ORDER BY pl.last_name, pl.first_name ASC" );
        $teams = QueryHelpers::get_teams();

        $base_url = admin_url( 'admin.php?page=tt-players' );
        if ( $ft ) $base_url = add_query_arg( 'team_id', $ft, $base_url );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Players', 'talenttrack' ); ?><?php if ( current_user_can( 'tt_edit_players' ) ) : ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-players&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a><?php endif; ?> <?php \TT\Shared\Admin\HelpLink::render( 'teams-players' ); ?></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderBulkMessage(); ?>
            <form method="get" style="margin:10px 0"><input type="hidden" name="page" value="tt-players"/>
                <input type="hidden" name="tt_view" value="<?php echo esc_attr( $view ); ?>"/>
                <select name="team_id" onchange="this.form.submit()"><option value="0"><?php esc_html_e( 'All Teams', 'talenttrack' ); ?></option>
                <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $ft, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select>
            </form>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderStatusTabs( 'player', $view, $base_url ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::openForm( 'player', $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>

            <table class="widefat striped"><thead><tr>
                <th class="check-column" style="width:30px;"><?php \TT\Shared\Admin\BulkActionsHelper::selectAllCheckbox(); ?></th>
                <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Position(s)', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Foot', 'talenttrack' ); ?></th>
                <th>#</th><th><?php esc_html_e( 'DOB', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
            </tr></thead><tbody>
            <?php if ( empty( $players ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'No players.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $players as $pl ) :
                $pos = json_decode( (string) $pl->preferred_positions, true ); $pos_str = is_array( $pos ) ? implode( ', ', $pos ) : '';
                $is_archived = $pl->archived_at !== null;
                ?>
                <tr <?php echo $is_archived ? 'style="opacity:0.6;background:#fafafa;"' : ''; ?>>
                    <td class="check-column"><?php \TT\Shared\Admin\BulkActionsHelper::rowCheckbox( (int) $pl->id ); ?></td>
                    <td><strong><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=view&id={$pl->id}" ) ); ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></a></strong>
                        <?php if ( $is_archived ) : ?><span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#e0e0e0;border-radius:2px;font-size:10px;text-transform:uppercase;color:#555;"><?php esc_html_e( 'Archived', 'talenttrack' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php
                        $pl_team_name = (string) ( $pl->team_name ?? '' );
                        $pl_team_id   = (int) ( $pl->team_id ?? 0 );
                        if ( $pl_team_name !== '' && $pl_team_id > 0 && current_user_can( 'tt_view_teams' ) ) {
                            echo '<a href="' . esc_url( admin_url( 'admin.php?page=tt-teams&action=edit&id=' . $pl_team_id ) ) . '">'
                                . esc_html( $pl_team_name ) . '</a>';
                        } else {
                            echo esc_html( $pl_team_name !== '' ? $pl_team_name : '—' );
                        }
                    ?></td><td><?php echo esc_html( $pos_str ); ?></td>
                    <td><?php echo esc_html( (string) $pl->preferred_foot ); ?></td>
                    <td><?php echo $pl->jersey_number ? (int) $pl->jersey_number : '—'; ?></td>
                    <td><?php echo esc_html( $pl->date_of_birth ?: '—' ); ?></td>
                    <td><?php if ( current_user_can( 'tt_edit_players' ) ) : ?><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=edit&id={$pl->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_player&id={$pl->id}" ), 'tt_delete_player_' . $pl->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a><?php else : ?><span style="color:#999;">—</span><?php endif; ?></td>
                </tr>
            <?php endforeach; endif; ?></tbody></table>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::closeForm(); ?>
        </div>
        <?php
    }

    private static function render_form( ?object $player ): void {
        $is_edit   = $player !== null;

        // v2.14.0: Rate Card tab embedded on the edit view. Only available
        // when editing an existing player (no evaluations to rate-card on
        // a new one). Default tab is 'edit' (the existing form).
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tab'] ) ) : 'edit';
        if ( ! $is_edit ) $tab = 'edit'; // safety

        // Chart.js enqueue when the Rate Card tab is active.
        if ( $is_edit && $tab === 'ratecard' ) {
            \TT\Modules\Stats\Admin\PlayerRateCardView::enqueueChartLibrary();
        }

        $teams     = QueryHelpers::get_teams();
        $positions = QueryHelpers::get_lookup_names( 'position' );
        $foot_opts = QueryHelpers::get_lookup_names( 'foot_option' );
        $sel_pos   = $is_edit ? ( json_decode( (string) $player->preferred_positions, true ) ?: [] ) : [];
        wp_enqueue_media();

        $state = self::popFormState();

        $status_options = [
            'active'   => __( 'Active', 'talenttrack' ),
            'inactive' => __( 'Inactive', 'talenttrack' ),
            'trial'    => __( 'Trial', 'talenttrack' ),
            'released' => __( 'Released', 'talenttrack' ),
        ];

        // Note: custom field values are loaded on-demand by CustomFieldsSlot
        // itself (per-request cached), so this render_form doesn't need to
        // pre-load them. The slot consults $_POST['custom_fields'] so a
        // submitted form always re-renders with the user's typed values.
        //
        // On a validation-error redirect, $_POST is lost, so we pull the
        // transient-saved submission back into $_POST before rendering.
        // CustomFieldsSlot can't tell the difference and renders the
        // right values.
        if ( $state && isset( $state['submitted_custom_fields'] ) && is_array( $state['submitted_custom_fields'] ) ) {
            $_POST['custom_fields'] = $state['submitted_custom_fields'];
        }
        ?>
        <div class="wrap">
            
            <?php BackButton::render( admin_url( 'admin.php?page=tt-players' ) ); ?>
            <h1><?php echo $is_edit ? esc_html__( 'Edit Player', 'talenttrack' ) : esc_html__( 'Add Player', 'talenttrack' ); ?></h1>

            <?php if ( $is_edit ) :
                $edit_url     = admin_url( 'admin.php?page=tt-players&action=edit&id=' . (int) $player->id );
                $ratecard_url = add_query_arg( 'tab', 'ratecard', $edit_url );
                ?>
                <nav class="nav-tab-wrapper" style="margin-top:12px;">
                    <a href="<?php echo esc_url( $edit_url ); ?>" class="nav-tab <?php echo $tab === 'edit' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                    <a href="<?php echo esc_url( $ratecard_url ); ?>" class="nav-tab <?php echo $tab === 'ratecard' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Rate card', 'talenttrack' ); ?></a>
                </nav>
            <?php endif; ?>

            <?php
            if ( $is_edit && $tab === 'ratecard' ) {
                $player_id = (int) $player->id;
                $filters   = \TT\Infrastructure\Stats\PlayerStatsService::sanitizeFilters( $_GET );
                $base_url  = add_query_arg(
                    [ 'page' => 'tt-players', 'action' => 'edit', 'id' => $player_id, 'tab' => 'ratecard' ],
                    admin_url( 'admin.php' )
                );
                \TT\Modules\Stats\Admin\PlayerRateCardView::render( $player_id, $filters, $base_url );
                echo '</div>';
                return;
            }
            ?>

            <?php if ( $state && ! empty( $state['errors'] ) ) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e( 'Please fix the errors below:', 'talenttrack' ); ?></strong></p>
                    <ul style="list-style:disc;margin-left:20px;">
                    <?php foreach ( $state['errors'] as $err ) : ?>
                        <li><?php echo esc_html( (string) ( $err['message'] ?? '' ) ); ?></li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( $state && ! empty( $state['db_error'] ) ) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e( 'The database rejected the save. Please contact your administrator.', 'talenttrack' ); ?></strong></p>
                    <p style="font-family:monospace;font-size:12px;"><?php echo esc_html( (string) $state['db_error'] ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_player', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_player" />
                <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $player->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'First Name', 'talenttrack' ); ?> *</th><td><input type="text" name="first_name" value="<?php echo esc_attr( $player->first_name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'first_name' ); ?>
                    <tr><th><?php esc_html_e( 'Last Name', 'talenttrack' ); ?> *</th><td><input type="text" name="last_name" value="<?php echo esc_attr( $player->last_name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'last_name' ); ?>
                    <tr><th><?php esc_html_e( 'Date of Birth', 'talenttrack' ); ?></th><td><input type="date" name="date_of_birth" value="<?php echo esc_attr( $player->date_of_birth ?? '' ); ?>" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'date_of_birth' ); ?>
                    <tr><th><?php esc_html_e( 'Nationality', 'talenttrack' ); ?></th><td><input type="text" name="nationality" value="<?php echo esc_attr( $player->nationality ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'nationality' ); ?>
                    <tr><th><?php esc_html_e( 'Height (cm)', 'talenttrack' ); ?></th><td><input type="number" name="height_cm" value="<?php echo esc_attr( $player->height_cm ?? '' ); ?>" min="50" max="250" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'height_cm' ); ?>
                    <tr><th><?php esc_html_e( 'Weight (kg)', 'talenttrack' ); ?></th><td><input type="number" name="weight_kg" value="<?php echo esc_attr( $player->weight_kg ?? '' ); ?>" min="20" max="200" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'weight_kg' ); ?>
                    <tr><th><?php esc_html_e( 'Preferred Foot', 'talenttrack' ); ?></th><td><select name="preferred_foot"><option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $foot_opts as $f ) : ?><option value="<?php echo esc_attr( $f ); ?>" <?php selected( $player->preferred_foot ?? '', $f ); ?>><?php echo esc_html( $f ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'preferred_foot' ); ?>
                    <tr><th><?php esc_html_e( 'Preferred Position(s)', 'talenttrack' ); ?></th><td>
                        <?php foreach ( $positions as $pos ) : ?><label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="preferred_positions[]" value="<?php echo esc_attr( $pos ); ?>" <?php echo in_array( $pos, $sel_pos ) ? 'checked' : ''; ?> /> <?php echo esc_html( $pos ); ?></label><?php endforeach; ?>
                    </td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'preferred_positions' ); ?>
                    <tr><th><?php esc_html_e( 'Jersey Number', 'talenttrack' ); ?></th><td><input type="number" name="jersey_number" value="<?php echo esc_attr( $player->jersey_number ?? '' ); ?>" min="1" max="99" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'jersey_number' ); ?>
                    <tr><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><td><select name="team_id"><option value="0"><?php esc_html_e( '— No Team —', 'talenttrack' ); ?></option>
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $player->team_id ?? 0, $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'team_id' ); ?>
                    <tr><th><?php esc_html_e( 'Date Joined', 'talenttrack' ); ?></th><td><input type="date" name="date_joined" value="<?php echo esc_attr( $player->date_joined ?? '' ); ?>" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'date_joined' ); ?>
                    <tr><th><?php esc_html_e( 'Photo', 'talenttrack' ); ?></th><td><input type="text" name="photo_url" id="tt_photo_url" value="<?php echo esc_url( $player->photo_url ?? '' ); ?>" class="regular-text" /> <button type="button" class="button" id="tt-upload-photo"><?php esc_html_e( 'Upload', 'talenttrack' ); ?></button></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'photo_url' ); ?>
                    <tr><th><?php esc_html_e( 'Guardian Name', 'talenttrack' ); ?></th><td><input type="text" name="guardian_name" value="<?php echo esc_attr( $player->guardian_name ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'guardian_name' ); ?>
                    <tr><th><?php esc_html_e( 'Guardian Email', 'talenttrack' ); ?></th><td><input type="email" name="guardian_email" value="<?php echo esc_attr( $player->guardian_email ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'guardian_email' ); ?>
                    <tr><th><?php esc_html_e( 'Guardian Phone', 'talenttrack' ); ?></th><td><input type="text" name="guardian_phone" value="<?php echo esc_attr( $player->guardian_phone ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'guardian_phone' ); ?>
                    <tr><th><?php esc_html_e( 'Linked WP User', 'talenttrack' ); ?></th><td><?php wp_dropdown_users( [ 'name' => 'wp_user_id', 'selected' => $player->wp_user_id ?? 0, 'show_option_none' => __( '— None —', 'talenttrack' ), 'option_none_value' => 0 ] ); ?></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'wp_user_id' ); ?>
                    <tr><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><td><select name="status">
                        <?php foreach ( $status_options as $k => $l ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $player->status ?? 'active', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ), 'status' ); ?>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_PLAYER, (int) ( $player->id ?? 0 ) ); ?>
                </table>

                <?php submit_button( $is_edit ? __( 'Update Player', 'talenttrack' ) : __( 'Add Player', 'talenttrack' ) ); ?>
            </form>
        </div>
        <script>jQuery(function($){ var f; $('#tt-upload-photo').on('click',function(e){ e.preventDefault(); if(!f)f=wp.media({title:'<?php echo esc_js( __( 'Select Photo', 'talenttrack' ) ); ?>',button:{text:'<?php echo esc_js( __( 'Use', 'talenttrack' ) ); ?>'},multiple:false}); f.on('select',function(){$('#tt_photo_url').val(f.state().get('selection').first().toJSON().url);}); f.open(); }); });</script>
        <?php
    }

    private static function render_view( int $id ): void {
        $player = QueryHelpers::get_player( $id );
        if ( ! $player ) { echo '<div class="wrap"><p>' . esc_html__( 'Not found.', 'talenttrack' ) . '</p></div>'; return; }
        $team  = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $radar = QueryHelpers::player_radar_datasets( $id );
        $max   = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $pos   = json_decode( (string) $player->preferred_positions, true );
        ?>
        <div class="wrap">
            <?php BackButton::render( admin_url( 'admin.php?page=tt-players' ) ); ?>
            <h1><?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=edit&id={$id}" ) ); ?>" class="page-title-action"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a></h1>
            <div style="display:flex;gap:30px;flex-wrap:wrap;margin-top:20px;">
                <div style="flex:1;min-width:280px;">
                    <table class="form-table">
                        <tr><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><td><?php echo esc_html( $team ? (string) $team->name : '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Position(s)', 'talenttrack' ); ?></th><td><?php echo is_array( $pos ) ? esc_html( implode( ', ', $pos ) ) : '—'; ?></td></tr>
                        <tr><th><?php esc_html_e( 'Foot', 'talenttrack' ); ?></th><td><?php echo esc_html( $player->preferred_foot ?: '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'DOB', 'talenttrack' ); ?></th><td><?php echo esc_html( $player->date_of_birth ?: '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Nationality', 'talenttrack' ); ?></th><td><?php echo esc_html( $player->nationality ?: '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><td><?php echo esc_html( LabelTranslator::playerStatus( (string) $player->status ) ); ?></td></tr>
                    </table>
                    <?php CustomFieldsSlot::renderReadonly( CustomFieldsRepository::ENTITY_PLAYER, $id ); ?>
                </div>
                <div style="flex:1;min-width:320px;">
                    <h3><?php esc_html_e( 'Development Radar', 'talenttrack' ); ?></h3>
                    <?php echo ! empty( $radar['datasets'] ) ? QueryHelpers::radar_chart_svg( $radar['labels'], $radar['datasets'], $max ) : '<p>' . esc_html__( 'No evaluations yet.', 'talenttrack' ) . '</p>'; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function handle_save(): void {
        check_admin_referer( 'tt_save_player', 'tt_nonce' );
        $id_check = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        // v2.8.0: entity-scoped auth when editing an existing player (coaches
        // can edit players on their teams); capability-only for new players
        // since there's no entity to scope against yet.
        if ( $id_check > 0 ) {
            if ( ! AuthorizationService::canEditPlayer( get_current_user_id(), $id_check ) ) {
                wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
            }
        } else {
            if ( ! current_user_can( 'tt_edit_players' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
            }
        }

        global $wpdb;

        // Validate custom fields first so we can bail before writing the
        // native entity if required fields are missing (keeps the UX
        // predictable — error banner, user's input preserved via
        // transient, no half-saved state).
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        $submitted_cf = ( isset( $_POST['custom_fields'] ) && is_array( $_POST['custom_fields'] ) )
            ? array_map( function ( $v ) { return is_string( $v ) ? wp_unslash( $v ) : $v; }, wp_unslash( $_POST['custom_fields'] ) )
            : [];
        $multi_markers = ( isset( $_POST['custom_fields_multi_marker'] ) && is_array( $_POST['custom_fields_multi_marker'] ) )
            ? wp_unslash( $_POST['custom_fields_multi_marker'] )
            : [];
        $validation = ( new CustomFieldValidator() )->validate( $fields, $submitted_cf, $multi_markers );

        if ( ! empty( $validation['errors'] ) ) {
            self::saveFormState( [
                'errors' => $validation['errors'],
                'submitted_custom_fields' => $submitted_cf,
            ] );
            $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
            $back = add_query_arg(
                [ 'page' => 'tt-players', 'action' => $id ? 'edit' : 'new', 'id' => $id ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $back );
            exit;
        }

        $data = [
            'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['first_name'] ) ) : '',
            'last_name' => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['last_name'] ) ) : '',
            'date_of_birth' => isset( $_POST['date_of_birth'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_of_birth'] ) ) : '',
            'nationality' => isset( $_POST['nationality'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nationality'] ) ) : '',
            'height_cm' => ! empty( $_POST['height_cm'] ) ? absint( $_POST['height_cm'] ) : null,
            'weight_kg' => ! empty( $_POST['weight_kg'] ) ? absint( $_POST['weight_kg'] ) : null,
            'preferred_foot' => isset( $_POST['preferred_foot'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['preferred_foot'] ) ) : '',
            'preferred_positions' => wp_json_encode( array_map( 'sanitize_text_field', (array) ( $_POST['preferred_positions'] ?? [] ) ) ),
            'jersey_number' => ! empty( $_POST['jersey_number'] ) ? absint( $_POST['jersey_number'] ) : null,
            'team_id' => isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0,
            'date_joined' => isset( $_POST['date_joined'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date_joined'] ) ) : '',
            'photo_url' => isset( $_POST['photo_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['photo_url'] ) ) : '',
            'guardian_name' => isset( $_POST['guardian_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['guardian_name'] ) ) : '',
            'guardian_email' => isset( $_POST['guardian_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['guardian_email'] ) ) : '',
            'guardian_phone' => isset( $_POST['guardian_phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['guardian_phone'] ) ) : '',
            'wp_user_id' => isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0,
            'status' => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : 'active',
        ];
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( $id ) {
            $ok = $wpdb->update( $wpdb->prefix . 'tt_players', $data, [ 'id' => $id ] );
        } else {
            $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', $data );
            if ( $ok ) $id = (int) $wpdb->insert_id;
        }

        if ( $ok === false ) {
            Logger::error( 'player.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'is_update' => (bool) $id ] );
            self::saveFormState( [
                'db_error' => $wpdb->last_error ?: __( 'Unknown database error.', 'talenttrack' ),
                'submitted_custom_fields' => $submitted_cf,
            ] );
            $back = add_query_arg(
                [ 'page' => 'tt-players', 'action' => $id ? 'edit' : 'new', 'id' => $id ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $back );
            exit;
        }

        // Custom fields validated cleanly above; apply sanitized values.
        // Fields that were "skipped" (not on this submission) are left alone.
        $values_repo = new CustomValuesRepository();
        foreach ( $validation['sanitized'] as $field_id => $value ) {
            $values_repo->upsert( CustomFieldsRepository::ENTITY_PLAYER, $id, (int) $field_id, $value );
        }

        do_action( 'tt_after_player_save', $id, $data );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-players&tt_msg=saved' ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_delete_player_' . $id );

        // v2.8.0: delete remains capability-only (not entity-scoped). Deleting
        // a player is destructive, so team coaches shouldn't be able to delete
        // players they only coach. Only users with tt_manage_players can delete.
        if ( ! current_user_can( 'tt_edit_players' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-players&tt_msg=deleted' ) );
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
