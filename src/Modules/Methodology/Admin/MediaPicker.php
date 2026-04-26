<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;

/**
 * MediaPicker — shared admin component for attaching diagrams /
 * photos to a methodology entity.
 *
 * Renders inside an existing edit form (no separate AJAX). The
 * component reads the current `tt_methodology_assets` rows for the
 * (entity_type, entity_id) pair, displays them as thumbnails with
 * inline controls, and opens the WP media library for adding new
 * images. Form submission carries:
 *
 *   - tt_assets_add[]      — attachment IDs to add as new asset rows
 *   - tt_assets_remove[]   — existing asset IDs to archive
 *   - tt_assets_primary    — asset ID to mark as primary (single value)
 *   - tt_assets_caption[<asset_id>] — partial multilingual caption
 *
 * `handleSave()` applies all four collections in one transactionless
 * sweep. The caller invokes it after their main entity save completes.
 *
 * Why no AJAX: the existing methodology edit pages all use a single
 * form that posts to admin-post.php and redirects back. Keeping the
 * picker inside that form avoids drift between two save paths.
 */
final class MediaPicker {

    public static function enqueueAssets(): void {
        if ( ! did_action( 'wp_enqueue_media' ) ) wp_enqueue_media();
        wp_enqueue_script(
            'tt-methodology-media-picker',
            plugins_url( 'assets/js/admin-methodology-media-picker.js', TT_PLUGIN_FILE ),
            [ 'jquery' ],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-methodology-media-picker', 'TT_MethodologyMedia', [
            'modalTitle'  => __( 'Selecteer een diagram of afbeelding',  'talenttrack' ),
            'modalButton' => __( 'Toevoegen aan methodologie',            'talenttrack' ),
        ] );
    }

    public static function render( string $entity_type, int $entity_id ): void {
        $repo   = new MethodologyAssetsRepository();
        $assets = $entity_id > 0 ? $repo->listFor( $entity_type, $entity_id ) : [];

        $caption_tag = 'data-tt-asset-caption';
        ?>
        <div class="tt-methodology-media" data-entity-type="<?php echo esc_attr( $entity_type ); ?>" data-entity-id="<?php echo (int) $entity_id; ?>">
            <h3 style="margin-top:24px;"><?php esc_html_e( 'Diagrammen en afbeeldingen', 'talenttrack' ); ?></h3>
            <p class="description" style="margin:4px 0 12px; color:#5b6470;">
                <?php esc_html_e( 'De primaire afbeelding wordt boven aan deze entiteit getoond. Klik op een thumbnail om \'m als primair te markeren of te archiveren.', 'talenttrack' ); ?>
            </p>

            <div class="tt-methodology-media-list" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:12px;">
                <?php if ( empty( $assets ) ) : ?>
                    <p class="description" style="grid-column:1/-1; padding:24px; background:#fafafa; border:1px dashed #d4d6db; text-align:center; color:#5b6470; margin:0;">
                        <?php esc_html_e( 'Nog geen afbeeldingen toegevoegd. Voeg een diagram toe via "Afbeelding kiezen" hieronder.', 'talenttrack' ); ?>
                    </p>
                <?php else : foreach ( $assets as $asset ) :
                    $thumb = wp_get_attachment_image_src( (int) $asset->attachment_id, 'medium' );
                    $thumb_url = $thumb ? (string) $thumb[0] : (string) wp_get_attachment_url( (int) $asset->attachment_id );
                    $caption_nl = '';
                    $caption_en = '';
                    if ( ! empty( $asset->caption_json ) ) {
                        $cap = MultilingualField::decode( $asset->caption_json ) ?: [];
                        $caption_nl = (string) ( $cap['nl'] ?? '' );
                        $caption_en = (string) ( $cap['en'] ?? '' );
                    }
                    ?>
                    <div class="tt-methodology-media-card" style="border:1px solid #e0e2e7; border-radius:6px; overflow:hidden; background:#fff; display:flex; flex-direction:column;">
                        <div style="position:relative; aspect-ratio:1.3/1; background:#f6f7f9; overflow:hidden;">
                            <?php if ( $thumb_url ) : ?>
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" style="width:100%; height:100%; object-fit:contain;" />
                            <?php endif; ?>
                            <?php if ( ! empty( $asset->is_primary ) ) : ?>
                                <span style="position:absolute; top:6px; left:6px; background:#0a7c41; color:#fff; padding:3px 8px; font-size:11px; border-radius:3px;">
                                    <?php esc_html_e( 'Primair', 'talenttrack' ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( ! empty( $asset->is_shipped ) ) : ?>
                                <span style="position:absolute; top:6px; right:6px; background:#1a4a8a; color:#fff; padding:3px 8px; font-size:11px; border-radius:3px;">
                                    <?php esc_html_e( 'Shipped', 'talenttrack' ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="padding:10px; display:flex; flex-direction:column; gap:6px;">
                            <details>
                                <summary style="cursor:pointer; font-size:12px; color:#5b6470;"><?php esc_html_e( 'Bijschrift bewerken', 'talenttrack' ); ?></summary>
                                <label style="display:block; margin-top:6px; font-size:11px; color:#5b6470;">NL</label>
                                <input type="text" name="<?php echo esc_attr( 'tt_assets_caption[' . (int) $asset->id . '][nl]' ); ?>" value="<?php echo esc_attr( $caption_nl ); ?>" style="width:100%;" />
                                <label style="display:block; margin-top:6px; font-size:11px; color:#5b6470;">EN</label>
                                <input type="text" name="<?php echo esc_attr( 'tt_assets_caption[' . (int) $asset->id . '][en]' ); ?>" value="<?php echo esc_attr( $caption_en ); ?>" style="width:100%;" />
                            </details>
                            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;">
                                <?php if ( empty( $asset->is_primary ) ) : ?>
                                    <button type="submit" class="button button-small" name="tt_assets_primary" value="<?php echo (int) $asset->id; ?>">
                                        <?php esc_html_e( 'Maak primair', 'talenttrack' ); ?>
                                    </button>
                                <?php endif; ?>
                                <button type="submit" class="button button-small" name="tt_assets_remove[]" value="<?php echo (int) $asset->id; ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Verwijder deze afbeelding?', 'talenttrack' ) ); ?>');">
                                    <?php esc_html_e( 'Archiveren', 'talenttrack' ); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <p style="margin-top:12px;">
                <button type="button" class="button" data-tt-methodology-media-add>
                    <?php esc_html_e( 'Afbeelding kiezen…', 'talenttrack' ); ?>
                </button>
                <span class="description" style="margin-left:8px; color:#5b6470;">
                    <?php esc_html_e( 'Opent de WordPress mediabibliotheek. Geüploade afbeeldingen zijn meteen herbruikbaar.', 'talenttrack' ); ?>
                </span>
            </p>

            <div data-tt-methodology-media-staged style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;"></div>
        </div>
        <?php
    }

    /**
     * Apply add / remove / primary / caption changes from a submitted
     * form. Should be invoked from the entity edit page's save
     * handler AFTER the entity itself has been created/updated and
     * `$entity_id` is known.
     */
    public static function handleSave( string $entity_type, int $entity_id ): void {
        if ( $entity_id <= 0 ) return;
        $repo = new MethodologyAssetsRepository();

        // Add new attachments.
        if ( isset( $_POST['tt_assets_add'] ) && is_array( $_POST['tt_assets_add'] ) ) {
            foreach ( (array) $_POST['tt_assets_add'] as $att_id ) {
                $att_id = (int) $att_id;
                if ( $att_id <= 0 ) continue;
                if ( ! current_user_can( 'upload_files' ) ) break;
                $repo->create( [
                    'entity_type'   => $entity_type,
                    'entity_id'     => $entity_id,
                    'attachment_id' => $att_id,
                    'is_primary'    => 0,
                ] );
            }
        }

        // Archive (remove) existing.
        if ( isset( $_POST['tt_assets_remove'] ) && is_array( $_POST['tt_assets_remove'] ) ) {
            foreach ( (array) $_POST['tt_assets_remove'] as $asset_id ) {
                $asset_id = (int) $asset_id;
                if ( $asset_id <= 0 ) continue;
                $repo->archive( $asset_id );
            }
        }

        // Captions.
        if ( isset( $_POST['tt_assets_caption'] ) && is_array( $_POST['tt_assets_caption'] ) ) {
            foreach ( (array) $_POST['tt_assets_caption'] as $asset_id => $payload ) {
                $asset_id = (int) $asset_id;
                if ( $asset_id <= 0 || ! is_array( $payload ) ) continue;
                $clean = [];
                foreach ( [ 'nl', 'en' ] as $loc ) {
                    if ( isset( $payload[ $loc ] ) ) $clean[ $loc ] = sanitize_text_field( wp_unslash( (string) $payload[ $loc ] ) );
                }
                if ( ! empty( $clean ) ) $repo->update( $asset_id, [ 'caption_json' => $clean ] );
            }
        }

        // Primary toggle.
        if ( isset( $_POST['tt_assets_primary'] ) ) {
            $primary_id = (int) $_POST['tt_assets_primary'];
            if ( $primary_id > 0 ) $repo->setPrimary( $entity_type, $entity_id, $primary_id );
        }
    }
}
