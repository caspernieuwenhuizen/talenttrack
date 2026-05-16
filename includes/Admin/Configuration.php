<?php
namespace TT\Admin;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Configuration {

    public static function init() {
        add_action( 'admin_post_tt_save_config', [ __CLASS__, 'handle_save_config' ] );
        add_action( 'admin_post_tt_save_lookup', [ __CLASS__, 'handle_save_lookup' ] );
        add_action( 'admin_post_tt_delete_lookup', [ __CLASS__, 'handle_delete_lookup' ] );
    }

    public static function render_page() {
        $tab = sanitize_text_field( $_GET['tab'] ?? 'eval_categories' );
        $tabs = [
            'eval_categories' => 'Evaluation Categories',
            'eval_types'      => 'Evaluation Types',
            'positions'       => 'Positions',
            'foot_options'    => 'Preferred Foot',
            'age_groups'      => 'Age Groups',
            'goal_statuses'   => 'Goal Statuses',
            'goal_priorities' => 'Goal Priorities',
            'att_statuses'    => 'Attendance Statuses',
            'rating'          => 'Rating Scale',
            'branding'        => 'Branding',
            'reports'         => 'Report Settings',
            'system'          => 'System',
        ];
        ?>
        <div class="wrap">
            <h1>TalentTrack Configuration</h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo $_GET['tt_msg'] === 'deleted' ? 'Deleted.' : 'Saved.'; ?></p></div>
            <?php endif; ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $k => $l ) : ?>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$k" ) ); ?>"
                       class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $l ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div style="margin-top:20px;">
            <?php
            switch ( $tab ) {
                case 'eval_categories': self::tab_lookup( 'eval_category', 'Evaluation Category', true, true ); break;
                case 'eval_types':      self::tab_eval_types(); break;
                case 'positions':       self::tab_lookup( 'position', 'Position', false, false ); break;
                case 'foot_options':    self::tab_lookup( 'foot_option', 'Foot Option', false, false ); break;
                case 'age_groups':      self::tab_lookup( 'age_group', 'Age Group', false, false ); break;
                case 'goal_statuses':   self::tab_lookup( 'goal_status', 'Goal Status', false, false ); break;
                case 'goal_priorities': self::tab_lookup( 'goal_priority', 'Goal Priority', false, false ); break;
                case 'att_statuses':    self::tab_lookup( 'attendance_status', 'Attendance Status', false, false ); break;
                case 'rating':          self::tab_rating(); break;
                case 'branding':        self::tab_branding(); break;
                case 'reports':         self::tab_reports(); break;
                case 'system':          self::tab_system(); break;
            }
            ?>
            </div>
        </div>
        <?php
    }

    /* ═══════════════════════════════════════════════════════
       GENERIC LOOKUP CRUD
       ═══════════════════════════════════════════════════════ */

    private static function tab_lookup( $type, $label, $show_description = true, $show_sort = true ) {
        $action = sanitize_text_field( $_GET['crud'] ?? 'list' );
        $id     = absint( $_GET['lookup_id'] ?? 0 );
        $tab    = self::tab_key_for_type( $type );

        if ( $action === 'edit' || $action === 'new' ) {
            $item = ( $action === 'edit' && $id ) ? Helpers::get_lookup( $id ) : null;
            self::render_lookup_form( $type, $label, $item, $show_description, $show_sort, $tab );
            return;
        }

        $items = Helpers::get_lookups( $type );
        ?>
        <h2><?php echo esc_html( $label ); ?>s
            <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=new" ) ); ?>" class="page-title-action">Add New</a>
        </h2>
        <table class="widefat striped" style="max-width:700px;">
            <thead>
                <tr>
                    <th style="width:50px">Order</th>
                    <th>Name</th>
                    <?php if ( $show_description ) : ?><th>Description</th><?php endif; ?>
                    <th style="width:120px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="<?php echo $show_description ? 4 : 3; ?>">No items.</td></tr>
            <?php else : foreach ( $items as $item ) : ?>
                <tr>
                    <td><?php echo (int) $item->sort_order; ?></td>
                    <td><strong><?php echo esc_html( $item->name ); ?></strong></td>
                    <?php if ( $show_description ) : ?><td><?php echo esc_html( $item->description ); ?></td><?php endif; ?>
                    <td>
                        <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=edit&lookup_id={$item->id}" ) ); ?>">Edit</a> |
                        <a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_lookup&id={$item->id}&tab=$tab" ), 'tt_del_lookup_' . $item->id ); ?>"
                           onclick="return confirm('Delete this <?php echo esc_js( strtolower( $label ) ); ?>?')" style="color:#b32d2e;">Delete</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_lookup_form( $type, $label, $item = null, $show_desc = true, $show_sort = true, $tab = '' ) {
        $is_edit = $item !== null;
        ?>
        <h2><?php echo $is_edit ? 'Edit' : 'Add'; ?> <?php echo esc_html( $label ); ?>
            <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab" ) ); ?>" class="page-title-action">← Back</a>
        </h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
            <?php wp_nonce_field( 'tt_save_lookup', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_lookup" />
            <input type="hidden" name="lookup_type" value="<?php echo esc_attr( $type ); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
            <?php if ( $is_edit ) : ?>
                <input type="hidden" name="id" value="<?php echo (int) $item->id; ?>" />
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="tt_lk_name">Name *</label></th>
                    <td><input type="text" name="name" id="tt_lk_name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="regular-text" required /></td>
                </tr>
                <?php if ( $show_desc ) : ?>
                <tr>
                    <th><label for="tt_lk_desc">Description</label></th>
                    <td><input type="text" name="description" id="tt_lk_desc" value="<?php echo esc_attr( $item->description ?? '' ); ?>" class="large-text" /></td>
                </tr>
                <?php endif; ?>
                <?php if ( $show_sort ) : ?>
                <tr>
                    <th><label for="tt_lk_order">Sort Order</label></th>
                    <td><input type="number" name="sort_order" id="tt_lk_order" value="<?php echo (int) ( $item->sort_order ?? 0 ); ?>" min="0" /></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php submit_button( $is_edit ? 'Update' : 'Add' ); ?>
        </form>
        <?php
    }

    /* ═══════════════════════════════════════════════════════
       EVALUATION TYPES — special form with match-details toggle
       ═══════════════════════════════════════════════════════ */

    private static function tab_eval_types() {
        $action = sanitize_text_field( $_GET['crud'] ?? 'list' );
        $id     = absint( $_GET['lookup_id'] ?? 0 );
        $tab    = 'eval_types';

        if ( $action === 'edit' || $action === 'new' ) {
            $item = ( $action === 'edit' && $id ) ? Helpers::get_lookup( $id ) : null;
            $meta = $item ? Helpers::lookup_meta( $item ) : [];
            ?>
            <h2><?php echo $item ? 'Edit' : 'Add'; ?> Evaluation Type
                <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab" ) ); ?>" class="page-title-action">← Back</a>
            </h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
                <?php wp_nonce_field( 'tt_save_lookup', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_lookup" />
                <input type="hidden" name="lookup_type" value="eval_type" />
                <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
                <?php if ( $item ) : ?><input type="hidden" name="id" value="<?php echo (int) $item->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th>Name *</th><td><input type="text" name="name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th>Description</th><td><input type="text" name="description" value="<?php echo esc_attr( $item->description ?? '' ); ?>" class="large-text" /></td></tr>
                    <tr><th>Requires Match Details</th><td><label><input type="checkbox" name="requires_match_details" value="1" <?php checked( ! empty( $meta['requires_match_details'] ) ); ?> /> If checked, this type will ask for opponent, competition, result, home/away, and minutes played</label></td></tr>
                    <tr><th>Sort Order</th><td><input type="number" name="sort_order" value="<?php echo (int) ( $item->sort_order ?? 0 ); ?>" min="0" /></td></tr>
                </table>
                <?php submit_button( $item ? 'Update' : 'Add' ); ?>
            </form>
            <?php
            return;
        }

        // List view
        $items = Helpers::get_eval_types();
        ?>
        <h2>Evaluation Types
            <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=new" ) ); ?>" class="page-title-action">Add New</a>
        </h2>
        <table class="widefat striped" style="max-width:700px;">
            <thead><tr><th style="width:50px">Order</th><th>Name</th><th>Description</th><th>Match Details?</th><th style="width:120px">Actions</th></tr></thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="5">No evaluation types.</td></tr>
            <?php else : foreach ( $items as $item ) :
                $meta = Helpers::lookup_meta( $item );
            ?>
                <tr>
                    <td><?php echo (int) $item->sort_order; ?></td>
                    <td><strong><?php echo esc_html( $item->name ); ?></strong></td>
                    <td><?php echo esc_html( $item->description ); ?></td>
                    <td><?php echo ! empty( $meta['requires_match_details'] ) ? '✓ Yes' : 'No'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=edit&lookup_id={$item->id}" ) ); ?>">Edit</a> |
                        <a href="<?php echo wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_lookup&id={$item->id}&tab=$tab" ), 'tt_del_lookup_' . $item->id ); ?>"
                           onclick="return confirm('Delete this evaluation type?')" style="color:#b32d2e;">Delete</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* ═══════════════════════════════════════════════════════
       SCALAR CONFIG TABS (rating, branding, reports, system)
       ═══════════════════════════════════════════════════════ */

    private static function tab_rating() {
        ?>
        <h2>Rating Scale</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_save_config', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_config" />
            <input type="hidden" name="tab" value="rating" />
            <table class="form-table">
                <tr><th>Minimum</th><td><input type="number" name="cfg[rating_min]" value="<?php echo esc_attr( Helpers::get_config( 'rating_min', 5 ) ); ?>" min="0" max="10" step="0.5" /></td></tr>
                <tr><th>Maximum</th><td><input type="number" name="cfg[rating_max]" value="<?php echo esc_attr( Helpers::get_config( 'rating_max', 10 ) ); ?>" min="1" max="100" step="0.5" /></td></tr>
                <tr><th>Step</th><td><input type="number" name="cfg[rating_step]" value="<?php echo esc_attr( Helpers::get_config( 'rating_step', '0.5' ) ); ?>" min="0.1" max="1" step="0.1" /></td></tr>
            </table>
            <?php submit_button( 'Save Scale' ); ?>
        </form>
        <?php
    }

    private static function tab_branding() {
        wp_enqueue_media();
        $logo = Helpers::get_config( 'logo_url', '' );
        ?>
        <h2>Branding</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_save_config', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_config" />
            <input type="hidden" name="tab" value="branding" />
            <table class="form-table">
                <tr><th>Academy Name</th><td><input type="text" name="cfg[academy_name]" value="<?php echo esc_attr( Helpers::get_config( 'academy_name', '' ) ); ?>" class="regular-text" /></td></tr>
                <tr><th>Logo</th><td>
                    <div id="tt-logo-preview"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" style="max-height:70px" /><?php endif; ?></div>
                    <input type="hidden" name="cfg[logo_url]" id="tt_logo_url" value="<?php echo esc_url( $logo ); ?>" />
                    <button type="button" class="button" id="tt-upload-logo">Upload</button>
                    <button type="button" class="button" id="tt-remove-logo" <?php echo $logo ? '' : 'style="display:none"'; ?>>Remove</button>
                </td></tr>
                <tr><th>Primary Color</th><td><input type="color" name="cfg[primary_color]" value="<?php echo esc_attr( Helpers::get_config( 'primary_color', '#0b3d2e' ) ); ?>" /></td></tr>
                <tr><th>Secondary Color</th><td><input type="color" name="cfg[secondary_color]" value="<?php echo esc_attr( Helpers::get_config( 'secondary_color', '#e8b624' ) ); ?>" /></td></tr>
                <tr><th>Season Label</th><td><input type="text" name="cfg[season_label]" value="<?php echo esc_attr( Helpers::get_config( 'season_label', '' ) ); ?>" class="regular-text" /></td></tr>
                <tr><th>Report Footer Text</th><td><input type="text" name="cfg[footer_text]" value="<?php echo esc_attr( Helpers::get_config( 'footer_text', '' ) ); ?>" class="large-text" /></td></tr>
            </table>
            <?php submit_button( 'Save Branding' ); ?>
        </form>
        <script>
        jQuery(function($){
            var frame;
            $('#tt-upload-logo').on('click',function(e){ e.preventDefault();
                if(!frame) frame=wp.media({title:'Select Logo',button:{text:'Use'},multiple:false});
                frame.on('select',function(){ var u=frame.state().get('selection').first().toJSON().url; $('#tt_logo_url').val(u); $('#tt-logo-preview').html('<img src="'+u+'" style="max-height:70px"/>'); $('#tt-remove-logo').show(); });
                frame.open();
            });
            $('#tt-remove-logo').on('click',function(){ $('#tt_logo_url').val(''); $('#tt-logo-preview').html(''); $(this).hide(); });
        });
        </script>
        <?php
    }

    private static function tab_reports() {
        $categories = Helpers::get_categories();
        $weights    = json_decode( Helpers::get_config( 'composite_weights', '{}' ), true ) ?: [];
        ?>
        <h2>Report Settings</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_save_config', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_config" />
            <input type="hidden" name="tab" value="reports" />
            <table class="form-table">
                <tr><th>Default Date Range (months)</th><td><input type="number" name="cfg[default_report_range]" value="<?php echo esc_attr( Helpers::get_config( 'default_report_range', '3' ) ); ?>" min="1" max="24" /></td></tr>
            </table>
            <h3>Composite Score Weights</h3>
            <p class="description">Define how much each evaluation category contributes to the overall development score. Values should total 100.</p>
            <table class="widefat" style="max-width:500px;">
                <thead><tr><th>Category</th><th>Weight (%)</th></tr></thead>
                <tbody>
                <?php foreach ( $categories as $cat ) : ?>
                    <tr><td><?php echo esc_html( $cat->name ); ?></td>
                        <td><input type="number" name="weights[<?php echo (int) $cat->id; ?>]" value="<?php echo esc_attr( $weights[ $cat->id ] ?? 25 ); ?>" min="0" max="100" style="width:80px" /></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( 'Save Report Settings' ); ?>
        </form>
        <?php
    }

    private static function tab_system() {
        $modules     = json_decode( Helpers::get_config( 'modules_enabled', '[]' ), true ) ?: [];
        $all_modules = [ 'evaluations', 'goals', 'attendance', 'sessions', 'reports' ];
        ?>
        <h2>System Settings</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_save_config', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_config" />
            <input type="hidden" name="tab" value="system" />
            <table class="form-table">
                <tr><th>Date Format</th><td><select name="cfg[date_format]">
                    <?php foreach ( [ 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y' ] as $f ) : ?>
                        <option value="<?php echo $f; ?>" <?php selected( Helpers::get_config( 'date_format', 'Y-m-d' ), $f ); ?>><?php echo date( $f ); ?> (<?php echo $f; ?>)</option>
                    <?php endforeach; ?>
                </select></td></tr>
                <tr><th>Enabled Modules</th><td>
                    <?php foreach ( $all_modules as $m ) : ?>
                        <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="modules_enabled[]" value="<?php echo esc_attr( $m ); ?>" <?php echo in_array( $m, $modules ) ? 'checked' : ''; ?> /> <?php echo esc_html( ucfirst( $m ) ); ?></label>
                    <?php endforeach; ?>
                </td></tr>
            </table>
            <?php submit_button( 'Save System Settings' ); ?>
        </form>
        <?php
    }

    /* ═══════════════════════════════════════════════════════
       HANDLERS
       ═══════════════════════════════════════════════════════ */

    public static function handle_save_config() {
        if ( ! current_user_can( 'tt_manage_settings' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_config', 'tt_nonce' );

        $tab = sanitize_text_field( $_POST['tab'] ?? '' );

        foreach ( $_POST['cfg'] ?? [] as $k => $v ) {
            Helpers::set_config( sanitize_key( $k ), sanitize_text_field( $v ) );
        }

        if ( isset( $_POST['weights'] ) ) {
            $w = [];
            foreach ( $_POST['weights'] as $cid => $val ) $w[ absint( $cid ) ] = absint( $val );
            Helpers::set_config( 'composite_weights', wp_json_encode( $w ) );
        }

        if ( isset( $_POST['modules_enabled'] ) ) {
            Helpers::set_config( 'modules_enabled', wp_json_encode( array_map( 'sanitize_text_field', $_POST['modules_enabled'] ) ) );
        } elseif ( $tab === 'system' ) {
            Helpers::set_config( 'modules_enabled', '[]' );
        }

        wp_safe_redirect( admin_url( "admin.php?page=tt-config&tab=$tab&tt_msg=saved" ) );
        exit;
    }

    public static function handle_save_lookup() {
        if ( ! current_user_can( 'tt_manage_settings' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'tt_save_lookup', 'tt_nonce' );

        global $wpdb;
        $id   = absint( $_POST['id'] ?? 0 );
        $type = sanitize_text_field( $_POST['lookup_type'] ?? '' );
        $tab  = sanitize_text_field( $_POST['tab'] ?? '' );

        $data = [
            'lookup_type' => $type,
            'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
            'description' => sanitize_text_field( $_POST['description'] ?? '' ),
            'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
        ];

        // Special meta for eval_type
        if ( $type === 'eval_type' ) {
            $data['meta'] = wp_json_encode( [
                'requires_match_details' => isset( $_POST['requires_match_details'] ),
            ]);
        }

        if ( $id ) {
            $wpdb->update( $wpdb->prefix . 'tt_lookups', $data, [ 'id' => $id ] );
        } else {
            $wpdb->insert( $wpdb->prefix . 'tt_lookups', $data );
        }

        wp_safe_redirect( admin_url( "admin.php?page=tt-config&tab=$tab&tt_msg=saved" ) );
        exit;
    }

    public static function handle_delete_lookup() {
        $id  = absint( $_GET['id'] ?? 0 );
        $tab = sanitize_text_field( $_GET['tab'] ?? '' );
        check_admin_referer( 'tt_del_lookup_' . $id );
        if ( ! current_user_can( 'tt_manage_settings' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_lookups', [ 'id' => $id ] );

        wp_safe_redirect( admin_url( "admin.php?page=tt-config&tab=$tab&tt_msg=deleted" ) );
        exit;
    }

    /* ═══ Helper: map lookup_type to tab key ═════════════ */

    private static function tab_key_for_type( $type ) {
        $map = [
            'eval_category'     => 'eval_categories',
            'eval_type'         => 'eval_types',
            'position'          => 'positions',
            'foot_option'       => 'foot_options',
            'age_group'         => 'age_groups',
            'goal_status'       => 'goal_statuses',
            'goal_priority'     => 'goal_priorities',
            'attendance_status' => 'att_statuses',
        ];
        return $map[ $type ] ?? $type;
    }
}
