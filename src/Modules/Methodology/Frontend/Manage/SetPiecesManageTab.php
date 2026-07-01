<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\SetPiecesRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * SetPiecesManageTab (#2228) — the set-pieces manage tab, built on the
 * #2225 scaffold. Mirrors PrinciplesManageTab: register() a render +
 * handle callable into MethodologyManageRegistry, then dispatch a
 * list ⇄ flat create/edit form on the frame-supplied action.
 *
 * A set piece carries a slug, a kind (corner / free-kick / …) and a side
 * (attacking / defending / transition), a multilingual title, a
 * multilingual bullet list (newline-separated per language) and an
 * optional diagram-overlay JSON blob. No business logic lives here beyond
 * composition — the closed kind/side taxonomies and persistence run
 * through MethodologyEnums + SetPiecesRepository, the same domain layer
 * SetPiecesRestController consumes (§4).
 */
final class SetPiecesManageTab {

    public const MTAB = 'set_pieces';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Spelhervattingen', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 50,
        ] );
    }

    // ── render ──────────────────────────────────────────────────────

    /** @param array{action:string,id:int,flash:string} $ctx */
    public static function render( array $ctx ): void {
        $action = (string) ( $ctx['action'] ?? 'list' );
        $id     = (int) ( $ctx['id'] ?? 0 );

        if ( $action === 'new' || ( $action === 'edit' && $id > 0 ) ) {
            self::renderForm( $id );
            return;
        }
        self::renderList();
    }

    private static function renderList(): void {
        echo '<div class="tt-mmg-toolbar">';
        echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="'
            . esc_url( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'new' ] ) ) . '">'
            . esc_html__( '+ New set piece', 'talenttrack' ) . '</a>';
        echo '</div>';

        $set_pieces = ( new SetPiecesRepository() )->listFiltered();
        if ( empty( $set_pieces ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No set pieces yet. Use “+ New set piece” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        $kinds = MethodologyEnums::setPieceKinds();
        $sides = MethodologyEnums::sides();

        echo '<ul class="tt-mmg-list">';
        foreach ( $set_pieces as $sp ) {
            $shipped  = ! empty( $sp->is_shipped );
            $title    = MultilingualField::string( $sp->title_json );
            $edit_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $sp->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<span class="tt-mmg-row__code"><code>' . esc_html( (string) $sp->slug ) . '</code></span>';
            echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                . esc_html( $title !== '' ? $title : __( '(untitled)', 'talenttrack' ) ) . '</a>';
            echo '<span class="tt-mmg-row__meta">'
                . esc_html( ( $kinds[ (string) $sp->kind_key ] ?? '' ) . ' · ' . ( $sides[ (string) $sp->side ] ?? '' ) )
                . '</span>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            if ( $shipped ) {
                // Shipped rows are read-only reference content — no frontend
                // edit / delete. The read view surfaces them; club authoring
                // acts on club-authored rows only.
                echo '<span class="tt-mmg-readonly">' . esc_html__( 'Read-only', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
                echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                    . esc_attr( wp_json_encode( __( 'Delete this set piece? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $sp->id ) . '" />';
                echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                    . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function renderForm( int $id ): void {
        $repo = new SetPiecesRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That set piece could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped set pieces are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $v = self::formValues( $row );

        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-sp-slug"><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label>
                <input type="text" id="tt-sp-slug" class="tt-input" name="slug" maxlength="120" required
                       value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="corner-attacking-far-post" />
            </div>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sp-kind"><?php esc_html_e( 'Kind', 'talenttrack' ); ?></label>
                    <select id="tt-sp-kind" class="tt-input" name="kind_key" required>
                        <?php foreach ( MethodologyEnums::setPieceKinds() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['kind_key'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sp-side"><?php esc_html_e( 'Side', 'talenttrack' ); ?></label>
                    <select id="tt-sp-side" class="tt-input" name="side" required>
                        <?php foreach ( MethodologyEnums::sides() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['side'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php self::multilingualText( 'title', __( 'Title', 'talenttrack' ), $v['title_nl'], $v['title_en'] ); ?>

            <div class="tt-mmg-ml">
                <h3 class="tt-mmg-ml__label"><?php esc_html_e( 'Bullets (one per line)', 'talenttrack' ); ?></h3>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-sp-bullets-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                        <textarea id="tt-sp-bullets-nl" class="tt-input" name="bullets_nl" rows="6"><?php echo esc_textarea( $v['bullets_nl'] ); ?></textarea>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-sp-bullets-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                        <textarea id="tt-sp-bullets-en" class="tt-input" name="bullets_en" rows="6"><?php echo esc_textarea( $v['bullets_en'] ); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-sp-overlay"><?php esc_html_e( 'Diagram overlay (JSON)', 'talenttrack' ); ?></label>
                <textarea id="tt-sp-overlay" class="tt-input tt-mmg-code" name="diagram_overlay_json" rows="5"
                          spellcheck="false" placeholder="{}"><?php echo esc_textarea( $v['diagram_overlay_json'] ); ?></textarea>
                <p class="tt-field-hint"><?php esc_html_e( 'Optional. Raw JSON describing marker positions on the pitch diagram. Leave blank if none.', 'talenttrack' ); ?></p>
            </div>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save set piece', 'talenttrack' ) : __( 'Create set piece', 'talenttrack' ),
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
                    <label class="tt-field-label" for="tt-sp-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-sp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sp-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-sp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     * Bullets render newline-joined per language for the textareas; the
     * diagram overlay is surfaced as pretty-printed JSON for editing.
     *
     * @return array{slug:string,kind_key:string,side:string,title_nl:string,title_en:string,bullets_nl:string,bullets_en:string,diagram_overlay_json:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'slug'                 => (string) ( $row->slug ?? '' ),
            'kind_key'             => (string) ( $row->kind_key ?? '' ),
            'side'                 => (string) ( $row->side ?? '' ),
            'title_nl'             => '',
            'title_en'             => '',
            'bullets_nl'           => '',
            'bullets_en'           => '',
            'diagram_overlay_json' => '',
        ];
        if ( ! $row ) return $v;

        $title = MultilingualField::decode( $row->title_json ?? null ) ?: [];
        $v['title_nl'] = (string) ( $title['nl'] ?? '' );
        $v['title_en'] = (string) ( $title['en'] ?? '' );

        $bullets = MultilingualField::decode( $row->bullets_json ?? null ) ?: [];
        $v['bullets_nl'] = self::joinLines( $bullets['nl'] ?? null );
        $v['bullets_en'] = self::joinLines( $bullets['en'] ?? null );

        $overlay = MultilingualField::decode( $row->diagram_overlay_json ?? null );
        if ( is_array( $overlay ) && ! empty( $overlay ) ) {
            $v['diagram_overlay_json'] = (string) wp_json_encode( $overlay, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        return $v;
    }

    /** @param mixed $list */
    private static function joinLines( $list ): string {
        if ( ! is_array( $list ) ) return '';
        $out = [];
        foreach ( $list as $item ) {
            if ( is_string( $item ) && $item !== '' ) $out[] = $item;
        }
        return implode( "\n", $out );
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (create / edit / delete).
     * Mirrors SetPieceEditPage::handleSave (§4 — same domain layer).
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
        $repo = new SetPiecesRepository();

        if ( $op === 'delete' ) {
            if ( $id <= 0 || ! $repo->delete( $id ) ) {
                return [ 'flash' => __( 'That set piece could not be deleted. Shipped reference set pieces cannot be removed.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Set piece deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $kind = sanitize_key( (string) wp_unslash( $post['kind_key'] ?? '' ) );
        $side = sanitize_key( (string) wp_unslash( $post['side'] ?? '' ) );
        if ( ! MethodologyEnums::isValidKind( $kind ) || ! MethodologyEnums::isValidSide( $side ) ) {
            return [ 'flash' => __( 'Please choose a valid kind and side.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $slug = sanitize_text_field( wp_unslash( (string) ( $post['slug'] ?? '' ) ) );
        if ( $slug === '' ) {
            return [ 'flash' => __( 'A set piece needs a slug.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'slug'                 => $slug,
            'kind_key'             => $kind,
            'side'                 => $side,
            'title_json'           => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['title_en'] ?? '' ) ) ),
            ] ),
            'bullets_json'         => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $post['bullets_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $post['bullets_en'] ?? '' ) ),
            ] ),
            'diagram_overlay_json' => self::normalizeOverlay( (string) wp_unslash( $post['diagram_overlay_json'] ?? '' ) ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That set piece could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Set piece saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['is_shipped'] = 0;
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Set piece created.', 'talenttrack' ) : __( 'Could not create the set piece.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }

    /**
     * Split a newline-separated textarea into a clean list of bullet
     * strings. Mirrors SetPieceEditPage::splitLines.
     *
     * @return string[]
     */
    private static function splitLines( string $raw ): array {
        $parts = preg_split( "/\r?\n/", $raw ) ?: [];
        $out = [];
        foreach ( $parts as $p ) {
            $clean = trim( sanitize_text_field( $p ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }

    /**
     * Validate + re-encode the diagram-overlay JSON. Empty input stores
     * an empty object; malformed JSON is dropped to an empty object so a
     * fat-finger never persists garbage. Valid JSON round-trips through
     * decode/encode to strip whitespace and normalise.
     */
    private static function normalizeOverlay( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) return '{}';
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return '{}';
        return (string) wp_json_encode( $decoded );
    }
}
