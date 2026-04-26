<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\LearningGoalsRepository;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;

/**
 * LearningGoalEditPage — edit a single learning goal row inside the
 * framework primer. Bullets are newline-separated textareas same
 * pattern as set pieces and positions.
 */
final class LearningGoalEditPage {

    public const SLUG = 'tt-methodology-learning-goal-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_learning_goal_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new LearningGoalsRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->find( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'Shipped learning goals are read-only.', 'talenttrack' ) );
        }

        MediaPicker::enqueueAssets();

        $primer    = ( new FrameworkPrimerRepository() )->activeForClub();
        $primer_id = (int) ( $row->primer_id ?? ( $primer->id ?? 0 ) );
        $title_dec   = $row ? ( MultilingualField::decode( $row->title_json )   ?: [] ) : [];
        $bullets_dec = $row ? ( MultilingualField::decode( $row->bullets_json ) ?: [] ) : [];
        $bullets_nl  = is_array( $bullets_dec['nl'] ?? null ) ? implode( "\n", $bullets_dec['nl'] ) : '';
        $bullets_en  = is_array( $bullets_dec['en'] ?? null ) ? implode( "\n", $bullets_dec['en'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo $row ? esc_html__( 'Edit learning goal', 'talenttrack' ) : esc_html__( 'Add learning goal', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG . '&tab=framework' ) ); ?>">← <?php esc_html_e( 'Back to framework', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_learning_goal_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_learning_goal_save" />
                <input type="hidden" name="primer_id" value="<?php echo (int) $primer_id; ?>" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="slug" class="regular-text" required value="<?php echo esc_attr( (string) ( $row->slug ?? '' ) ); ?>" placeholder="positiespel-verbeteren" /></td>
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
                        <th><label><?php esc_html_e( 'Linked team-task (optional)', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="team_task_key">
                                <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                                <?php foreach ( MethodologyEnums::teamTasks() as $k => $label ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) ( $row->team_task_key ?? '' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
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
                        <th><label><?php esc_html_e( 'Bullets (NL — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="bullets_nl" rows="6" class="large-text"><?php echo esc_textarea( $bullets_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Bullets (EN — one per line)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="bullets_en" rows="6" class="large-text"><?php echo esc_textarea( $bullets_en ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label></th>
                        <td><input type="number" name="sort_order" value="<?php echo (int) ( $row->sort_order ?? 0 ); ?>" /></td>
                    </tr>
                </table>
                <?php if ( $row ) MediaPicker::render( MethodologyAssetsRepository::TYPE_LEARNING_GOAL, (int) $row->id ); ?>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Create learning goal', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_learning_goal_save', 'tt_methodology_nonce' );
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $primer_id = absint( $_POST['primer_id'] ?? 0 );
        $payload = [
            'primer_id'     => $primer_id,
            'slug'          => sanitize_key( (string) wp_unslash( $_POST['slug'] ?? '' ) ),
            'side'          => sanitize_key( (string) wp_unslash( $_POST['side'] ?? '' ) ),
            'team_task_key' => sanitize_key( (string) wp_unslash( $_POST['team_task_key'] ?? '' ) ) ?: null,
            'sort_order'    => (int) ( $_POST['sort_order'] ?? 0 ),
            'title_json'    => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_en'] ?? '' ) ) ),
            ] ),
            'bullets_json'  => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $_POST['bullets_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $_POST['bullets_en'] ?? '' ) ),
            ] ),
        ];
        $repo = new LearningGoalsRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) wp_die( esc_html__( 'Shipped learning goals are read-only.', 'talenttrack' ) );
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $id = $repo->create( $payload );
        }
        MediaPicker::handleSave( MethodologyAssetsRepository::TYPE_LEARNING_GOAL, (int) $id );
        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'framework', 'tt_msg' => 'saved' ],
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
