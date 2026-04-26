<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;
use TT\Modules\Methodology\Repositories\PhasesRepository;

/**
 * PhaseEditPage — edit a single attacking or defending phase row
 * within a framework primer.
 */
final class PhaseEditPage {

    public const SLUG = 'tt-methodology-phase-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_phase_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new PhasesRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->find( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'Shipped phases are read-only. Clone the framework primer first.', 'talenttrack' ) );
        }

        MediaPicker::enqueueAssets();

        $primer       = ( new FrameworkPrimerRepository() )->activeForClub();
        $primer_id    = (int) ( $row->primer_id ?? ( $primer->id ?? 0 ) );
        $title_dec    = $row ? ( MultilingualField::decode( $row->title_json ) ?: [] ) : [];
        $goal_dec     = $row ? ( MultilingualField::decode( $row->goal_json )  ?: [] ) : [];
        ?>
        <div class="wrap">
            <h1><?php echo $row ? esc_html__( 'Edit phase', 'talenttrack' ) : esc_html__( 'Add phase', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG . '&tab=framework' ) ); ?>">← <?php esc_html_e( 'Back to framework', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_phase_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_phase_save" />
                <input type="hidden" name="primer_id" value="<?php echo (int) $primer_id; ?>" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
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
                        <th><label><?php esc_html_e( 'Phase number (1–4)', 'talenttrack' ); ?></label></th>
                        <td><input type="number" name="phase_number" min="1" max="4" required value="<?php echo (int) ( $row->phase_number ?? 1 ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Title (NL)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="title_nl" class="regular-text" value="<?php echo esc_attr( (string) ( $title_dec['nl'] ?? '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Title (EN)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="title_en" class="regular-text" value="<?php echo esc_attr( (string) ( $title_dec['en'] ?? '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Goal (NL)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="goal_nl" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $goal_dec['nl'] ?? '' ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Goal (EN)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="goal_en" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $goal_dec['en'] ?? '' ) ); ?></textarea></td>
                    </tr>
                </table>
                <?php if ( $row ) MediaPicker::render( MethodologyAssetsRepository::TYPE_PHASE, (int) $row->id ); ?>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Create phase', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_phase_save', 'tt_methodology_nonce' );
        $id        = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $primer_id = absint( $_POST['primer_id'] ?? 0 );
        $payload = [
            'primer_id'    => $primer_id,
            'side'         => sanitize_key( (string) wp_unslash( $_POST['side'] ?? '' ) ),
            'phase_number' => max( 1, min( 4, (int) ( $_POST['phase_number'] ?? 1 ) ) ),
            'title_json'   => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_en'] ?? '' ) ) ),
            ] ),
            'goal_json'    => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['goal_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['goal_en'] ?? '' ) ) ),
            ] ),
        ];
        $repo = new PhasesRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) wp_die( esc_html__( 'Shipped phases are read-only.', 'talenttrack' ) );
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $id = $repo->create( $payload );
        }
        MediaPicker::handleSave( MethodologyAssetsRepository::TYPE_PHASE, (int) $id );
        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'framework', 'tt_msg' => 'saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
