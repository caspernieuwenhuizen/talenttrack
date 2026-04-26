<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\InfluenceFactorsRepository;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;

/**
 * InfluenceFactorEditPage — edit a single factor of influence row.
 *
 * Sub-factors (the smaller cards inside one factor) are edited as a
 * single JSON textarea — keeps the form simple while still letting
 * Casper add or remove cards. Each sub-factor row has the shape
 *   [{ slug, title: { nl, en }, description: { nl, en } }, …]
 * and the helper at the bottom of this class does friendly parsing
 * for hand-edited input.
 */
final class InfluenceFactorEditPage {

    public const SLUG = 'tt-methodology-factor-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_factor_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new InfluenceFactorsRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->find( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'Shipped influence factors are read-only.', 'talenttrack' ) );
        }

        MediaPicker::enqueueAssets();

        $primer    = ( new FrameworkPrimerRepository() )->activeForClub();
        $primer_id = (int) ( $row->primer_id ?? ( $primer->id ?? 0 ) );
        $title_dec = $row ? ( MultilingualField::decode( $row->title_json )       ?: [] ) : [];
        $desc_dec  = $row ? ( MultilingualField::decode( $row->description_json ) ?: [] ) : [];
        $sub_json  = $row && ! empty( $row->sub_factors_json )
            ? wp_json_encode( json_decode( $row->sub_factors_json, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            : '';
        ?>
        <div class="wrap">
            <h1><?php echo $row ? esc_html__( 'Edit influence factor', 'talenttrack' ) : esc_html__( 'Add influence factor', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG . '&tab=framework' ) ); ?>">← <?php esc_html_e( 'Back to framework', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_factor_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_factor_save" />
                <input type="hidden" name="primer_id" value="<?php echo (int) $primer_id; ?>" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="slug" class="regular-text" required value="<?php echo esc_attr( (string) ( $row->slug ?? '' ) ); ?>" placeholder="spelers" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label></th>
                        <td><input type="number" name="sort_order" value="<?php echo (int) ( $row->sort_order ?? 0 ); ?>" /></td>
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
                        <th><label><?php esc_html_e( 'Description (NL)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="description_nl" rows="4" class="large-text"><?php echo esc_textarea( (string) ( $desc_dec['nl'] ?? '' ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Description (EN)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="description_en" rows="4" class="large-text"><?php echo esc_textarea( (string) ( $desc_dec['en'] ?? '' ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Sub-factors (JSON)', 'talenttrack' ); ?></label></th>
                        <td>
                            <textarea name="sub_factors" rows="14" class="large-text code" placeholder='[{"slug":"spelers","title":{"nl":"Spelers","en":"Players"},"description":{"nl":"...","en":"..."}}]'><?php echo esc_textarea( $sub_json ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Optional JSON array of sub-cards. Each entry needs slug + title (nl/en) + description (nl/en). Leave empty for no sub-cards.', 'talenttrack' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php if ( $row ) MediaPicker::render( MethodologyAssetsRepository::TYPE_INFLUENCE_FACTOR, (int) $row->id ); ?>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Create influence factor', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_factor_save', 'tt_methodology_nonce' );
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $primer_id = absint( $_POST['primer_id'] ?? 0 );

        $sub_raw = trim( (string) wp_unslash( $_POST['sub_factors'] ?? '' ) );
        $sub_factors = null;
        if ( $sub_raw !== '' ) {
            $decoded = json_decode( $sub_raw, true );
            if ( is_array( $decoded ) ) $sub_factors = self::sanitizeSubFactors( $decoded );
        }

        $payload = [
            'primer_id'        => $primer_id,
            'slug'             => sanitize_key( (string) wp_unslash( $_POST['slug'] ?? '' ) ),
            'sort_order'       => (int) ( $_POST['sort_order'] ?? 0 ),
            'title_json'       => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_en'] ?? '' ) ) ),
            ] ),
            'description_json' => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description_en'] ?? '' ) ) ),
            ] ),
            'sub_factors_json' => $sub_factors !== null ? wp_json_encode( $sub_factors ) : null,
        ];

        $repo = new InfluenceFactorsRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) wp_die( esc_html__( 'Shipped influence factors are read-only.', 'talenttrack' ) );
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $id = $repo->create( $payload );
        }
        MediaPicker::handleSave( MethodologyAssetsRepository::TYPE_INFLUENCE_FACTOR, (int) $id );
        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'framework', 'tt_msg' => 'saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /** @param array<int, mixed> $list */
    private static function sanitizeSubFactors( array $list ): array {
        $out = [];
        foreach ( $list as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $slug  = isset( $entry['slug'] ) ? sanitize_key( (string) $entry['slug'] ) : '';
            if ( $slug === '' ) continue;
            $title_nl = isset( $entry['title']['nl'] ) ? sanitize_text_field( (string) $entry['title']['nl'] ) : '';
            $title_en = isset( $entry['title']['en'] ) ? sanitize_text_field( (string) $entry['title']['en'] ) : '';
            $desc_nl  = isset( $entry['description']['nl'] ) ? sanitize_textarea_field( (string) $entry['description']['nl'] ) : '';
            $desc_en  = isset( $entry['description']['en'] ) ? sanitize_textarea_field( (string) $entry['description']['en'] ) : '';
            $out[] = [
                'slug'        => $slug,
                'title'       => [ 'nl' => $title_nl, 'en' => $title_en ],
                'description' => [ 'nl' => $desc_nl,  'en' => $desc_en ],
            ];
        }
        return $out;
    }
}
