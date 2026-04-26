<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FormationsRepository;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;
use TT\Modules\Methodology\Repositories\MethodologyVisionRepository;

/**
 * VisionEditPage — wp-admin form for the club's vision record.
 *
 * One vision per club. Editing creates or updates the row scoped to
 * the current install; the shipped sample is read-only and clubs
 * start their own from scratch (or by reading the sample as
 * inspiration).
 */
class VisionEditPage {

    public const SLUG = 'tt-methodology-vision-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_vision_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new MethodologyVisionRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->find( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'The shipped sample vision is read-only. Create a new club vision instead.', 'talenttrack' ) );
        }

        MediaPicker::enqueueAssets();

        $formations = ( new FormationsRepository() )->listAll();
        $way_nl = $way_en = $notes_nl = $notes_en = '';
        $traits_nl = $traits_en = '';
        $style = (string) ( $row->style_of_play_key ?? '' );
        $formation_id = (int) ( $row->formation_id ?? 0 );

        if ( $row ) {
            $way_decoded    = MultilingualField::decode( $row->way_of_playing_json )    ?: [];
            $traits_decoded = MultilingualField::decode( $row->important_traits_json )  ?: [];
            $notes_decoded  = MultilingualField::decode( $row->notes_json )             ?: [];
            $way_nl   = (string) ( $way_decoded['nl']   ?? '' );
            $way_en   = (string) ( $way_decoded['en']   ?? '' );
            $notes_nl = (string) ( $notes_decoded['nl'] ?? '' );
            $notes_en = (string) ( $notes_decoded['en'] ?? '' );
            $traits_nl = is_array( $traits_decoded['nl'] ?? null ) ? implode( "\n", $traits_decoded['nl'] ) : '';
            $traits_en = is_array( $traits_decoded['en'] ?? null ) ? implode( "\n", $traits_decoded['en'] ) : '';
        }
        ?>
        <div class="wrap">
            <h1><?php echo $row ? esc_html__( 'Edit vision', 'talenttrack' ) : esc_html__( 'Define vision', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG . '&tab=vision' ) ); ?>">← <?php esc_html_e( 'Back to vision', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_vision_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_vision_save" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e( 'Formation', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="formation_id">
                                <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                                <?php foreach ( $formations as $f ) : ?>
                                    <option value="<?php echo (int) $f->id; ?>" <?php selected( $formation_id, (int) $f->id ); ?>>
                                        <?php echo esc_html( MultilingualField::string( $f->name_json ) ?: $f->slug ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Style of play', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="style_of_play_key">
                                <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                                <?php foreach ( MethodologyEnums::stylesOfPlay() as $k => $label ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $style, $k ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Way of playing (NL)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="way_nl" rows="4" class="large-text"><?php echo esc_textarea( $way_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Way of playing (EN)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="way_en" rows="4" class="large-text"><?php echo esc_textarea( $way_en ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Important traits (NL — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="traits_nl" rows="4" class="large-text"><?php echo esc_textarea( $traits_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Important traits (EN — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="traits_en" rows="4" class="large-text"><?php echo esc_textarea( $traits_en ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Notes (NL)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="notes_nl" rows="3" class="large-text"><?php echo esc_textarea( $notes_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Notes (EN)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="notes_en" rows="3" class="large-text"><?php echo esc_textarea( $notes_en ); ?></textarea></td>
                    </tr>
                </table>
                <?php if ( $row ) MediaPicker::render( MethodologyAssetsRepository::TYPE_VISION, (int) $row->id ); ?>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Save vision', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_vision_save', 'tt_methodology_nonce' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $payload = [
            'formation_id'      => absint( $_POST['formation_id'] ?? 0 ) ?: null,
            'style_of_play_key' => sanitize_key( (string) wp_unslash( $_POST['style_of_play_key'] ?? '' ) ) ?: null,
            'way_of_playing_json' => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['way_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['way_en'] ?? '' ) ) ),
            ] ),
            'important_traits_json' => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $_POST['traits_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $_POST['traits_en'] ?? '' ) ),
            ] ),
            'notes_json' => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['notes_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['notes_en'] ?? '' ) ) ),
            ] ),
        ];

        $repo = new MethodologyVisionRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) {
                wp_die( esc_html__( 'The shipped sample vision is read-only.', 'talenttrack' ) );
            }
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $payload['club_scope'] = 'site';
            $id = $repo->create( $payload );
        }

        MediaPicker::handleSave( MethodologyAssetsRepository::TYPE_VISION, (int) $id );

        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'vision', 'tt_msg' => 'saved' ],
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
