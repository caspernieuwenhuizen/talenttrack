<?php
namespace TT\Admin;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Players {
    public static function init() {
        add_action( 'admin_post_tt_save_player', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_player', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page() {
        $action = $_GET['action'] ?? 'list';
        $id     = absint( $_GET['id'] ?? 0 );
        if ( $action === 'edit' || $action === 'new' ) { self::render_form( $action === 'edit' ? Helpers::get_player( $id ) : null ); return; }
        if ( $action === 'view' && $id ) { self::render_view( $id ); return; }
        self::render_list();
    }

    private static function render_list() {
        global $wpdb; $p = $wpdb->prefix;
        $ft = absint( $_GET['team_id'] ?? 0 );
        $where = "WHERE pl.status='active'" . ( $ft ? $wpdb->prepare( " AND pl.team_id=%d", $ft ) : '' );
        $players = $wpdb->get_results( "SELECT pl.*, t.name AS team_name FROM {$p}tt_players pl LEFT JOIN {$p}tt_teams t ON pl.team_id=t.id $where ORDER BY pl.last_name, pl.first_name ASC" );
        $teams = Helpers::get_teams();
        ?>
        <div class="wrap">
            <h1>Players <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-players&action=new' ) ); ?>" class="page-title-action">Add New</a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
            <form method="get" style="margin:10px 0"><input type="hidden" name="page" value="tt-players"/>
                <select name="team_id" onchange="this.form.submit()"><option value="0">All Teams</option>
                <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $ft, (int) $t->id ); ?>><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?></select></form>
            <table class="widefat striped"><thead><tr><th>Name</th><th>Team</th><th>Position(s)</th><th>Foot</th><th>#</th><th>DOB</th><th>Actions</th></tr></thead><tbody>
            <?php if ( empty( $players ) ) : ?><tr><td colspan="7">No players.</td></tr>
            <?php else : foreach ( $players as $pl ) :
                $pos = json_decode( $pl->preferred_positions, true ); $pos_str = is_array( $pos ) ? implode( ', ', $pos ) : ''; ?>
                <tr><td><strong><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=view&id={$pl->id}" ) ); ?>"><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></a></strong></td>
                    <td><?php echo esc_html( $pl->team_name ?: '—' ); ?></td><td><?php echo esc_html( $pos_str ); ?></td><td><?php echo esc_html( $pl->preferred_foot ); ?></td>
                    <td><?php echo $pl->jersey_number ? (int) $pl->jersey_number : '—'; ?></td><td><?php echo esc_html( $pl->date_of_birth ?: '—' ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=edit&id={$pl->id}" ) ); ?>">Edit</a> | <a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_player&id={$pl->id}" ), 'tt_delete_player_' . $pl->id ); ?>" onclick="return confirm('Delete?')" style="color:#b32d2e;">Delete</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( $player = null ) {
        $is_edit   = $player !== null;
        $teams     = Helpers::get_teams();
        $positions = Helpers::get_lookup_names( 'position' );
        $foot_opts = Helpers::get_lookup_names( 'foot_option' );
        $sel_pos   = $is_edit ? ( json_decode( $player->preferred_positions, true ) ?: [] ) : [];
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? 'Edit Player' : 'Add Player'; ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_player', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_player" />
                <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $player->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th>First Name *</th><td><input type="text" name="first_name" value="<?php echo esc_attr( $player->first_name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th>Last Name *</th><td><input type="text" name="last_name" value="<?php echo esc_attr( $player->last_name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th>Date of Birth</th><td><input type="date" name="date_of_birth" value="<?php echo esc_attr( $player->date_of_birth ?? '' ); ?>" /></td></tr>
                    <tr><th>Nationality</th><td><input type="text" name="nationality" value="<?php echo esc_attr( $player->nationality ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th>Height (cm)</th><td><input type="number" name="height_cm" value="<?php echo esc_attr( $player->height_cm ?? '' ); ?>" min="50" max="250" /></td></tr>
                    <tr><th>Weight (kg)</th><td><input type="number" name="weight_kg" value="<?php echo esc_attr( $player->weight_kg ?? '' ); ?>" min="20" max="200" /></td></tr>
                    <tr><th>Preferred Foot</th><td><select name="preferred_foot"><option value="">— Select —</option>
                        <?php foreach ( $foot_opts as $f ) : ?><option value="<?php echo esc_attr( $f ); ?>" <?php selected( $player->preferred_foot ?? '', $f ); ?>><?php echo esc_html( $f ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Preferred Position(s)</th><td>
                        <?php foreach ( $positions as $pos ) : ?><label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="preferred_positions[]" value="<?php echo esc_attr( $pos ); ?>" <?php echo in_array( $pos, $sel_pos ) ? 'checked' : ''; ?> /> <?php echo esc_html( $pos ); ?></label><?php endforeach; ?></td></tr>
                    <tr><th>Jersey Number</th><td><input type="number" name="jersey_number" value="<?php echo esc_attr( $player->jersey_number ?? '' ); ?>" min="1" max="99" /></td></tr>
                    <tr><th>Team</th><td><select name="team_id"><option value="0">— No Team —</option>
                        <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>" <?php selected( $player->team_id ?? 0, $t->id ); ?>><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Date Joined</th><td><input type="date" name="date_joined" value="<?php echo esc_attr( $player->date_joined ?? '' ); ?>" /></td></tr>
                    <tr><th>Photo</th><td><input type="text" name="photo_url" id="tt_photo_url" value="<?php echo esc_url( $player->photo_url ?? '' ); ?>" class="regular-text" /> <button type="button" class="button" id="tt-upload-photo">Upload</button></td></tr>
                    <tr><th>Guardian Name</th><td><input type="text" name="guardian_name" value="<?php echo esc_attr( $player->guardian_name ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th>Guardian Email</th><td><input type="email" name="guardian_email" value="<?php echo esc_attr( $player->guardian_email ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th>Guardian Phone</th><td><input type="text" name="guardian_phone" value="<?php echo esc_attr( $player->guardian_phone ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th>Linked WP User</th><td><?php wp_dropdown_users( [ 'name' => 'wp_user_id', 'selected' => $player->wp_user_id ?? 0, 'show_option_none' => '— None —', 'option_none_value' => 0 ] ); ?></td></tr>
                    <tr><th>Status</th><td><select name="status">
                        <?php foreach ( [ 'active', 'inactive', 'trial', 'released' ] as $s ) : ?><option value="<?php echo $s; ?>" <?php selected( $player->status ?? 'active', $s ); ?>><?php echo ucfirst( $s ); ?></option><?php endforeach; ?></select></td></tr>
                </table>
                <?php submit_button( $is_edit ? 'Update Player' : 'Add Player' ); ?>
            </form>
        </div>
        <script>jQuery(function($){ var f; $('#tt-upload-photo').on('click',function(e){ e.preventDefault(); if(!f)f=wp.media({title:'Select Photo',button:{text:'Use'},multiple:false}); f.on('select',function(){$('#tt_photo_url').val(f.state().get('selection').first().toJSON().url);}); f.open(); }); });</script>
        <?php
    }

    private static function render_view( $id ) {
        $player = Helpers::get_player( $id );
        if ( ! $player ) { echo '<div class="wrap"><p>Not found.</p></div>'; return; }
        $team  = $player->team_id ? Helpers::get_team( $player->team_id ) : null;
        $radar = Helpers::player_radar_datasets( $id );
        $max   = (float) Helpers::get_config( 'rating_max', 5 );
        $pos   = json_decode( $player->preferred_positions, true );
        global $wpdb; $p = $wpdb->prefix;
        $evals = $wpdb->get_results( $wpdb->prepare( "SELECT e.*, lt.name AS type_name, u.display_name AS coach_name FROM {$p}tt_evaluations e LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id LEFT JOIN {$wpdb->users} u ON e.coach_id=u.ID WHERE e.player_id=%d ORDER BY e.eval_date DESC LIMIT 20", $id ) );
        $goals = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_goals WHERE player_id=%d ORDER BY created_at DESC", $id ) );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( Helpers::player_display_name( $player ) ); ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-players&action=edit&id={$id}" ) ); ?>" class="page-title-action">Edit</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-players' ) ); ?>" class="page-title-action">← Back</a></h1>
            <div style="display:flex;gap:30px;flex-wrap:wrap;margin-top:20px;">
                <div style="flex:1;min-width:280px;">
                    <table class="form-table">
                        <tr><th>Team</th><td><?php echo esc_html( $team ? $team->name : '—' ); ?></td></tr>
                        <tr><th>Position(s)</th><td><?php echo is_array( $pos ) ? esc_html( implode( ', ', $pos ) ) : '—'; ?></td></tr>
                        <tr><th>Foot</th><td><?php echo esc_html( $player->preferred_foot ?: '—' ); ?></td></tr>
                        <tr><th>DOB</th><td><?php echo esc_html( $player->date_of_birth ?: '—' ); ?></td></tr>
                        <tr><th>Nationality</th><td><?php echo esc_html( $player->nationality ?: '—' ); ?></td></tr>
                        <tr><th>Height</th><td><?php echo $player->height_cm ? $player->height_cm . ' cm' : '—'; ?></td></tr>
                        <tr><th>Weight</th><td><?php echo $player->weight_kg ? $player->weight_kg . ' kg' : '—'; ?></td></tr>
                        <tr><th>Jersey</th><td><?php echo $player->jersey_number ? '#' . $player->jersey_number : '—'; ?></td></tr>
                    </table>
                </div>
                <div style="flex:1;min-width:320px;">
                    <h3>Development Radar</h3>
                    <?php if ( ! empty( $radar['datasets'] ) ) echo Helpers::radar_chart_svg( $radar['labels'], $radar['datasets'], $max );
                    else echo '<p style="color:#888;">No evaluations yet.</p>'; ?>
                </div>
            </div>
            <h3 style="margin-top:30px;">Recent Evaluations</h3>
            <table class="widefat striped"><thead><tr><th>Date</th><th>Type</th><th>Coach</th><th>Notes</th><th></th></tr></thead><tbody>
            <?php if ( empty( $evals ) ) : ?><tr><td colspan="5">None.</td></tr>
            <?php else : foreach ( $evals as $ev ) : ?>
                <tr><td><?php echo esc_html( $ev->eval_date ); ?></td><td><?php echo esc_html( $ev->type_name ?: '—' ); ?></td><td><?php echo esc_html( $ev->coach_name ); ?></td><td><?php echo esc_html( wp_trim_words( $ev->notes ?: '', 15 ) ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=view&id={$ev->id}" ) ); ?>">View</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
            <h3>Goals</h3>
            <table class="widefat striped"><thead><tr><th>Goal</th><th>Status</th><th>Priority</th><th>Due</th></tr></thead><tbody>
            <?php if ( empty( $goals ) ) : ?><tr><td colspan="4">None.</td></tr>
            <?php else : foreach ( $goals as $g ) : ?>
                <tr><td><?php echo esc_html( $g->title ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $g->status ) ) ); ?></td><td><?php echo esc_html( ucfirst( $g->priority ) ); ?></td><td><?php echo esc_html( $g->due_date ?: '—' ); ?></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'tt_manage_players' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_player', 'tt_nonce' );
        global $wpdb;
        $data = [
            'first_name' => sanitize_text_field( $_POST['first_name'] ?? '' ), 'last_name' => sanitize_text_field( $_POST['last_name'] ?? '' ),
            'date_of_birth' => sanitize_text_field( $_POST['date_of_birth'] ?? '' ), 'nationality' => sanitize_text_field( $_POST['nationality'] ?? '' ),
            'height_cm' => absint( $_POST['height_cm'] ?? 0 ) ?: null, 'weight_kg' => absint( $_POST['weight_kg'] ?? 0 ) ?: null,
            'preferred_foot' => sanitize_text_field( $_POST['preferred_foot'] ?? '' ),
            'preferred_positions' => wp_json_encode( array_map( 'sanitize_text_field', $_POST['preferred_positions'] ?? [] ) ),
            'jersey_number' => absint( $_POST['jersey_number'] ?? 0 ) ?: null, 'team_id' => absint( $_POST['team_id'] ?? 0 ),
            'date_joined' => sanitize_text_field( $_POST['date_joined'] ?? '' ), 'photo_url' => esc_url_raw( $_POST['photo_url'] ?? '' ),
            'guardian_name' => sanitize_text_field( $_POST['guardian_name'] ?? '' ), 'guardian_email' => sanitize_email( $_POST['guardian_email'] ?? '' ),
            'guardian_phone' => sanitize_text_field( $_POST['guardian_phone'] ?? '' ), 'wp_user_id' => absint( $_POST['wp_user_id'] ?? 0 ),
            'status' => sanitize_text_field( $_POST['status'] ?? 'active' ),
        ];
        $id = absint( $_POST['id'] ?? 0 );
        if ( $id ) $wpdb->update( $wpdb->prefix . 'tt_players', $data, [ 'id' => $id ] );
        else { $wpdb->insert( $wpdb->prefix . 'tt_players', $data ); $id = $wpdb->insert_id; }
        do_action( 'tt_after_player_save', $id, $data );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-players&tt_msg=saved' ) ); exit;
    }

    public static function handle_delete() {
        $id = absint( $_GET['id'] ?? 0 );
        check_admin_referer( 'tt_delete_player_' . $id );
        if ( ! current_user_can( 'tt_manage_players' ) ) wp_die( 'Unauthorized' );
        global $wpdb; $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-players&tt_msg=deleted' ) ); exit;
    }
}
