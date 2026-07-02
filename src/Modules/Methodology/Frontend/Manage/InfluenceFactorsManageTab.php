<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\InfluenceFactorsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * InfluenceFactorsManageTab (#2229) — the influence-factors authoring tab,
 * built on the #2225 scaffold. Mirrors SetPiecesManageTab: register() a
 * render + handle callable into MethodologyManageRegistry, then dispatch a
 * list ⇄ flat create/edit form on the frame-supplied action.
 *
 * An influence factor (factor van invloed) is a child of the framework
 * primer: it carries a slug, a multilingual title + description and an
 * optional array of sub-factor cards edited as a single JSON textarea
 * (each entry: {slug, title:{nl,en}, description:{nl,en}}). No business
 * logic lives here beyond composition — persistence + sub-factor
 * sanitising run through InfluenceFactorsRepository, the same domain layer
 * InfluenceFactorsRestController consumes (§4). Mirrors
 * InfluenceFactorEditPage.
 */
final class InfluenceFactorsManageTab {

    public const MTAB = 'influence_factors';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Factoren van invloed', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 45,
        ] );
    }

    // ── render ──────────────────────────────────────────────────────

    /** @param array{action:string,id:int,flash:string} $ctx */
    public static function render( array $ctx ): void {
        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            self::renderNoPrimer();
            return;
        }

        $action = (string) ( $ctx['action'] ?? 'list' );
        $id     = (int) ( $ctx['id'] ?? 0 );

        if ( $action === 'new' || ( $action === 'edit' && $id > 0 ) ) {
            self::renderForm( (int) $primer->id, $id );
            return;
        }
        self::renderList( (int) $primer->id );
    }

    private static function renderNoPrimer(): void {
        echo '<p class="tt-notice">'
            . esc_html__( 'Author the framework primer first — influence factors hang off it. Open the Raamwerk tab and save the primer.', 'talenttrack' )
            . '</p>';
        echo '<a class="tt-btn tt-btn-secondary" href="'
            . esc_url( MethodologyManageView::tabUrl( FrameworkPrimerManageTab::MTAB ) ) . '">'
            . esc_html__( 'Go to Raamwerk', 'talenttrack' ) . '</a>';
    }

    private static function renderList( int $primer_id ): void {
        echo '<div class="tt-mmg-toolbar">';
        echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="'
            . esc_url( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'new' ] ) ) . '">'
            . esc_html__( '+ New influence factor', 'talenttrack' ) . '</a>';
        echo '</div>';

        $factors = ( new InfluenceFactorsRepository() )->listForPrimer( $primer_id );
        if ( empty( $factors ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No influence factors yet. Use “+ New influence factor” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-mmg-list">';
        foreach ( $factors as $factor ) {
            $shipped  = ! empty( $factor->is_shipped );
            $title    = MultilingualField::string( $factor->title_json );
            $edit_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $factor->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<span class="tt-mmg-row__code"><code>' . esc_html( (string) $factor->slug ) . '</code></span>';
            echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                . esc_html( $title !== '' ? $title : __( '(untitled)', 'talenttrack' ) ) . '</a>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            if ( $shipped ) {
                echo '<span class="tt-mmg-readonly">' . esc_html__( 'Read-only', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
                echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                    . esc_attr( wp_json_encode( __( 'Delete this influence factor? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $factor->id ) . '" />';
                echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                    . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function renderForm( int $primer_id, int $id ): void {
        $repo = new InfluenceFactorsRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That influence factor could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped influence factors are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $v = self::formValues( $row );
        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <input type="hidden" name="primer_id" value="<?php echo esc_attr( (string) $primer_id ); ?>" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-if-slug"><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-if-slug" class="tt-input" name="slug" maxlength="64" required
                           value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="spelers" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-if-sort"><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label>
                    <input type="number" inputmode="numeric" id="tt-if-sort" class="tt-input" name="sort_order" value="<?php echo esc_attr( (string) $v['sort_order'] ); ?>" />
                </div>
            </div>

            <?php self::multilingualText( 'title', __( 'Title', 'talenttrack' ), $v['title_nl'], $v['title_en'] ); ?>

            <div class="tt-mmg-ml">
                <h3 class="tt-mmg-ml__label"><?php esc_html_e( 'Description', 'talenttrack' ); ?></h3>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-if-desc-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                        <textarea id="tt-if-desc-nl" class="tt-input" name="description_nl" rows="4"><?php echo esc_textarea( $v['description_nl'] ); ?></textarea>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-if-desc-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                        <textarea id="tt-if-desc-en" class="tt-input" name="description_en" rows="4"><?php echo esc_textarea( $v['description_en'] ); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-if-sub"><?php esc_html_e( 'Sub-factors (JSON)', 'talenttrack' ); ?></label>
                <textarea id="tt-if-sub" class="tt-input tt-mmg-code" name="sub_factors" rows="12"
                          spellcheck="false" placeholder='[{"slug":"spelers","title":{"nl":"Spelers","en":"Players"},"description":{"nl":"...","en":"..."}}]'><?php echo esc_textarea( $v['sub_factors'] ); ?></textarea>
                <p class="tt-field-hint"><?php esc_html_e( 'Optional JSON array of sub-cards. Each entry needs slug + title (nl/en) + description (nl/en). Leave empty for no sub-cards.', 'talenttrack' ); ?></p>
            </div>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save influence factor', 'talenttrack' ) : __( 'Create influence factor', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    /** Two side-by-side NL/EN text inputs for a multilingual string field. */
    private static function multilingualText( string $name, string $label, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-if-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-if-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-if-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-if-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values. The
     * sub-factors are surfaced as pretty-printed JSON for editing.
     *
     * @return array{slug:string,sort_order:int,title_nl:string,title_en:string,description_nl:string,description_en:string,sub_factors:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'slug'           => (string) ( $row->slug ?? '' ),
            'sort_order'     => (int) ( $row->sort_order ?? 0 ),
            'title_nl'       => '',
            'title_en'       => '',
            'description_nl' => '',
            'description_en' => '',
            'sub_factors'    => '',
        ];
        if ( ! $row ) return $v;

        $title = MultilingualField::decode( $row->title_json ?? null ) ?: [];
        $v['title_nl'] = (string) ( $title['nl'] ?? '' );
        $v['title_en'] = (string) ( $title['en'] ?? '' );

        $desc = MultilingualField::decode( $row->description_json ?? null ) ?: [];
        $v['description_nl'] = (string) ( $desc['nl'] ?? '' );
        $v['description_en'] = (string) ( $desc['en'] ?? '' );

        $sub = MultilingualField::decode( $row->sub_factors_json ?? null );
        if ( is_array( $sub ) && ! empty( $sub ) ) {
            $v['sub_factors'] = (string) wp_json_encode( $sub, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        return $v;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (create / edit / delete).
     * Mirrors InfluenceFactorEditPage::handleSave (§4 — same domain layer).
     *
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    public static function handle( array $post ): array {
        if ( ! current_user_can( MethodologyManageView::CAP ) ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }
        $op   = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
        $id   = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        $repo = new InfluenceFactorsRepository();

        if ( $op === 'delete' ) {
            if ( $id <= 0 || ! $repo->delete( $id ) ) {
                return [ 'flash' => __( 'That influence factor could not be deleted. Shipped reference factors cannot be removed.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Influence factor deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $slug = sanitize_key( (string) wp_unslash( $post['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return [ 'flash' => __( 'An influence factor needs a slug.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $sub_raw = trim( (string) wp_unslash( $post['sub_factors'] ?? '' ) );
        $sub_factors = null;
        if ( $sub_raw !== '' ) {
            $decoded = json_decode( $sub_raw, true );
            if ( is_array( $decoded ) ) $sub_factors = self::sanitizeSubFactors( $decoded );
        }

        $payload = [
            'slug'             => $slug,
            'sort_order'       => (int) ( $post['sort_order'] ?? 0 ),
            'title_json'       => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['title_en'] ?? '' ) ) ),
            ] ),
            'description_json' => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_en'] ?? '' ) ) ),
            ] ),
            'sub_factors_json' => $sub_factors !== null ? (string) wp_json_encode( $sub_factors ) : '',
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That influence factor could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Influence factor saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['primer_id']  = absint( $post['primer_id'] ?? 0 );
        $payload['is_shipped'] = 0;
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Influence factor created.', 'talenttrack' ) : __( 'Could not create the influence factor.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }

    /**
     * Sanitize a sub-factor list. Each valid entry needs a slug and
     * multilingual title + description. Mirrors
     * InfluenceFactorEditPage::sanitizeSubFactors.
     *
     * @param array<int,mixed> $list
     * @return array<int,array{slug:string,title:array{nl:string,en:string},description:array{nl:string,en:string}}>
     */
    private static function sanitizeSubFactors( array $list ): array {
        $out = [];
        foreach ( $list as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $slug = isset( $entry['slug'] ) ? sanitize_key( (string) $entry['slug'] ) : '';
            if ( $slug === '' ) continue;
            $out[] = [
                'slug'        => $slug,
                'title'       => [
                    'nl' => isset( $entry['title']['nl'] ) ? sanitize_text_field( (string) $entry['title']['nl'] ) : '',
                    'en' => isset( $entry['title']['en'] ) ? sanitize_text_field( (string) $entry['title']['en'] ) : '',
                ],
                'description' => [
                    'nl' => isset( $entry['description']['nl'] ) ? sanitize_textarea_field( (string) $entry['description']['nl'] ) : '',
                    'en' => isset( $entry['description']['en'] ) ? sanitize_textarea_field( (string) $entry['description']['en'] ) : '',
                ],
            ];
        }
        return $out;
    }
}
