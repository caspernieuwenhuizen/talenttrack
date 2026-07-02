<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FormationsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FormationsManageTab (#2227) — the frontend authoring tab for
 * formations and their per-jersey position cards. Registered into the
 * shared MethodologyManageRegistry from MethodologyModule::boot(), the
 * same way PrinciplesManageTab (#2225) is — no shared switch to edit.
 *
 * Two nesting levels dispatched on the `action` context:
 *
 *   1. list ⇄ new / edit         → formations (name / description /
 *      diagram-data), mirroring the wp-admin formation editing.
 *   2. positions                 → the position cards inside one
 *      formation (list + add), plus position_new / position_edit
 *      forms, mirroring PositionEditPage.
 *
 * All persistence runs through FormationsRepository + MultilingualField
 * — the same domain layer FormationsRestController consumes (§4), so a
 * future SaaS front end gets identical answers. Shipped rows are
 * read-only reference content; club authoring acts on club rows only.
 */
final class FormationsManageTab {

    public const MTAB = 'formations';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Formaties', 'talenttrack' ),
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

        switch ( $action ) {
            case 'new':
                self::renderFormationForm( 0 );
                return;
            case 'edit':
                if ( $id > 0 ) { self::renderFormationForm( $id ); return; }
                break;
            case 'positions':
                if ( $id > 0 ) { self::renderPositions( $id ); return; }
                break;
            case 'position_new':
                $fid = isset( $_GET['formation_id'] ) ? absint( $_GET['formation_id'] ) : 0;
                if ( $fid > 0 ) { self::renderPositionForm( $fid, 0 ); return; }
                break;
            case 'position_edit':
                if ( $id > 0 ) { self::renderPositionForm( 0, $id ); return; }
                break;
        }
        self::renderFormationList();
    }

    // ── formation list ──────────────────────────────────────────────

    private static function renderFormationList(): void {
        echo '<div class="tt-mmg-toolbar">';
        echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="'
            . esc_url( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'new' ] ) ) . '">'
            . esc_html__( '+ New formation', 'talenttrack' ) . '</a>';
        echo '</div>';

        $formations = ( new FormationsRepository() )->listAll();
        if ( empty( $formations ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No formations yet. Use “+ New formation” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-mmg-list">';
        foreach ( $formations as $f ) {
            $shipped   = ! empty( $f->is_shipped );
            $name      = MultilingualField::string( $f->name_json );
            $edit_url  = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $f->id ] ) );
            $pos_url   = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'positions', 'id' => (int) $f->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<span class="tt-mmg-row__code"><code>' . esc_html( (string) $f->slug ) . '</code></span>';
            echo '<a class="tt-mmg-row__name" href="' . esc_url( $shipped ? $pos_url : $edit_url ) . '">'
                . esc_html( $name !== '' ? $name : (string) $f->slug ) . '</a>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $pos_url ) . '">'
                . esc_html__( 'Positions', 'talenttrack' ) . '</a>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-readonly">' . esc_html__( 'Read-only', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
                echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                    . esc_attr( wp_json_encode( __( 'Delete this formation and its position cards? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete_formation" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $f->id ) . '" />';
                echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                    . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    // ── formation form ──────────────────────────────────────────────

    private static function renderFormationForm( int $id ): void {
        $repo = new FormationsRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That formation could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped formations are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $slug = (string) ( $row->slug ?? '' );
        $name_nl = $name_en = $desc_nl = $desc_en = '';
        $diagram = '';
        if ( $row ) {
            $name = MultilingualField::decode( $row->name_json ) ?: [];
            $desc = MultilingualField::decode( $row->description_json ) ?: [];
            $name_nl = (string) ( $name['nl'] ?? '' );
            $name_en = (string) ( $name['en'] ?? '' );
            $desc_nl = (string) ( $desc['nl'] ?? '' );
            $desc_en = (string) ( $desc['en'] ?? '' );
            $diagram = is_string( $row->diagram_data_json ?? null ) ? (string) $row->diagram_data_json : '';
        }

        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save_formation" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mf-slug"><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label>
                <input type="text" id="tt-mf-slug" class="tt-input" name="slug" maxlength="100" required
                       value="<?php echo esc_attr( $slug ); ?>" placeholder="1-4-3-3" />
            </div>

            <?php
            self::multilingualText( 'name', __( 'Name', 'talenttrack' ), $name_nl, $name_en );
            self::multilingualTextarea( 'description', __( 'Description', 'talenttrack' ), $desc_nl, $desc_en );
            ?>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mf-diagram"><?php esc_html_e( 'Diagram data (JSON)', 'talenttrack' ); ?></label>
                <textarea id="tt-mf-diagram" class="tt-input tt-mono" name="diagram_data_json" rows="6"
                          spellcheck="false" placeholder='{"positions":{"1":{"x":50,"y":92,"label":"K"}}}'><?php echo esc_textarea( $diagram ); ?></textarea>
                <p class="tt-field-hint"><?php esc_html_e( 'Optional. Normalized 0–100 position coordinates for the pitch diagram. Leave blank to use the default layout.', 'talenttrack' ); ?></p>
            </div>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save formation', 'talenttrack' ) : __( 'Create formation', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    // ── positions (nested list within a formation) ──────────────────

    private static function renderPositions( int $formation_id ): void {
        $repo      = new FormationsRepository();
        $formation = $repo->find( $formation_id );
        if ( ! $formation ) {
            echo '<p class="tt-notice">' . esc_html__( 'That formation could not be found.', 'talenttrack' ) . '</p>';
            return;
        }

        $name         = MultilingualField::string( $formation->name_json ) ?: (string) $formation->slug;
        $ship_ro      = ! empty( $formation->is_shipped );
        $back_to_list = MethodologyManageView::tabUrl( self::MTAB );

        echo '<div class="tt-mmg-subhead">';
        echo '<h2 class="tt-mmg-subhead__title">' . esc_html( sprintf(
            /* translators: %s is the formation name */
            __( 'Positions — %s', 'talenttrack' ),
            $name
        ) ) . '</h2>';
        echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $back_to_list ) . '">'
            . esc_html__( 'Back to formations', 'talenttrack' ) . '</a>';
        echo '</div>';

        if ( ! $ship_ro ) {
            $new_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'position_new', 'formation_id' => $formation_id ] ) );
            echo '<div class="tt-mmg-toolbar">';
            echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="' . esc_url( $new_url ) . '">'
                . esc_html__( '+ New position', 'talenttrack' ) . '</a>';
            echo '</div>';
        } else {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped formations are read-only reference content; their positions cannot be edited.', 'talenttrack' ) . '</p>';
        }

        $positions = $repo->positionsFor( $formation_id );
        if ( empty( $positions ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No positions on this formation yet.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-mmg-list">';
        foreach ( $positions as $pos ) {
            $shipped = ! empty( $pos->is_shipped );
            $label   = MultilingualField::string( $pos->long_name_json )
                ?: MultilingualField::string( $pos->short_name_json )
                ?: __( '(unnamed position)', 'talenttrack' );
            $edit_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'position_edit', 'id' => (int) $pos->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<span class="tt-mmg-row__code"><code>#' . esc_html( (string) (int) $pos->jersey_number ) . '</code></span>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-row__name">' . esc_html( $label ) . '</span>';
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            if ( $shipped ) {
                echo '<span class="tt-mmg-readonly">' . esc_html__( 'Read-only', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
                echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                    . esc_attr( wp_json_encode( __( 'Delete this position? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete_position" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $pos->id ) . '" />';
                echo '<input type="hidden" name="formation_id" value="' . esc_attr( (string) $formation_id ) . '" />';
                echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                    . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    // ── position form ───────────────────────────────────────────────

    private static function renderPositionForm( int $formation_id, int $id ): void {
        $repo = new FormationsRepository();
        $row  = $id > 0 ? $repo->findPosition( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That position could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped positions are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $formation_id = $row ? (int) $row->formation_id : $formation_id;
        $formation    = $repo->find( $formation_id );
        if ( ! $formation ) {
            echo '<p class="tt-notice">' . esc_html__( 'That formation could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! $row && ! empty( $formation->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped formations are read-only reference content; positions cannot be added.', 'talenttrack' ) . '</p>';
            return;
        }

        $jersey  = (int) ( $row->jersey_number ?? 1 );
        $short_nl = $short_en = $long_nl = $long_en = '';
        $att_nl = $att_en = $def_nl = $def_en = '';
        if ( $row ) {
            $short = MultilingualField::decode( $row->short_name_json ) ?: [];
            $long  = MultilingualField::decode( $row->long_name_json )  ?: [];
            $att   = MultilingualField::decode( $row->attacking_tasks_json ) ?: [];
            $def   = MultilingualField::decode( $row->defending_tasks_json ) ?: [];
            $short_nl = (string) ( $short['nl'] ?? '' );
            $short_en = (string) ( $short['en'] ?? '' );
            $long_nl  = (string) ( $long['nl']  ?? '' );
            $long_en  = (string) ( $long['en']  ?? '' );
            $att_nl   = is_array( $att['nl'] ?? null ) ? implode( "\n", $att['nl'] ) : '';
            $att_en   = is_array( $att['en'] ?? null ) ? implode( "\n", $att['en'] ) : '';
            $def_nl   = is_array( $def['nl'] ?? null ) ? implode( "\n", $def['nl'] ) : '';
            $def_en   = is_array( $def['en'] ?? null ) ? implode( "\n", $def['en'] ) : '';
        }

        // Cancel returns to the parent formation's positions list (tt_back
        // overrides when present).
        $back      = BackLink::resolve();
        $cancel_url = $back !== null
            ? $back['url']
            : MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'positions', 'id' => $formation_id ] );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save_position" />
            <input type="hidden" name="formation_id" value="<?php echo esc_attr( (string) $formation_id ); ?>" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mfp-jersey"><?php esc_html_e( 'Jersey number', 'talenttrack' ); ?></label>
                <input type="number" inputmode="numeric" id="tt-mfp-jersey" class="tt-input" name="jersey_number"
                       min="1" max="11" required value="<?php echo esc_attr( (string) $jersey ); ?>" />
            </div>

            <?php
            self::multilingualText( 'short', __( 'Short name', 'talenttrack' ), $short_nl, $short_en );
            self::multilingualText( 'long', __( 'Long name', 'talenttrack' ), $long_nl, $long_en );
            self::multilingualTaskList( 'att', __( 'Attacking tasks (one per line)', 'talenttrack' ), $att_nl, $att_en );
            self::multilingualTaskList( 'def', __( 'Defending tasks (one per line)', 'talenttrack' ), $def_nl, $def_en );

            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save position', 'talenttrack' ) : __( 'Create position', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    // ── shared field partials ───────────────────────────────────────

    /** Two side-by-side NL/EN text inputs for a multilingual string field. */
    private static function multilingualText( string $name, string $label, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mf-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-mf-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mf-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-mf-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    /** Two side-by-side NL/EN textareas for a multilingual long-text field. */
    private static function multilingualTextarea( string $name, string $label, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mf-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mf-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" rows="4"><?php echo esc_textarea( $nl ); ?></textarea>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mf-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mf-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" rows="4"><?php echo esc_textarea( $en ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Two side-by-side NL/EN textareas for a newline-separated task list.
     * Stored as a `{nl:[...], en:[...]}` array-of-strings JSON.
     */
    private static function multilingualTaskList( string $name, string $label, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mfp-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mfp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" rows="5"><?php echo esc_textarea( $nl ); ?></textarea>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mfp-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mfp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" rows="5"><?php echo esc_textarea( $en ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms. Dispatches on `op`:
     * save_formation / delete_formation / save_position / delete_position.
     * Mirrors the wp-admin save flow (§4 — same domain layer).
     *
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    public static function handle( array $post ): array {
        if ( ! current_user_can( MethodologyManageView::CAP ) ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }
        $op   = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
        $repo = new FormationsRepository();

        switch ( $op ) {
            case 'delete_formation':
                return self::handleDeleteFormation( $repo, $post );
            case 'save_formation':
                return self::handleSaveFormation( $repo, $post );
            case 'delete_position':
                return self::handleDeletePosition( $repo, $post );
            case 'save_position':
                return self::handleSavePosition( $repo, $post );
        }
        return [ 'flash' => '', 'back_to_list' => false ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    private static function handleDeleteFormation( FormationsRepository $repo, array $post ): array {
        $id = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        if ( $id <= 0 || ! $repo->deleteFormation( $id ) ) {
            return [ 'flash' => __( 'That formation could not be deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }
        return [ 'flash' => __( 'Formation deleted.', 'talenttrack' ), 'back_to_list' => true ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    private static function handleSaveFormation( FormationsRepository $repo, array $post ): array {
        $id   = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        $slug = sanitize_text_field( wp_unslash( (string) ( $post['slug'] ?? '' ) ) );
        if ( $slug === '' ) {
            return [ 'flash' => __( 'A formation needs a slug.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'slug'              => $slug,
            'name_json'         => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['name_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['name_en'] ?? '' ) ) ),
            ] ),
            'description_json'  => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_en'] ?? '' ) ) ),
            ] ),
            'diagram_data_json' => self::sanitizeDiagram( (string) wp_unslash( $post['diagram_data_json'] ?? '' ) ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That formation could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->updateFormation( $id, $payload );
            return [ 'flash' => __( 'Formation saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['is_shipped'] = 0;
        $new_id = $repo->createFormation( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Formation created.', 'talenttrack' ) : __( 'Could not create the formation.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    private static function handleDeletePosition( FormationsRepository $repo, array $post ): array {
        $id = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        if ( $id <= 0 || ! $repo->deletePosition( $id ) ) {
            return [ 'flash' => __( 'That position could not be deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }
        return [ 'flash' => __( 'Position deleted.', 'talenttrack' ), 'back_to_list' => true ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    private static function handleSavePosition( FormationsRepository $repo, array $post ): array {
        $id           = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        $formation_id = isset( $post['formation_id'] ) ? absint( $post['formation_id'] ) : 0;

        $payload = [
            'jersey_number'        => max( 1, min( 11, (int) ( $post['jersey_number'] ?? 1 ) ) ),
            'short_name_json'      => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['short_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['short_en'] ?? '' ) ) ),
            ] ),
            'long_name_json'       => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['long_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['long_en'] ?? '' ) ) ),
            ] ),
            'attacking_tasks_json' => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $post['att_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $post['att_en'] ?? '' ) ),
            ] ),
            'defending_tasks_json' => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $post['def_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $post['def_en'] ?? '' ) ),
            ] ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->findPosition( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That position could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->updatePosition( $id, $payload );
            return [ 'flash' => __( 'Position saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $parent = $repo->find( $formation_id );
        if ( ! $parent || ! empty( $parent->is_shipped ) ) {
            return [ 'flash' => __( 'Could not create the position.', 'talenttrack' ), 'back_to_list' => true ];
        }
        $payload['formation_id'] = $formation_id;
        $payload['is_shipped']   = 0;
        $new_id = $repo->createPosition( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Position created.', 'talenttrack' ) : __( 'Could not create the position.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }

    /**
     * Validate the optional diagram-data JSON. Returns the compacted JSON
     * on success, an empty JSON object when blank, and — to avoid silently
     * discarding a typo — the raw input unchanged when it doesn't parse
     * (the diagram component falls back to a default layout for bad data).
     */
    private static function sanitizeDiagram( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) return '';
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            return (string) wp_json_encode( $decoded );
        }
        return sanitize_textarea_field( $raw );
    }

    /**
     * Split a newline-separated textarea into a clean list of strings.
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
}
