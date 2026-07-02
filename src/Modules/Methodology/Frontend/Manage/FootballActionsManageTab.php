<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FootballActionsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FootballActionsManageTab (#2230) — the frontend manage tab for football
 * actions (voetbalhandelingen). Copies the PrinciplesManageTab shape:
 *
 *   1. register() → MethodologyManageRegistry::register([...]).
 *   2. render()   → list ⇄ flat create/edit form.
 *   3. handle()   → sanitize → MultilingualField::encode → repository
 *      create/update/delete (mirrors FootballActionEditPage::handleSave).
 *
 * Fields: a slug, a category (with_ball / without_ball / support), and the
 * NL/EN multilingual `name` + `description`. No business logic lives here
 * beyond composition — persistence + the linked-goal delete guard run
 * through FootballActionsRepository, the same domain layer
 * FootballActionsRestController consumes (§4).
 */
final class FootballActionsManageTab {

    public const MTAB = 'football-actions';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Voetbalhandelingen', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 90,
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
            . esc_html__( '+ New football action', 'talenttrack' ) . '</a>';
        echo '</div>';

        $actions = ( new FootballActionsRepository() )->listAll();
        if ( empty( $actions ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No football actions yet. Use “+ New football action” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        $categories = FootballActionsRepository::categories();

        echo '<ul class="tt-mmg-list">';
        foreach ( $actions as $a ) {
            $shipped  = ! empty( $a->is_shipped );
            $name     = MultilingualField::string( $a->name_json );
            $edit_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $a->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<span class="tt-mmg-row__code"><code>' . esc_html( (string) $a->slug ) . '</code></span>';
            echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                . esc_html( $name !== '' ? $name : __( '(untitled)', 'talenttrack' ) ) . '</a>';
            echo '<span class="tt-mmg-row__meta">'
                . esc_html( $categories[ (string) $a->category_key ] ?? (string) $a->category_key )
                . '</span>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            if ( $shipped ) {
                // Shipped rows are read-only reference content — no frontend
                // edit / delete. Club authoring acts on club-authored rows only.
                echo '<span class="tt-mmg-readonly">' . esc_html__( 'Read-only', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
                echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                    . esc_attr( wp_json_encode( __( 'Delete this football action? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $a->id ) . '" />';
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
        $repo = new FootballActionsRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That football action could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped football actions are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $v          = self::formValues( $row );
        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-fa-slug"><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-fa-slug" class="tt-input" name="slug" maxlength="64" required
                           value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="aannemen" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-fa-category"><?php esc_html_e( 'Category', 'talenttrack' ); ?></label>
                    <select id="tt-fa-category" class="tt-input" name="category_key" required>
                        <?php foreach ( FootballActionsRepository::categories() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['category_key'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php
            self::multilingualText( 'name', __( 'Name', 'talenttrack' ), $v['name_nl'], $v['name_en'] );
            self::multilingualTextarea( 'description', __( 'Description', 'talenttrack' ), $v['description_nl'], $v['description_en'] );
            ?>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save football action', 'talenttrack' ) : __( 'Create football action', 'talenttrack' ),
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
                    <label class="tt-field-label" for="tt-fa-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-fa-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-fa-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-fa-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
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
                    <label class="tt-field-label" for="tt-fa-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <textarea id="tt-fa-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" rows="4"><?php echo esc_textarea( $nl ); ?></textarea>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-fa-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <textarea id="tt-fa-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" rows="4"><?php echo esc_textarea( $en ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     *
     * @return array{slug:string,category_key:string,name_nl:string,name_en:string,description_nl:string,description_en:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'slug'            => (string) ( $row->slug ?? '' ),
            'category_key'    => (string) ( $row->category_key ?? FootballActionsRepository::CAT_WITH_BALL ),
            'name_nl'         => '',
            'name_en'         => '',
            'description_nl'  => '',
            'description_en'  => '',
        ];
        if ( ! $row ) return $v;

        foreach ( [ 'name' => 'name_json', 'description' => 'description_json' ] as $field => $col ) {
            $decoded = MultilingualField::decode( $row->{$col} ?? null ) ?: [];
            $v[ $field . '_nl' ] = (string) ( $decoded['nl'] ?? '' );
            $v[ $field . '_en' ] = (string) ( $decoded['en'] ?? '' );
        }
        return $v;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (create / edit / delete).
     * Mirrors FootballActionEditPage::handleSave (§4 — same domain layer).
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
        $repo = new FootballActionsRepository();

        if ( $op === 'delete' ) {
            if ( $id <= 0 ) {
                return [ 'flash' => __( 'That football action could not be deleted.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $linked = $repo->countLinkedGoals( $id );
            if ( $linked > 0 ) {
                return [
                    'flash'        => sprintf(
                        /* translators: %d: number of goals linked to the action. */
                        _n(
                            'This football action is linked to %d goal and cannot be deleted. Unlink it first.',
                            'This football action is linked to %d goals and cannot be deleted. Unlink them first.',
                            $linked,
                            'talenttrack'
                        ),
                        $linked
                    ),
                    'back_to_list' => true,
                ];
            }
            if ( ! $repo->delete( $id ) ) {
                return [ 'flash' => __( 'That football action could not be deleted.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Football action deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $slug = sanitize_key( (string) wp_unslash( $post['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return [ 'flash' => __( 'A football action needs a slug.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $category = sanitize_key( (string) wp_unslash( $post['category_key'] ?? '' ) );
        if ( ! array_key_exists( $category, FootballActionsRepository::categories() ) ) {
            return [ 'flash' => __( 'Please choose a valid category.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'slug'             => $slug,
            'category_key'     => $category,
            'name_json'        => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['name_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['name_en'] ?? '' ) ) ),
            ] ),
            'description_json' => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_en'] ?? '' ) ) ),
            ] ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That football action could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Football action saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['is_shipped'] = 0;
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Football action created.', 'talenttrack' ) : __( 'Could not create the football action.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }
}
