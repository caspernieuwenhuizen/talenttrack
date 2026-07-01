<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FormationsRepository;
use TT\Modules\Methodology\Repositories\MethodologyVisionRepository;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * VisionManageTab (#2226) — the club vision authoring tab.
 *
 * Unlike PrinciplesManageTab (a list ⇄ form tab), the vision is a
 * SINGLETON: one editable record per club. So this tab renders a single
 * edit form directly — no list, no "+ New", no delete. It resolves the
 * active club-authored vision, or presents a blank form that creates one
 * on first save. The shipped sample is read-only reference content and is
 * never edited here.
 *
 * Persistence and validation run through MethodologyVisionRepository +
 * MethodologyEnums + MultilingualField — the same domain layer
 * VisionRestController consumes (§4), so a SaaS front end gets identical
 * answers. Mirrors the wp-admin VisionEditPage save.
 */
final class VisionManageTab {

    public const MTAB = 'vision';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Visie', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 10,
        ] );
    }

    // ── render ──────────────────────────────────────────────────────

    /** @param array{action:string,id:int,flash:string} $ctx */
    public static function render( array $ctx ): void {
        $repo = new MethodologyVisionRepository();
        $row  = self::editableRow( $repo );

        $v          = self::formValues( $row );
        $formations = ( new FormationsRepository() )->listAll();
        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <p class="tt-mmg-intro"><?php esc_html_e( 'The club vision is a single record. Edit it here; it appears on the read view’s Vision tab.', 'talenttrack' ); ?></p>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mv-formation"><?php esc_html_e( 'Formation', 'talenttrack' ); ?></label>
                    <select id="tt-mv-formation" class="tt-input" name="formation_id">
                        <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                        <?php foreach ( $formations as $f ) : ?>
                            <option value="<?php echo esc_attr( (string) (int) $f->id ); ?>"<?php selected( $v['formation_id'], (int) $f->id ); ?>>
                                <?php echo esc_html( MultilingualField::string( $f->name_json ) ?: (string) $f->slug ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mv-style"><?php esc_html_e( 'Style of play', 'talenttrack' ); ?></label>
                    <select id="tt-mv-style" class="tt-input" name="style_of_play_key">
                        <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                        <?php foreach ( MethodologyEnums::stylesOfPlay() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['style_of_play_key'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php self::multilingualTextarea( 'way', __( 'Way of playing', 'talenttrack' ), $v['way_nl'], $v['way_en'] ); ?>

            <div class="tt-mmg-ml">
                <h3 class="tt-mmg-ml__label"><?php esc_html_e( 'Important traits (one per line)', 'talenttrack' ); ?></h3>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-mv-traits-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                        <textarea id="tt-mv-traits-nl" class="tt-input" name="traits_nl" rows="4"><?php echo esc_textarea( $v['traits_nl'] ); ?></textarea>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-mv-traits-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                        <textarea id="tt-mv-traits-en" class="tt-input" name="traits_en" rows="4"><?php echo esc_textarea( $v['traits_en'] ); ?></textarea>
                    </div>
                </div>
            </div>

            <?php self::multilingualTextarea( 'notes', __( 'Notes', 'talenttrack' ), $v['notes_nl'], $v['notes_en'] ); ?>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save vision', 'talenttrack' ) : __( 'Create vision', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    /**
     * The club-authored vision row that this tab edits (never the shipped
     * sample). Returns null when the club has not yet authored one — the
     * form then renders blank and creates on first save.
     */
    private static function editableRow( MethodologyVisionRepository $repo ): ?object {
        $active = $repo->activeForClub();
        if ( $active && empty( $active->is_shipped ) ) {
            return $active;
        }
        return null;
    }

    /** Two side-by-side NL/EN textareas for a multilingual long-text field. */
    private static function multilingualTextarea( string $name, string $label, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mv-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mv-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" rows="4"><?php echo esc_textarea( $nl ); ?></textarea>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mv-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mv-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" rows="4"><?php echo esc_textarea( $en ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     *
     * @return array{formation_id:int,style_of_play_key:string,way_nl:string,way_en:string,traits_nl:string,traits_en:string,notes_nl:string,notes_en:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'formation_id'      => (int) ( $row->formation_id ?? 0 ),
            'style_of_play_key' => (string) ( $row->style_of_play_key ?? '' ),
            'way_nl'            => '',
            'way_en'            => '',
            'traits_nl'         => '',
            'traits_en'         => '',
            'notes_nl'          => '',
            'notes_en'          => '',
        ];
        if ( ! $row ) return $v;

        foreach ( [ 'way' => 'way_of_playing_json', 'notes' => 'notes_json' ] as $field => $col ) {
            $decoded = MultilingualField::decode( $row->{$col} ?? null ) ?: [];
            $v[ $field . '_nl' ] = (string) ( $decoded['nl'] ?? '' );
            $v[ $field . '_en' ] = (string) ( $decoded['en'] ?? '' );
        }

        $traits = MultilingualField::decode( $row->important_traits_json ?? null ) ?: [];
        $v['traits_nl'] = is_array( $traits['nl'] ?? null ) ? implode( "\n", $traits['nl'] ) : '';
        $v['traits_en'] = is_array( $traits['en'] ?? null ) ? implode( "\n", $traits['en'] ) : '';

        return $v;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the vision form. Mirrors
     * VisionEditPage::handleSave (§4 — same domain layer). Create when no
     * club-authored row exists yet, update otherwise. No delete: the
     * vision is a singleton and is archived from wp-admin if ever needed.
     *
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    public static function handle( array $post ): array {
        if ( ! current_user_can( MethodologyManageView::CAP ) ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }
        $op = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $id   = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        $repo = new MethodologyVisionRepository();

        $style = sanitize_key( (string) wp_unslash( $post['style_of_play_key'] ?? '' ) );
        if ( $style !== '' && ! MethodologyEnums::isValidStyle( $style ) ) {
            return [ 'flash' => __( 'Please choose a valid style of play.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'formation_id'          => absint( $post['formation_id'] ?? 0 ) ?: null,
            'style_of_play_key'     => $style !== '' ? $style : null,
            'way_of_playing_json'   => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['way_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['way_en'] ?? '' ) ) ),
            ] ),
            'important_traits_json' => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $post['traits_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $post['traits_en'] ?? '' ) ),
            ] ),
            'notes_json'            => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['notes_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['notes_en'] ?? '' ) ) ),
            ] ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That vision could not be saved.', 'talenttrack' ), 'back_to_list' => false ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Vision saved.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload['is_shipped'] = 0;
        $payload['club_scope'] = 'site';
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Vision created.', 'talenttrack' ) : __( 'Could not create the vision.', 'talenttrack' ),
            'back_to_list' => false,
        ];
    }

    /**
     * Split a textarea into a clean list of non-empty trimmed lines.
     * Mirrors VisionEditPage::splitLines.
     *
     * @return string[]
     */
    private static function splitLines( string $raw ): array {
        $parts = preg_split( "/\r?\n/", $raw ) ?: [];
        $out   = [];
        foreach ( $parts as $p ) {
            $clean = trim( sanitize_text_field( $p ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }
}
