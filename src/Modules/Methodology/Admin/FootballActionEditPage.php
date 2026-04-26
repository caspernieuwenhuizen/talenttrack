<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FootballActionsRepository;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;

/**
 * FootballActionEditPage — edit a single football action row. Hidden
 * submenu reachable via
 * `?page=tt-football-action-edit&action=new|edit&id=...`.
 */
final class FootballActionEditPage {

    public const SLUG = 'tt-football-action-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_football_action_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new FootballActionsRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->find( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'Shipped football actions are read-only.', 'talenttrack' ) );
        }

        MediaPicker::enqueueAssets();

        $name_dec = $row ? ( MultilingualField::decode( $row->name_json )        ?: [] ) : [];
        $desc_dec = $row ? ( MultilingualField::decode( $row->description_json ) ?: [] ) : [];
        ?>
        <div class="wrap">
            <h1><?php echo $row ? esc_html__( 'Edit football action', 'talenttrack' ) : esc_html__( 'Add football action', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . FootballActionsPage::SLUG ) ); ?>">← <?php esc_html_e( 'Back to football actions', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_football_action_save', 'tt_football_action_nonce' ); ?>
                <input type="hidden" name="action" value="tt_football_action_save" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="slug" class="regular-text" required value="<?php echo esc_attr( (string) ( $row->slug ?? '' ) ); ?>" placeholder="aannemen" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Category', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="category_key" required>
                                <?php foreach ( FootballActionsRepository::categories() as $k => $label ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) ( $row->category_key ?? '' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label></th>
                        <td><input type="number" name="sort_order" value="<?php echo (int) ( $row->sort_order ?? 0 ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Name (NL)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="name_nl" class="regular-text" value="<?php echo esc_attr( (string) ( $name_dec['nl'] ?? '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Name (EN)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="name_en" class="regular-text" value="<?php echo esc_attr( (string) ( $name_dec['en'] ?? '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Description (NL)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="description_nl" rows="4" class="large-text"><?php echo esc_textarea( (string) ( $desc_dec['nl'] ?? '' ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Description (EN)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="description_en" rows="4" class="large-text"><?php echo esc_textarea( (string) ( $desc_dec['en'] ?? '' ) ); ?></textarea></td>
                    </tr>
                </table>
                <?php if ( $row ) MediaPicker::render( MethodologyAssetsRepository::TYPE_FOOTBALL_ACTION, (int) $row->id ); ?>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Create football action', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_football_action_save', 'tt_football_action_nonce' );
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $payload = [
            'slug'             => sanitize_key( (string) wp_unslash( $_POST['slug'] ?? '' ) ),
            'category_key'     => sanitize_key( (string) wp_unslash( $_POST['category_key'] ?? '' ) ),
            'sort_order'       => (int) ( $_POST['sort_order'] ?? 0 ),
            'name_json'        => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['name_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['name_en'] ?? '' ) ) ),
            ] ),
            'description_json' => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description_en'] ?? '' ) ) ),
            ] ),
        ];
        $repo = new FootballActionsRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) wp_die( esc_html__( 'Shipped football actions are read-only.', 'talenttrack' ) );
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $id = $repo->create( $payload );
        }
        MediaPicker::handleSave( MethodologyAssetsRepository::TYPE_FOOTBALL_ACTION, (int) $id );
        wp_safe_redirect( add_query_arg(
            [ 'page' => FootballActionsPage::SLUG, 'tt_msg' => 'saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
