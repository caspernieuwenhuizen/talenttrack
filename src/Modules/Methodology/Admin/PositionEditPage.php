<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FormationsRepository;

/**
 * PositionEditPage — wp-admin form for a club-authored position
 * card. Hidden submenu reached via
 * `?page=tt-methodology-position-edit&action=new|edit&id=...`.
 *
 * The shape mirrors PrincipleEditPage: NL + EN side-by-side for
 * scalar fields; newline-separated textareas for the attacking and
 * defending task lists. Saving splits on newlines and stores as a
 * `{nl: [...], en: [...]}` JSON.
 */
class PositionEditPage {

    public const SLUG = 'tt-methodology-position-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_position_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new FormationsRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->findPosition( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'Shipped positions are read-only. Use Clone & Edit instead.', 'talenttrack' ) );
        }

        $formations = $repo->listAll();
        $short_nl = $short_en = $long_nl = $long_en = '';
        $att_nl = $att_en = $def_nl = $def_en = '';

        if ( $row ) {
            $short_decoded = MultilingualField::decode( $row->short_name_json ) ?: [];
            $long_decoded  = MultilingualField::decode( $row->long_name_json )  ?: [];
            $att_decoded   = MultilingualField::decode( $row->attacking_tasks_json ) ?: [];
            $def_decoded   = MultilingualField::decode( $row->defending_tasks_json ) ?: [];
            $short_nl = (string) ( $short_decoded['nl'] ?? '' );
            $short_en = (string) ( $short_decoded['en'] ?? '' );
            $long_nl  = (string) ( $long_decoded['nl']  ?? '' );
            $long_en  = (string) ( $long_decoded['en']  ?? '' );
            $att_nl   = is_array( $att_decoded['nl'] ?? null ) ? implode( "\n", $att_decoded['nl'] ) : '';
            $att_en   = is_array( $att_decoded['en'] ?? null ) ? implode( "\n", $att_decoded['en'] ) : '';
            $def_nl   = is_array( $def_decoded['nl'] ?? null ) ? implode( "\n", $def_decoded['nl'] ) : '';
            $def_en   = is_array( $def_decoded['en'] ?? null ) ? implode( "\n", $def_decoded['en'] ) : '';
        }
        ?>
        <div class="wrap">
            <h1><?php echo $row ? esc_html__( 'Edit position', 'talenttrack' ) : esc_html__( 'Add position', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG . '&tab=formations' ) ); ?>">← <?php esc_html_e( 'Back to formations', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_position_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_position_save" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tt_pos_formation"><?php esc_html_e( 'Formation', 'talenttrack' ); ?></label></th>
                        <td>
                            <select id="tt_pos_formation" name="formation_id" required>
                                <?php foreach ( $formations as $f ) : ?>
                                    <option value="<?php echo (int) $f->id; ?>" <?php selected( (int) ( $row->formation_id ?? 0 ), (int) $f->id ); ?>>
                                        <?php echo esc_html( MultilingualField::string( $f->name_json ) ?: $f->slug ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_pos_jersey"><?php esc_html_e( 'Jersey number', 'talenttrack' ); ?></label></th>
                        <td><input type="number" id="tt_pos_jersey" name="jersey_number" min="1" max="11" required value="<?php echo esc_attr( (string) ( $row->jersey_number ?? 1 ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Short name (NL)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="short_nl" class="regular-text" value="<?php echo esc_attr( $short_nl ); ?>" placeholder="Vleugelverdediger" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Short name (EN)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="short_en" class="regular-text" value="<?php echo esc_attr( $short_en ); ?>" placeholder="Wing-back" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Long name (NL)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="long_nl" class="regular-text" value="<?php echo esc_attr( $long_nl ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Long name (EN)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="long_en" class="regular-text" value="<?php echo esc_attr( $long_en ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Attacking tasks (NL — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="att_nl" rows="6" class="large-text"><?php echo esc_textarea( $att_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Attacking tasks (EN — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="att_en" rows="6" class="large-text"><?php echo esc_textarea( $att_en ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Defending tasks (NL — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="def_nl" rows="6" class="large-text"><?php echo esc_textarea( $def_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Defending tasks (EN — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="def_en" rows="6" class="large-text"><?php echo esc_textarea( $def_en ); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Create position', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_position_save', 'tt_methodology_nonce' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $payload = [
            'formation_id'        => absint( $_POST['formation_id'] ?? 0 ),
            'jersey_number'       => max( 1, min( 11, (int) ( $_POST['jersey_number'] ?? 1 ) ) ),
            'short_name_json'     => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['short_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['short_en'] ?? '' ) ) ),
            ] ),
            'long_name_json'      => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['long_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['long_en'] ?? '' ) ) ),
            ] ),
            'attacking_tasks_json' => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $_POST['att_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $_POST['att_en'] ?? '' ) ),
            ] ),
            'defending_tasks_json' => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $_POST['def_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $_POST['def_en'] ?? '' ) ),
            ] ),
        ];

        $repo = new FormationsRepository();
        if ( $id > 0 ) {
            $existing = $repo->findPosition( $id );
            if ( $existing && $existing->is_shipped ) {
                wp_die( esc_html__( 'Shipped positions are read-only.', 'talenttrack' ) );
            }
            $repo->updatePosition( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $id = $repo->createPosition( $payload );
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'formations', 'formation_id' => $payload['formation_id'], 'position_id' => $id, 'tt_msg' => 'saved' ],
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
