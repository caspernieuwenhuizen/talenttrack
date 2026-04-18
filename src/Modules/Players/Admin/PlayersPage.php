<?php
namespace TT\Modules\Players\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

class PlayersPage {
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
        $where = "WHERE pl.status='active'" . ( $ft ? $wpdb->prepare( " AND pl.team_id=%d", $ft ) : '' );
        $players = $wpdb->get_results( "SELECT pl.*, t.name AS team_name FROM {$p}tt_players pl LEFT JOIN {$p}tt_teams t ON pl.team_id=t.id $where ORDER BY pl.last_name, pl.first_name ASC" );
        $teams = QueryHelpers::get_teams();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Players', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-players&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <form method="get" style="margin:10px 0"><input type="hidden" name="page" value="tt-players"/>
                <select name="team_id" onchange="this.form.submit()"><option value="0"><?php esc_html_e( 'All Teams', 'talenttrack' ); ?></option>
                <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $ft, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select>
            </form>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Position(s)', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Foot', 'talenttrack' ); ?></th>
                <th>#</th><th><?php esc_html_e( 'DOB', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
            </tr></thead><tbody>
            <?php if ( empty( $players ) ) : ?><tr><td colspan="7"><?php esc_html_e( 'No players.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $players as $pl ) :
                $pos = json_decode( (string) $pl->preferred_positions, true ); $pos_str = is_array( $pos ) ? implode( ', ', $pos ) : ''; ?>
                <tr>
                    <td><strong><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=view&id={$pl->id}" ) ); ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></a></strong></td>
                    <td><?php echo esc_html( $pl->team_name ?: '—' ); ?></td><td><?php echo esc_html( $pos_str ); ?></td>
                    <td><?php echo esc_html( (string) $pl->preferred_foot ); ?></td>
                    <td><?php echo $pl->jersey_number ? (int) $pl->jersey_number : '—'; ?></td>
                    <td><?php echo esc_html( $pl->date_of_birth ?: '—' ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=edit&id={$pl->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_player&id={$pl->id}" ), 'tt_delete_player_' . $pl->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td>
                </tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( ?object $player ): void {
        $is_edit   = $player !== null;
        $teams     = QueryHelpers::get_teams();
        $positions = QueryHelpers::get_lookup_names( 'position' );
        $foot_opts = QueryHelpers::get_lookup_names( 'foot_option' );
        $sel_pos   = $is_edit ? ( json_decode( (string) $player->preferred_positions, true ) ?: [] ) : [];
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? esc_html__( 'Edit Player', 'talenttrack' ) : esc_html__( 'Add Player', 'talenttrack' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_player', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_player" />
                <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $player->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'First Name', 'talenttrack' ); ?> *</th><td><input type="text" name="first_name" value="<?php echo esc_attr( $player->first_name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th><?php esc_html_e( 'Last Name', 'talenttrack' ); ?> *</th><td><input type="text" name="last_name" value="<?php echo esc_attr( $player->last_name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th><?php esc_html_e( 'Date of Birth', 'talenttrack' ); ?></th><td><input type="date" name="date_of_birth" value="<?php echo esc_attr( $player->date_of_birth ?? '' ); ?>" /></td></tr>
                    <tr><th><?php esc_html_e( 'Nationality', 'talenttrack' ); ?></th><td><input type="text" name="nationality" value="<?php echo esc_attr( $player->nationality ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Height (cm)', 'talenttrack' ); ?></th><td><input type="number" name="height_cm" value="<?php echo esc_attr( $player->height_cm ?? '' ); ?>" min="50" max="250" /></td></tr>
                    <tr><th><?php esc_html_e( 'Weight (kg)', 'talenttrack' ); ?></th><td><input type="number" name="weight_kg" value="<?php echo esc_attr( $player->weight_kg ?? '' ); ?>" min="20" max="200" /></td></tr>
                    <tr><th><?php esc_html_e( 'Preferred Foot', 'talenttrack' ); ?></th><td><select name="preferred_foot"><option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $foot_opts as $f ) : ?><option value="<?php echo esc_attr( $f ); ?>" <?php selected( $player->preferred_foot ?? '', $f ); ?>><?php echo esc_html( $f ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Preferred Position(s)', 'talenttrack' ); ?></th><td>
                        <?php foreach ( $positions as $pos ) : ?><label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="preferred_positions[]" value="<?php echo esc_attr( $pos ); ?>" <?php echo in_array( $pos, $sel_pos ) ? 'checked' : ''; ?> /> <?php echo esc_html( $pos ); ?></label><?php endforeach; ?>
                    </td></tr>
                    <tr><th><?php esc_html_e( 'Jersey Number', 'talenttrack' ); ?></th><td><input type="number" name="jersey_number" value="<?php echo esc_attr( $player->jersey_number ?? '' ); ?>" min="1" max="99" /></td></tr>
                    <tr><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><td><select name="team_id"><option value="0"><?php esc_html_e( '— No Team —', 'talenttrack' ); ?></option>
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $player->team_id ?? 0, $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Date Joined', 'talenttrack' ); ?></th><td><input type="date" name="date_joined" value="<?php echo esc_attr( $player->date_joined ?? '' ); ?>" /></td></tr>
                    <tr><th><?php esc_html_e( 'Photo', 'talenttrack' ); ?></th><td><input type="text" name="photo_url" id="tt_photo_url" value="<?php echo esc_url( $player->photo_url ?? '' ); ?>" class="regular-text" /> <button type="button" class="button" id="tt-upload-photo"><?php esc_html_e( 'Upload', 'talenttrack' ); ?></button></td></tr>
                    <tr><th><?php esc_html_e( 'Guardian Name', 'talenttrack' ); ?></th><td><input type="text" name="guardian_name" value="<?php echo esc_attr( $player->guardian_name ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Guardian Email', 'talenttrack' ); ?></th><td><input type="email" name="guardian_email" value="<?php echo esc_attr( $player->guardian_email ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Guardian Phone', 'talenttrack' ); ?></th><td><input type="text" name="guardian_phone" value="<?php echo esc_attr( $player->guardian_phone ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Linked WP User', 'talenttrack' ); ?></th><td><?php wp_dropdown_users( [ 'name' => 'wp_user_id', 'selected' => $player->wp_user_id ?? 0, 'show_option_none' => __( '— None —', 'talenttrack' ), 'option_none_value' => 0 ] ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><td><select name="status">
                        <?php foreach ( [ 'active' => __( 'Active', 'talenttrack' ), 'inactive' => __( 'Inactive', 'talenttrack' ), 'trial' => __( 'Trial', 'talenttrack' ), 'released' => __( 'Released', 'talenttrack' ) ] as $k => $l ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $player->status ?? 'active', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
                </table>
                <?php submit_button( $is_edit ? __( 'Update Player', 'talenttrack' ) : __( 'Add Player', 'talenttrack' ) ); ?>
            </form>
        </div>
        <script>jQuery(function($){ var f; $('#tt-upload-photo').on('click',function(e){ e.preventDefault(); if(!f)f=wp.media({title:'Select Photo',button:{text:'Use'},multiple:false}); f.on('select',function(){$('#tt_photo_url').val(f.state().get('selection').first().toJSON().url);}); f.open(); }); });</script>
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
            <h1><?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=edit&id={$id}" ) ); ?>" class="page-title-action"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-players' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'talenttrack' ); ?></a></h1>
            <div style="display:flex;gap:30px;flex-wrap:wrap;margin-top:20px;">
                <div style="flex:1;min-width:280px;">
                    <table class="form-table">
                        <tr><th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th><td><?php echo esc_html( $team ? (string) $team->name : '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Position(s)', 'talenttrack' ); ?></th><td><?php echo is_array( $pos ) ? esc_html( implode( ', ', $pos ) ) : '—'; ?></td></tr>
                        <tr><th><?php esc_html_e( 'Foot', 'talenttrack' ); ?></th><td><?php echo esc_html( $player->preferred_foot ?: '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'DOB', 'talenttrack' ); ?></th><td><?php echo esc_html( $player->date_of_birth ?: '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Nationality', 'talenttrack' ); ?></th><td><?php echo esc_html( $player->nationality ?: '—' ); ?></td></tr>
                    </table>
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
        if ( ! current_user_can( 'tt_manage_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_player', 'tt_nonce' );
        global $wpdb;
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
        if ( $id ) $wpdb->update( $wpdb->prefix . 'tt_players', $data, [ 'id' => $id ] );
        else { $wpdb->insert( $wpdb->prefix . 'tt_players', $data ); $id = (int) $wpdb->insert_id; }
        do_action( 'tt_after_player_save', $id, $data );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-players&tt_msg=saved' ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_delete_player_' . $id );
        if ( ! current_user_can( 'tt_manage_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-players&tt_msg=deleted' ) );
        exit;
    }
}
