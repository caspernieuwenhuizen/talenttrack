<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FormationsRepository;
use TT\Modules\Methodology\Repositories\SetPiecesRepository;

/**
 * SetPieceEditPage — wp-admin form for a club-authored set piece.
 *
 * Hidden submenu reached via
 * `?page=tt-methodology-set-piece-edit&action=new|edit&id=...`.
 *
 * Bullets are stored as a `{nl: [...], en: [...]}` JSON; the form
 * uses newline-separated textareas same as PositionEditPage.
 */
class SetPieceEditPage {

    public const SLUG = 'tt-methodology-set-piece-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_set_piece_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new SetPiecesRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->find( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'Shipped set pieces are read-only. Use Clone & Edit instead.', 'talenttrack' ) );
        }

        $formations = ( new FormationsRepository() )->listAll();
        $title_nl = $title_en = '';
        $bullets_nl = $bullets_en = '';

        if ( $row ) {
            $title_decoded   = MultilingualField::decode( $row->title_json )   ?: [];
            $bullets_decoded = MultilingualField::decode( $row->bullets_json ) ?: [];
            $title_nl   = (string) ( $title_decoded['nl'] ?? '' );
            $title_en   = (string) ( $title_decoded['en'] ?? '' );
            $bullets_nl = is_array( $bullets_decoded['nl'] ?? null ) ? implode( "\n", $bullets_decoded['nl'] ) : '';
            $bullets_en = is_array( $bullets_decoded['en'] ?? null ) ? implode( "\n", $bullets_decoded['en'] ) : '';
        }
        ?>
        <div class="wrap">
            <h1><?php echo $row ? esc_html__( 'Edit set piece', 'talenttrack' ) : esc_html__( 'Add set piece', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG . '&tab=set_pieces' ) ); ?>">← <?php esc_html_e( 'Back to set pieces', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_set_piece_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_set_piece_save" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tt_sp_slug"><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label></th>
                        <td><input type="text" id="tt_sp_slug" name="slug" class="regular-text" required value="<?php echo esc_attr( (string) ( $row->slug ?? '' ) ); ?>" placeholder="corner-attacking-far-post" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Kind', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="kind_key" required>
                                <?php foreach ( MethodologyEnums::setPieceKinds() as $k => $label ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $row->kind_key ?? '', $k ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Side', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="side" required>
                                <?php foreach ( MethodologyEnums::sides() as $k => $label ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $row->side ?? '', $k ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Default formation', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="default_formation_id">
                                <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                                <?php foreach ( $formations as $f ) : ?>
                                    <option value="<?php echo (int) $f->id; ?>" <?php selected( (int) ( $row->default_formation_id ?? 0 ), (int) $f->id ); ?>>
                                        <?php echo esc_html( MultilingualField::string( $f->name_json ) ?: $f->slug ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Title (NL)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="title_nl" class="regular-text" value="<?php echo esc_attr( $title_nl ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Title (EN)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="title_en" class="regular-text" value="<?php echo esc_attr( $title_en ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Bullets (NL — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="bullets_nl" rows="6" class="large-text"><?php echo esc_textarea( $bullets_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Bullets (EN — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="bullets_en" rows="6" class="large-text"><?php echo esc_textarea( $bullets_en ); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Create set piece', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_set_piece_save', 'tt_methodology_nonce' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $payload = [
            'slug'                 => sanitize_text_field( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) ),
            'kind_key'             => sanitize_key( (string) wp_unslash( $_POST['kind_key'] ?? '' ) ),
            'side'                 => sanitize_key( (string) wp_unslash( $_POST['side'] ?? '' ) ),
            'default_formation_id' => absint( $_POST['default_formation_id'] ?? 0 ) ?: null,
            'title_json'           => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_en'] ?? '' ) ) ),
            ] ),
            'bullets_json'         => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $_POST['bullets_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $_POST['bullets_en'] ?? '' ) ),
            ] ),
        ];

        if ( ! MethodologyEnums::isValidKind( $payload['kind_key'] ) ||
             ! MethodologyEnums::isValidSide( $payload['side'] ) ) {
            wp_die( esc_html__( 'Invalid kind or side.', 'talenttrack' ) );
        }

        $repo = new SetPiecesRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) {
                wp_die( esc_html__( 'Shipped set pieces are read-only.', 'talenttrack' ) );
            }
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $id = $repo->create( $payload );
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'set_pieces', 'tt_msg' => 'saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /** @return string[] */
    private static function splitLines( string $raw ): array {
        $parts = preg_split( "/\r?\n/", $raw ) ?: [];
        $out = [];
        foreach ( $parts as $p ) {
            $clean = trim( sanitize_text_field( $p ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }
}
