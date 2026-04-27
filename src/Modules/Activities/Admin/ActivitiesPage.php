<?php
namespace TT\Modules\Activities\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomFieldsSlot;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Validation\CustomFieldValidator;
use TT\Shared\Admin\BackButton;

/**
 * ActivitiesPage — admin CRUD for activities (was SessionsPage).
 *
 * v3.x: the entity gained a typing layer — `activity_type_key`
 * (game / training / other), `game_subtype_key` (friendly / cup /
 * league when type=game), and `other_label` (free-text when
 * type=other). The form renders the conditional fields inline; the
 * list view shows the type badge per row.
 */
class ActivitiesPage {

    private const TRANSIENT_PREFIX = 'tt_act_form_state_';

    public static function init(): void {
        add_action( 'admin_post_tt_save_activity', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_activity', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $id ); return; }
        global $wpdb; $p = $wpdb->prefix;

        $view        = \TT\Infrastructure\Archive\ArchiveRepository::sanitizeView( $_GET['tt_view'] ?? 'active' );
        $view_clause = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( $view );
        $type_filter = isset( $_GET['type'] ) ? sanitize_key( (string) wp_unslash( $_GET['type'] ) ) : '';

        $scope = QueryHelpers::apply_demo_scope( 'a', 'activity' );
        $type_clause = '';
        if ( $type_filter !== '' && in_array( $type_filter, [ 'game', 'training', 'other' ], true ) ) {
            $type_clause = $wpdb->prepare( ' AND a.activity_type_key = %s', $type_filter );
        }
        $activities = $wpdb->get_results( "SELECT a.*, t.name AS team_name, u.display_name AS coach_name FROM {$p}tt_activities a LEFT JOIN {$p}tt_teams t ON a.team_id=t.id LEFT JOIN {$wpdb->users} u ON a.coach_id=u.ID WHERE a.{$view_clause} {$scope} {$type_clause} ORDER BY a.session_date DESC LIMIT 50" );
        $base_url = admin_url( 'admin.php?page=tt-activities' );
        // #0040 — type filter as a <select> instead of the chip-strip.
        // Three hardcoded options for now (game/training/other) since the
        // storage column `activity_type_key` enforces those three values;
        // moving to a lookup-driven set would require an entity migration.
        $type_options = [
            ''         => __( 'All types', 'talenttrack' ),
            'game'     => __( 'Games', 'talenttrack' ),
            'training' => __( 'Trainings', 'talenttrack' ),
            'other'    => __( 'Other', 'talenttrack' ),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Activities', 'talenttrack' ); ?><?php if ( current_user_can( 'tt_edit_activities' ) ) : ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-activities&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a><?php endif; ?> <?php \TT\Shared\Admin\HelpLink::render( 'activities' ); ?></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <?php self::renderMigrationNotice(); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderBulkMessage(); ?>

            <form method="get" action="<?php echo esc_url( $base_url ); ?>" class="tt-activities-filter" style="margin:8px 0 12px; font-size:13px; display:flex; gap:8px; align-items:center;">
                <input type="hidden" name="page" value="tt-activities" />
                <?php if ( ! empty( $_GET['tt_view'] ) ) : ?>
                    <input type="hidden" name="tt_view" value="<?php echo esc_attr( sanitize_key( (string) $_GET['tt_view'] ) ); ?>" />
                <?php endif; ?>
                <label for="tt-activities-type-filter"><strong><?php esc_html_e( 'Type:', 'talenttrack' ); ?></strong></label>
                <select id="tt-activities-type-filter" name="type" onchange="this.form.submit();">
                    <?php foreach ( $type_options as $opt_key => $opt_label ) : ?>
                        <option value="<?php echo esc_attr( (string) $opt_key ); ?>" <?php selected( $type_filter, $opt_key ); ?>><?php echo esc_html( (string) $opt_label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="button"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button></noscript>
            </form>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderStatusTabs( 'activity', $view, $base_url ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::openForm( 'activity', $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>

            <table class="widefat striped tt-table-sortable"><thead><tr>
                <th class="check-column" style="width:30px;" data-tt-sort="off"><?php \TT\Shared\Admin\BulkActionsHelper::selectAllCheckbox(); ?></th>
                <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                <th data-tt-sort="off"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
            </tr></thead><tbody>
            <?php if ( empty( $activities ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No activities.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $activities as $a ) :
                $is_archived = $a->archived_at !== null;
                ?>
                <tr <?php echo $is_archived ? 'style="opacity:0.6;background:#fafafa;"' : ''; ?>>
                    <td class="check-column"><?php \TT\Shared\Admin\BulkActionsHelper::rowCheckbox( (int) $a->id ); ?></td>
                    <td><?php echo esc_html( (string) $a->session_date ); ?></td>
                    <td><?php echo esc_html( self::renderTypeBadge( $a ) ); ?></td>
                    <td><?php echo esc_html( (string) $a->title ); ?>
                        <?php if ( $is_archived ) : ?><span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#e0e0e0;border-radius:2px;font-size:10px;text-transform:uppercase;color:#555;"><?php esc_html_e( 'Archived', 'talenttrack' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php
                        $act_team_name = (string) ( $a->team_name ?? '' );
                        $act_team_id   = (int) ( $a->team_id ?? 0 );
                        if ( $act_team_name !== '' && $act_team_id > 0 && current_user_can( 'tt_view_teams' ) ) {
                            echo '<a href="' . esc_url( admin_url( 'admin.php?page=tt-teams&action=edit&id=' . $act_team_id ) ) . '">'
                                . esc_html( $act_team_name ) . '</a>';
                        } else {
                            echo esc_html( $act_team_name !== '' ? $act_team_name : '—' );
                        }
                    ?></td>
                    <td><?php if ( current_user_can( 'tt_edit_activities' ) ) : ?><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-activities&action=edit&id={$a->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_activity&id={$a->id}" ), 'tt_del_act_' . $a->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a><?php else : ?><span style="color:#999;">—</span><?php endif; ?></td></tr>
            <?php endforeach; endif; ?></tbody></table>

            <?php \TT\Shared\Admin\BulkActionsHelper::renderActionBar( $view ); ?>
            <?php \TT\Shared\Admin\BulkActionsHelper::closeForm(); ?>
        </div>
        <?php
    }

    /**
     * One-time admin notice flagging the migration that backfilled
     * existing rows to type=training. Dismissible via ?tt_dismiss_act_notice=1.
     */
    private static function renderMigrationNotice(): void {
        if ( isset( $_GET['tt_dismiss_act_notice'] ) ) {
            delete_transient( 'tt_activities_migrated_notice' );
            return;
        }
        $count = (int) get_transient( 'tt_activities_migrated_notice' );
        if ( $count <= 0 ) return;
        $dismiss_url = add_query_arg( 'tt_dismiss_act_notice', '1', admin_url( 'admin.php?page=tt-activities' ) );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: %d: number of activities migrated */
                    esc_html__( '%d existing activities were migrated from sessions and default to type "Training". Reclassify any historical games via the edit form.', 'talenttrack' ),
                    $count
                );
                ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Dismiss', 'talenttrack' ); ?></a>
            </p>
        </div>
        <?php
    }

    private static function renderTypeBadge( object $row ): string {
        $type = (string) ( $row->activity_type_key ?? 'training' );
        // #0050 — pull the translated label from the activity_type
        // lookup. Cases for game subtype / other-label still apply
        // because those carry extra context the lookup name lacks.
        if ( $type === 'game' ) {
            $sub = (string) ( $row->game_subtype_key ?? '' );
            if ( $sub !== '' ) {
                return sprintf(
                    /* translators: %s: game subtype label (Friendly / Cup / League) */
                    __( 'Game · %s', 'talenttrack' ),
                    $sub
                );
            }
            return __( 'Game', 'talenttrack' );
        }
        if ( $type === 'other' ) {
            $label = (string) ( $row->other_label ?? '' );
            return $label !== '' ? $label : __( 'Other', 'talenttrack' );
        }
        // Training and admin-added types — pull the translated label
        // from the lookup, fall back to humanised key when the row was
        // somehow removed.
        return \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'activity_type', $type ) ?: $type;
    }

    private static function render_form( int $id ): void {
        global $wpdb; $p = $wpdb->prefix;
        $activity = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_activities WHERE id=%d", $id ) ) : null;
        $teams = QueryHelpers::get_teams();
        $att_statuses = QueryHelpers::get_lookup_names( 'attendance_status' );
        // #0050 — Type now lookup-driven; existing rows store the seed
        // names (training/game/other) so no data migration was needed.
        $activity_type_rows = QueryHelpers::get_lookups( 'activity_type' );
        $game_subtypes      = QueryHelpers::get_lookup_names( 'game_subtype' );
        $attendance = [];
        if ( $activity ) foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_attendance WHERE activity_id=%d AND is_guest=0", $activity->id ) ) as $r ) $attendance[ (int) $r->player_id ] = $r;
        $team_id = (int) ( $activity->team_id ?? 0 );
        $players = $team_id ? QueryHelpers::get_players( $team_id ) : QueryHelpers::get_players();
        $state = self::popFormState();
        $current_type = (string) ( $activity->activity_type_key ?? 'training' );
        $current_subtype = (string) ( $activity->game_subtype_key ?? '' );
        $current_other = (string) ( $activity->other_label ?? '' );
        ?>
        <div class="wrap">

            <?php BackButton::render( admin_url( 'admin.php?page=tt-activities' ) ); ?>
            <h1><?php echo $activity ? esc_html__( 'Edit Activity', 'talenttrack' ) : esc_html__( 'New Activity', 'talenttrack' ); ?></h1>

            <?php if ( ! empty( $_GET['tt_cf_error'] ) ) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e( 'The activity was saved, but one or more custom fields had invalid values and were not updated.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $state && ! empty( $state['db_error'] ) ) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e( 'The database rejected the save. No activity was created.', 'talenttrack' ); ?></strong></p>
                    <p style="font-family:monospace;font-size:12px;"><?php echo esc_html( (string) $state['db_error'] ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tt-activity-form">
                <?php wp_nonce_field( 'tt_save_activity', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_activity" />
                <?php if ( $activity ) : ?><input type="hidden" name="id" value="<?php echo (int) $activity->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</th>
                        <td>
                            <select name="activity_type_key" id="tt-activity-type" required>
                                <?php foreach ( $activity_type_rows as $type_row ) : ?>
                                    <option value="<?php echo esc_attr( (string) $type_row->name ); ?>" <?php selected( $current_type, (string) $type_row->name ); ?>><?php echo esc_html( \TT\Infrastructure\Query\LookupTranslator::name( $type_row ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="tt-activity-subtype-row" style="<?php echo $current_type === 'game' ? '' : 'display:none;'; ?>">
                        <th><?php esc_html_e( 'Game subtype', 'talenttrack' ); ?></th>
                        <td>
                            <select name="game_subtype_key">
                                <option value=""><?php esc_html_e( '— Choose —', 'talenttrack' ); ?></option>
                                <?php foreach ( $game_subtypes as $sub ) : ?>
                                    <option value="<?php echo esc_attr( $sub ); ?>" <?php selected( $current_subtype, $sub ); ?>><?php echo esc_html( $sub ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Optional: friendly, cup, or league.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                    <tr id="tt-activity-other-row" style="<?php echo $current_type === 'other' ? '' : 'display:none;'; ?>">
                        <th><?php esc_html_e( 'Other label', 'talenttrack' ); ?> *</th>
                        <td>
                            <input type="text" name="other_label" maxlength="120" value="<?php echo esc_attr( $current_other ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Required when Type is "Other". Free text — e.g. team-building day, club meeting.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                    <tr><th><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</th><td><input type="text" name="title" value="<?php echo esc_attr( $activity->title ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_ACTIVITY, (int) ( $activity->id ?? 0 ), 'title' ); ?>
                    <tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</th><td><input type="date" name="session_date" value="<?php echo esc_attr( $activity->session_date ?? current_time( 'Y-m-d' ) ); ?>" required /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_ACTIVITY, (int) ( $activity->id ?? 0 ), 'session_date' ); ?>
                    <tr><th><?php esc_html_e( 'Location', 'talenttrack' ); ?></th><td><input type="text" name="location" value="<?php echo esc_attr( $activity->location ?? '' ); ?>" class="regular-text" /></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_ACTIVITY, (int) ( $activity->id ?? 0 ), 'location' ); ?>
                    <tr><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><td><select name="team_id"><option value="0"><?php esc_html_e( '— All —', 'talenttrack' ); ?></option>
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $team_id, $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_ACTIVITY, (int) ( $activity->id ?? 0 ), 'team_id' ); ?>
                    <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( $activity->notes ?? '' ); ?></textarea></td></tr>
                    <?php CustomFieldsSlot::render( CustomFieldsRepository::ENTITY_ACTIVITY, (int) ( $activity->id ?? 0 ), 'notes' ); ?>
                    <?php
                    if ( class_exists( '\TT\Modules\Methodology\Repositories\PrinciplesRepository' ) ) :
                        $all_principles = ( new \TT\Modules\Methodology\Repositories\PrinciplesRepository() )->listFiltered();
                        $linked_ids     = ( $activity && (int) $activity->id > 0 )
                            ? ( new \TT\Modules\Methodology\Repositories\PrincipleLinksRepository() )->principlesForActivity( (int) $activity->id )
                            : [];
                        if ( ! empty( $all_principles ) ) : ?>
                            <tr>
                                <th><?php esc_html_e( 'Principles practiced', 'talenttrack' ); ?></th>
                                <td>
                                    <select name="activity_principle_ids[]" multiple size="6" style="min-width:320px;">
                                        <?php foreach ( $all_principles as $pr ) :
                                            $title = \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
                                            ?>
                                            <option value="<?php echo (int) $pr->id; ?>" <?php selected( in_array( (int) $pr->id, $linked_ids, true ) ); ?>>
                                                <?php echo esc_html( $pr->code . ' · ' . ( $title ?: '—' ) ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Optional. Hold Ctrl/Cmd to select multiple.', 'talenttrack' ); ?></p>
                                </td>
                            </tr>
                    <?php endif; endif; ?>
                    <?php CustomFieldsSlot::renderAppend( CustomFieldsRepository::ENTITY_ACTIVITY, (int) ( $activity->id ?? 0 ) ); ?>
                </table>
                <?php if ( ! empty( $players ) ) : ?>
                <h3><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h3>
                <table class="widefat striped" style="max-width:600px;"><thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th></tr></thead><tbody>
                <?php foreach ( $players as $pl ) : $att = $attendance[ (int) $pl->id ] ?? null; ?>
                    <tr><td><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></td>
                        <td><select name="att[<?php echo (int) $pl->id; ?>][status]"><?php foreach ( $att_statuses as $as ) : ?><option value="<?php echo esc_attr( $as ); ?>" <?php selected( $att->status ?? 'Present', $as ); ?>><?php echo esc_html( LabelTranslator::attendanceStatus( (string) $as ) ); ?></option><?php endforeach; ?></select></td>
                        <td><input type="text" name="att[<?php echo (int) $pl->id; ?>][notes]" value="<?php echo esc_attr( $att->notes ?? '' ); ?>" style="width:200px" /></td></tr>
                <?php endforeach; ?></tbody></table>
                <?php endif; ?>
                <?php submit_button( $activity ? __( 'Update', 'talenttrack' ) : __( 'Save', 'talenttrack' ) ); ?>
            </form>
        </div>
        <script>
        (function(){
            var sel = document.getElementById('tt-activity-type');
            if ( ! sel ) return;
            var subRow = document.getElementById('tt-activity-subtype-row');
            var otherRow = document.getElementById('tt-activity-other-row');
            sel.addEventListener('change', function(){
                // The seeded keys 'game' and 'other' anchor the conditional
                // rows; admin-added types behave like neither (no subtype,
                // no other-label).
                if ( subRow )   subRow.style.display   = ( sel.value === 'game' )  ? '' : 'none';
                if ( otherRow ) otherRow.style.display = ( sel.value === 'other' ) ? '' : 'none';
            });
        })();
        </script>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'tt_edit_activities' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_activity', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        $type = isset( $_POST['activity_type_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['activity_type_key'] ) ) : 'training';
        // #0050 — validate against the live lookup; unknown values fall
        // back to 'training' silently (wp-admin path stays lenient; REST
        // path returns 400 for the same case).
        $valid_types = QueryHelpers::get_lookup_names( 'activity_type' );
        if ( ! in_array( $type, $valid_types, true ) ) $type = 'training';

        $data = [
            'title' => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '',
            'session_date' => isset( $_POST['session_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['session_date'] ) ) : '',
            'team_id' => isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0,
            'coach_id' => get_current_user_id(),
            'location' => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['location'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
            'activity_type_key' => $type,
            'game_subtype_key'  => $type === 'game' && ! empty( $_POST['game_subtype_key'] )
                ? sanitize_text_field( wp_unslash( (string) $_POST['game_subtype_key'] ) )
                : null,
            'other_label'       => $type === 'other' && ! empty( $_POST['other_label'] )
                ? sanitize_text_field( wp_unslash( (string) $_POST['other_label'] ) )
                : null,
        ];

        if ( $id ) {
            $ok = $wpdb->update( "{$p}tt_activities", $data, [ 'id' => $id ] );
        } else {
            $ok = $wpdb->insert( "{$p}tt_activities", $data );
            if ( $ok !== false ) $id = (int) $wpdb->insert_id;
        }

        if ( $ok !== false && $id > 0 && class_exists( '\TT\Modules\Methodology\Repositories\PrincipleLinksRepository' ) ) {
            $submitted = isset( $_POST['activity_principle_ids'] ) && is_array( $_POST['activity_principle_ids'] )
                ? array_map( 'intval', (array) $_POST['activity_principle_ids'] )
                : [];
            ( new \TT\Modules\Methodology\Repositories\PrincipleLinksRepository() )->setActivityPrinciples( $id, $submitted );
        }

        if ( $ok === false ) {
            Logger::error( 'admin.activity.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'is_update' => (bool) $id ] );
            self::saveFormState( [ 'db_error' => $wpdb->last_error ?: __( 'Unknown database error.', 'talenttrack' ) ] );
            $back = add_query_arg(
                [ 'page' => 'tt-activities', 'action' => $id ? 'edit' : 'new', 'id' => $id ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $back );
            exit;
        }

        // #0026 — only wipe roster rows; guest rows (is_guest=1) are
        // managed via the frontend / REST endpoints and survive a
        // legacy admin save cycle.
        $wpdb->delete( "{$p}tt_attendance", [ 'activity_id' => $id, 'is_guest' => 0 ] );
        $att_raw = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? $_POST['att'] : [];
        foreach ( $att_raw as $pid => $d ) {
            $ok_att = $wpdb->insert( "{$p}tt_attendance", [
                'activity_id' => $id, 'player_id' => absint( $pid ),
                'status' => isset( $d['status'] ) ? sanitize_text_field( wp_unslash( (string) $d['status'] ) ) : 'Present',
                'notes' => isset( $d['notes'] ) ? sanitize_text_field( wp_unslash( (string) $d['notes'] ) ) : '',
                'is_guest' => 0,
            ]);
            if ( $ok_att === false ) {
                Logger::error( 'admin.activity.attendance.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'activity_id' => $id, 'player_id' => absint( $pid ) ] );
            }
        }

        $cf_errors = CustomFieldValidator::persistFromPost( CustomFieldsRepository::ENTITY_ACTIVITY, $id, $_POST );
        $redirect_args = [ 'page' => 'tt-activities', 'tt_msg' => 'saved' ];
        if ( ! empty( $cf_errors ) ) {
            $redirect_args['tt_cf_error'] = 1;
            $redirect_args['action']      = 'edit';
            $redirect_args['id']          = $id;
        }

        // #0035 — fire tt_activity_completed when the activity is saved.
        // Workflow's EventDispatcher subscribes to this and dispatches
        // the post-game evaluation; the template short-circuits when
        // activity_type_key != 'game' so trainings + other types do
        // not spawn tasks.
        if ( class_exists( '\TT\Modules\Workflow\TaskContext' ) ) {
            $ctx = new \TT\Modules\Workflow\TaskContext( null, (int) $data['team_id'], (int) $id );
            do_action( 'tt_activity_completed', $ctx, $type );
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_del_act_' . $id );
        if ( ! current_user_can( 'tt_edit_activities' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_attendance", [ 'activity_id' => $id ] );
        $wpdb->delete( "{$p}tt_activities", [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-activities&tt_msg=deleted' ) );
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
