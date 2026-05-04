<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendEvalCategoriesView — frontend admin-tier surface for the
 * hierarchical evaluation-category editor.
 *
 * #0019 Sprint 5. Two-level tree (main + sub) — no deeper nesting
 * is currently supported by the schema. Add/edit/delete + up/down
 * arrow reorder per the Sprint 4 pattern.
 *
 * Per-age-group weight editing (`CategoryWeightsPage`) stays in
 * wp-admin for Sprint 5; deep-link to it is surfaced at the top of
 * this view.
 */
class FrontendEvalCategoriesView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $eval_cat_label = __( 'Evaluation categories', 'talenttrack' );
        if ( $action === 'new' || $id > 0 ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                $action === 'new' ? __( 'New evaluation category', 'talenttrack' ) : __( 'Edit evaluation category', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'eval-categories', $eval_cat_label ) ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $eval_cat_label );
        }

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New evaluation category', 'talenttrack' ) );
            self::renderForm( null );
            return;
        }

        if ( $id > 0 ) {
            $cat = ( new EvalCategoriesRepository() )->get( $id );
            self::renderHeader( $cat ? sprintf( __( 'Edit category — %s', 'talenttrack' ), EvalCategoriesRepository::displayLabel( (string) $cat->label ) ) : __( 'Category not found', 'talenttrack' ) );
            if ( ! $cat ) {
                echo '<p class="tt-notice">' . esc_html__( 'That category no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $cat );
            return;
        }

        self::renderHeader( __( 'Evaluation categories', 'talenttrack' ) );
        self::renderTree();
    }

    private static function renderTree(): void {
        $base = remove_query_arg( [ 'action', 'id' ] );
        $new_url     = add_query_arg( [ 'tt_view' => 'eval-categories', 'action' => 'new' ], $base );
        $weights_url = admin_url( 'admin.php?page=tt-category-weights' );

        echo '<p style="margin:0 0 var(--tt-sp-3); display:flex; gap:8px; flex-wrap:wrap;">';
        echo '<a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">' . esc_html__( 'New category', 'talenttrack' ) . '</a>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $weights_url ) . '">' . esc_html__( 'Edit per-age-group weights (wp-admin)', 'talenttrack' ) . '</a>';
        echo '</p>';

        $repo  = new EvalCategoriesRepository();
        $tree  = $repo->getTree( false );

        if ( ! $tree ) {
            echo '<p><em>' . esc_html__( 'No evaluation categories defined yet.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<div class="tt-eval-categories-tree" data-tt-eval-categories="1">';

        $main_count = count( $tree );
        $main_idx = 0;
        foreach ( $tree as $main ) {
            $children = is_array( $main->children ?? null ) ? $main->children : [];
            $is_first = $main_idx === 0;
            $is_last  = $main_idx === $main_count - 1;
            self::renderNode( (object) (array) $main, $base, true, $is_first, $is_last );
            if ( $children ) {
                echo '<ul style="list-style:none; padding-left:24px; margin:0 0 var(--tt-sp-3);">';
                $sub_count = count( $children );
                foreach ( $children as $sub_idx => $child ) {
                    $sf = $sub_idx === 0;
                    $sl = $sub_idx === $sub_count - 1;
                    echo '<li>';
                    self::renderNode( (object) (array) $child, $base, false, $sf, $sl );
                    echo '</li>';
                }
                echo '</ul>';
            }
            $main_idx++;
        }
        echo '<p class="tt-form-msg" data-tt-eval-cat-msg="1" style="margin-top:8px;"></p>';
        echo '</div>';
    }

    private static function renderNode( object $cat, string $base, bool $is_main, bool $is_first, bool $is_last ): void {
        $edit_url = add_query_arg( [ 'tt_view' => 'eval-categories', 'id' => (int) $cat->id ], $base );
        $css = $is_main ? 'font-weight:600;' : 'color:var(--tt-muted);';
        $inactive = empty( $cat->is_active );
        ?>
        <div class="tt-eval-cat-row" data-cat-id="<?php echo (int) $cat->id; ?>" style="display:flex; gap:10px; align-items:center; padding:6px 0; border-bottom:1px dashed var(--tt-line);">
            <span style="display:inline-flex; gap:4px;">
                <button type="button" class="tt-list-table-action" data-tt-eval-cat-move="up"   <?php disabled( $is_first ); ?>>↑</button>
                <button type="button" class="tt-list-table-action" data-tt-eval-cat-move="down" <?php disabled( $is_last  ); ?>>↓</button>
            </span>
            <span style="<?php echo esc_attr( $css ); ?>">
                <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $cat->label ) ); ?></a>
                <code style="margin-left:6px; font-size:var(--tt-fs-xs); color:var(--tt-muted);"><?php echo esc_html( (string) $cat->category_key ); ?></code>
                <?php if ( ! empty( $cat->is_system ) ) : ?>
                    <span class="tt-badge" style="margin-left:6px; padding:1px 6px; background:var(--tt-bg-soft); border:1px solid var(--tt-line); border-radius:999px; font-size:var(--tt-fs-xs); color:var(--tt-muted);"><?php esc_html_e( 'system', 'talenttrack' ); ?></span>
                <?php endif; ?>
                <?php if ( $inactive ) : ?>
                    <span class="tt-badge" style="margin-left:6px; padding:1px 6px; background:var(--tt-warning-soft); border:1px solid var(--tt-warning); border-radius:999px; font-size:var(--tt-fs-xs); color:#7c5a00;"><?php esc_html_e( 'inactive', 'talenttrack' ); ?></span>
                <?php endif; ?>
            </span>
            <span style="margin-left:auto; display:inline-flex; gap:4px;">
                <a class="tt-list-table-action" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                <?php if ( empty( $cat->is_system ) ) : ?>
                    <button type="button" class="tt-list-table-action tt-list-table-action-danger" data-tt-eval-cat-delete="<?php echo (int) $cat->id; ?>"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></button>
                <?php endif; ?>
            </span>
        </div>
        <?php
    }

    private static function renderForm( ?object $cat ): void {
        $is_edit   = $cat !== null;
        $rest_path = $is_edit ? 'eval-categories/' . (int) $cat->id : 'eval-categories';
        $rest_meth = $is_edit ? 'PUT' : 'POST';

        $repo  = new EvalCategoriesRepository();
        $mains = $repo->getMainCategories( false );

        ?>
        <form id="tt-eval-cat-form" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>">
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-cat-label"><?php esc_html_e( 'Label', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-cat-label" class="tt-input" name="label" required value="<?php echo esc_attr( (string) ( $cat->label ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-cat-key"><?php esc_html_e( 'Key', 'talenttrack' ); ?></label>
                    <?php if ( $is_edit ) : ?>
                        <p><code><?php echo esc_html( (string) $cat->category_key ); ?></code></p>
                        <span class="tt-field-hint"><?php esc_html_e( 'Cannot be changed after creation.', 'talenttrack' ); ?></span>
                    <?php else : ?>
                        <input type="text" id="tt-cat-key" class="tt-input" name="category_key" pattern="[a-z0-9_]*" />
                        <span class="tt-field-hint"><?php esc_html_e( 'Optional. Auto-generated from the label if empty. Cannot be changed later.', 'talenttrack' ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-cat-parent"><?php esc_html_e( 'Parent category', 'talenttrack' ); ?></label>
                    <select id="tt-cat-parent" class="tt-input" name="parent_id">
                        <option value="0"><?php esc_html_e( '— None (top-level) —', 'talenttrack' ); ?></option>
                        <?php foreach ( $mains as $m ) :
                            if ( $is_edit && (int) $m->id === (int) $cat->id ) continue;
                            ?>
                            <option value="<?php echo (int) $m->id; ?>" <?php selected( (int) ( $cat->parent_id ?? 0 ), (int) $m->id ); ?>>
                                <?php echo esc_html( (string) $m->label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?php checked( $is_edit ? ! empty( $cat->is_active ) : true ); ?> />
                        <?php esc_html_e( 'Active', 'talenttrack' ); ?>
                    </label>
                </div>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-cat-desc"><?php esc_html_e( 'Description', 'talenttrack' ); ?></label>
                <textarea id="tt-cat-desc" class="tt-input" name="description" rows="3"><?php echo esc_textarea( (string) ( $cat->description ?? '' ) ); ?></textarea>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update category', 'talenttrack' ) : __( 'Save category', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }
}
