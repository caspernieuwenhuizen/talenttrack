<?php
namespace TT\Modules\Evaluations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Shared\Admin\BackButton;

/**
 * EvalCategoriesPage — manages the hierarchical evaluation category tree.
 *
 * Sprint 1I (v2.12.0). TalentTrack → Evaluation Categories. Replaces the
 * v2.6+ "Evaluation Categories" tab under Configuration (which used the
 * generic lookup editor and couldn't express parent/child relationships).
 *
 * Routes under admin.php?page=tt-eval-categories:
 *   - default                     → tree list
 *   - crud=new&parent_id=N        → new subcategory under main parent N
 *   - crud=new&parent_id=0        → new main category
 *   - crud=edit&id=N              → edit an existing row
 *
 * Handlers (registered by the Evaluations module):
 *   - tt_save_eval_category       → create / update
 *   - tt_toggle_eval_category     → activate / deactivate
 *
 * Drag-reorder is deferred; `display_order` is an editable numeric input.
 */
class EvalCategoriesPage {

    private const CAP = 'tt_manage_settings';

    /* ═══════════════ Router ═══════════════ */

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $action = isset( $_GET['crud'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['crud'] ) ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( $action === 'new' ) {
            $parent_id = isset( $_GET['parent_id'] ) ? absint( $_GET['parent_id'] ) : 0;
            self::renderForm( 0, $parent_id );
            return;
        }
        if ( $action === 'edit' && $id > 0 ) {
            self::renderForm( $id, 0 );
            return;
        }
        self::renderTree();
    }

    /* ═══════════════ Views ═══════════════ */

    private static function renderTree(): void {
        $repo = new EvalCategoriesRepository();
        $tree = $repo->getTree( false ); // include inactive so admins can reactivate
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Evaluation Categories', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-eval-categories&crud=new&parent_id=0' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Add main category', 'talenttrack' ); ?>
                </a>
            </h1>

            <?php self::renderMessages(); ?>

            <p class="description">
                <?php esc_html_e( 'Main categories group high-level evaluation dimensions. Subcategories let coaches rate specific skills within each dimension. On the evaluation form, a coach can either rate the main category directly OR drill into its subcategories — both options are available per evaluation.', 'talenttrack' ); ?>
            </p>

            <?php if ( empty( $tree ) ) : ?>
                <p><em><?php esc_html_e( 'No evaluation categories configured. This is unusual — the plugin normally seeds four defaults (Technical, Tactical, Physical, Mental) on activation. Add one to get started.', 'talenttrack' ); ?></em></p>
                <?php return; ?>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th style="width:30%;"><?php esc_html_e( 'Label', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Key', 'talenttrack' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Order', 'talenttrack' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'System', 'talenttrack' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th style="width:230px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $tree as $main ) :
                    $main_edit_url   = admin_url( 'admin.php?page=tt-eval-categories&crud=edit&id=' . (int) $main->id );
                    $main_toggle_url = wp_nonce_url(
                        admin_url( 'admin-post.php?action=tt_toggle_eval_category&id=' . (int) $main->id ),
                        'tt_toggle_eval_cat_' . (int) $main->id
                    );
                    $add_sub_url = admin_url( 'admin.php?page=tt-eval-categories&crud=new&parent_id=' . (int) $main->id );
                    ?>
                    <tr style="background:#f6f7f7;">
                        <td>
                            <strong style="font-size:14px;">
                                <a href="<?php echo esc_url( $main_edit_url ); ?>"><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $main->label ) ); ?></a>
                            </strong>
                        </td>
                        <td><code><?php echo esc_html( (string) $main->category_key ); ?></code></td>
                        <td><?php echo (int) $main->display_order; ?></td>
                        <td>
                            <?php if ( (int) $main->is_system === 1 ) : ?>
                                <span style="color:#2271b1;">✓</span>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $main->is_active ) : ?>
                                <span style="color:#00a32a;">●</span> <?php esc_html_e( 'Active', 'talenttrack' ); ?>
                            <?php else : ?>
                                <span style="color:#888;">●</span> <?php esc_html_e( 'Inactive', 'talenttrack' ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $main_edit_url ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                            |
                            <a href="<?php echo esc_url( $add_sub_url ); ?>"><?php esc_html_e( 'Add sub', 'talenttrack' ); ?></a>
                            |
                            <a href="<?php echo esc_url( $main_toggle_url ); ?>">
                                <?php echo $main->is_active
                                    ? esc_html__( 'Deactivate', 'talenttrack' )
                                    : esc_html__( 'Activate', 'talenttrack' ); ?>
                            </a>
                        </td>
                    </tr>
                    <?php foreach ( (array) $main->children as $sub ) :
                        $sub_edit_url   = admin_url( 'admin.php?page=tt-eval-categories&crud=edit&id=' . (int) $sub->id );
                        $sub_toggle_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=tt_toggle_eval_category&id=' . (int) $sub->id ),
                            'tt_toggle_eval_cat_' . (int) $sub->id
                        );
                        ?>
                        <tr>
                            <td style="padding-left:30px;">
                                <span style="color:#999;">↳</span>
                                <a href="<?php echo esc_url( $sub_edit_url ); ?>"><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $sub->label ) ); ?></a>
                            </td>
                            <td><code><?php echo esc_html( (string) $sub->category_key ); ?></code></td>
                            <td><?php echo (int) $sub->display_order; ?></td>
                            <td>
                                <?php if ( (int) $sub->is_system === 1 ) : ?>
                                    <span style="color:#2271b1;">✓</span>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $sub->is_active ) : ?>
                                    <span style="color:#00a32a;">●</span> <?php esc_html_e( 'Active', 'talenttrack' ); ?>
                                <?php else : ?>
                                    <span style="color:#888;">●</span> <?php esc_html_e( 'Inactive', 'talenttrack' ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $sub_edit_url ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                                |
                                <a href="<?php echo esc_url( $sub_toggle_url ); ?>">
                                    <?php echo $sub->is_active
                                        ? esc_html__( 'Deactivate', 'talenttrack' )
                                        : esc_html__( 'Activate', 'talenttrack' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top:16px;">
                <?php esc_html_e( 'System categories (marked ✓) are seeded by the plugin. They can be renamed, reordered, and deactivated but not deleted. Deactivating a category hides it from new evaluations while preserving the ratings already stored against it.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    private static function renderForm( int $id, int $parent_id_on_new ): void {
        $repo = new EvalCategoriesRepository();
        $cat  = $id > 0 ? $repo->get( $id ) : null;

        $is_sub = $cat
            ? ( $cat->parent_id !== null )
            : ( $parent_id_on_new > 0 );

        $parent = null;
        if ( $cat && $cat->parent_id ) {
            $parent = $repo->get( (int) $cat->parent_id );
        } elseif ( $parent_id_on_new > 0 ) {
            $parent = $repo->get( $parent_id_on_new );
            if ( $parent && $parent->parent_id !== null ) {
                // Guard: refuse to create a grand-child of a subcategory.
                wp_die( esc_html__( 'Cannot nest subcategories more than two levels deep.', 'talenttrack' ) );
            }
        }

        $label       = $cat ? (string) $cat->label : '';
        $key         = $cat ? (string) $cat->category_key : '';
        $description = $cat ? (string) ( $cat->description ?? '' ) : '';
        $display_order = $cat ? (int) $cat->display_order : 10;
        $is_active   = $cat ? (bool) $cat->is_active : true;
        $is_system   = $cat ? (int) $cat->is_system : 0;
        ?>
        <div class="wrap">
            
            <?php BackButton::render( admin_url( 'admin.php?page=tt-eval-categories' ) ); ?>
            <h1>
                <?php if ( $cat ) : ?>
                    <?php echo $is_sub
                        ? esc_html__( 'Edit subcategory', 'talenttrack' )
                        : esc_html__( 'Edit main category', 'talenttrack' ); ?>
                <?php else : ?>
                    <?php echo $is_sub
                        ? esc_html__( 'Add subcategory', 'talenttrack' )
                        : esc_html__( 'Add main category', 'talenttrack' ); ?>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-eval-categories' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( '← Back', 'talenttrack' ); ?>
                </a>
            </h1>

            <?php self::renderMessages(); ?>

            <?php if ( $is_sub && $parent ) : ?>
                <p class="description">
                    <?php printf(
                        /* translators: %s is a main category label. */
                        esc_html__( 'This subcategory sits under: %s', 'talenttrack' ),
                        '<strong>' . esc_html( EvalCategoriesRepository::displayLabel( (string) $parent->label ) ) . '</strong>'
                    ); ?>
                </p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
                <?php wp_nonce_field( 'tt_save_eval_category', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_eval_category" />
                <?php if ( $cat ) : ?>
                    <input type="hidden" name="id" value="<?php echo (int) $cat->id; ?>" />
                <?php elseif ( $is_sub && $parent ) : ?>
                    <input type="hidden" name="parent_id" value="<?php echo (int) $parent->id; ?>" />
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="tt_evc_label"><?php esc_html_e( 'Label', 'talenttrack' ); ?> *</label></th>
                        <td>
                            <input type="text" name="label" id="tt_evc_label" class="regular-text"
                                   value="<?php echo esc_attr( $label ); ?>" required />
                            <p class="description">
                                <?php esc_html_e( 'The display name coaches see on the evaluation form.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_evc_key"><?php esc_html_e( 'Key', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="text" name="category_key" id="tt_evc_key" class="regular-text"
                                   value="<?php echo esc_attr( $key ); ?>"
                                   <?php echo $cat ? 'readonly' : ''; ?> />
                            <p class="description">
                                <?php if ( $cat ) : ?>
                                    <?php esc_html_e( 'Stable identifier. Cannot be changed after creation (ratings reference it).', 'talenttrack' ); ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'Auto-generated from the label if blank. Must be globally unique across all categories.', 'talenttrack' ); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_evc_description"><?php esc_html_e( 'Description', 'talenttrack' ); ?></label></th>
                        <td>
                            <textarea name="description" id="tt_evc_description" rows="2" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Optional. Not shown to coaches — for your own reference.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_evc_order"><?php esc_html_e( 'Display order', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="number" name="display_order" id="tt_evc_order" class="small-text"
                                   value="<?php echo (int) $display_order; ?>" min="0" step="1" />
                            <p class="description">
                                <?php esc_html_e( 'Lower numbers render first. Convention: increments of 10 so you have room to insert between items later.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?php checked( $is_active ); ?> />
                                <?php esc_html_e( 'Active (shown on evaluation forms)', 'talenttrack' ); ?>
                            </label>
                            <?php if ( $is_system ) : ?>
                                <p class="description">
                                    <?php esc_html_e( 'This is a system category. It can be renamed and deactivated but not deleted.', 'talenttrack' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <p>
                    <?php submit_button(
                        $cat ? __( 'Update category', 'talenttrack' ) : __( 'Add category', 'talenttrack' ),
                        'primary', 'submit', false
                    ); ?>
                </p>
            </form>
        </div>

        <script>
        // Auto-suggest a key from the label (only when creating).
        (function(){
            var labelInput = document.getElementById('tt_evc_label');
            var keyInput   = document.getElementById('tt_evc_key');
            if (labelInput && keyInput && !keyInput.readOnly) {
                labelInput.addEventListener('input', function(){
                    if (keyInput.dataset.touched) return;
                    keyInput.value = labelInput.value
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '_')
                        .replace(/^_|_$/g, '');
                });
                keyInput.addEventListener('input', function(){ keyInput.dataset.touched = '1'; });
            }
        })();
        </script>
        <?php
    }

    /* ═══════════════ Handlers ═══════════════ */

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_save_eval_category', 'tt_nonce' );

        $id            = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $parent_id     = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
        $label         = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
        $key           = isset( $_POST['category_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['category_key'] ) ) : '';
        $description   = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '';
        $display_order = isset( $_POST['display_order'] ) ? (int) $_POST['display_order'] : 10;
        $is_active     = ! empty( $_POST['is_active'] );

        if ( $label === '' ) {
            self::redirectWithError( 'missing_label', $id, $parent_id );
        }

        $repo = new EvalCategoriesRepository();

        if ( $id > 0 ) {
            // Update — category_key and parent are locked by the repository.
            $ok = $repo->update( $id, [
                'label'         => $label,
                'description'   => $description,
                'display_order' => $display_order,
                'is_active'     => $is_active ? 1 : 0,
            ] );
            if ( ! $ok ) self::redirectWithError( 'save_failed', $id, $parent_id );
        } else {
            // Create. If parent_id=0 → main category (null parent).
            // Generate key if blank; guard against duplicates.
            if ( $key === '' ) {
                $base = sanitize_key( $label );
                if ( $base === '' ) $base = 'cat_' . substr( md5( $label . microtime( true ) ), 0, 8 );
                $key = $base;
                $suffix = 2;
                while ( $repo->getByKey( $key ) !== null ) {
                    $key = $base . '_' . $suffix;
                    $suffix++;
                }
            } elseif ( $repo->getByKey( $key ) !== null ) {
                self::redirectWithError( 'duplicate_key', 0, $parent_id );
            }

            $new_id = $repo->create( [
                'parent_id'     => $parent_id > 0 ? $parent_id : null,
                'category_key'  => $key,
                'label'         => $label,
                'description'   => $description,
                'display_order' => $display_order,
                'is_active'     => $is_active ? 1 : 0,
                'is_system'     => 0,
            ] );
            if ( $new_id <= 0 ) self::redirectWithError( 'save_failed', 0, $parent_id );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-eval-categories&tt_msg=saved' ) );
        exit;
    }

    public static function handleToggle(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_toggle_eval_cat_' . $id );

        $repo = new EvalCategoriesRepository();
        $cat  = $repo->get( $id );
        if ( $cat ) {
            $repo->setActive( $id, empty( $cat->is_active ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-eval-categories&tt_msg=saved' ) );
        exit;
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function redirectWithError( string $code, int $id, int $parent_id ): void {
        $args = [
            'page'     => 'tt-eval-categories',
            'tt_error' => $code,
        ];
        if ( $id > 0 ) {
            $args['crud'] = 'edit';
            $args['id']   = $id;
        } elseif ( $parent_id > 0 ) {
            $args['crud']      = 'new';
            $args['parent_id'] = $parent_id;
        } else {
            $args['crud']      = 'new';
            $args['parent_id'] = 0;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function renderMessages(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        if ( $msg === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'talenttrack' ) . '</p></div>';
        }
        $err = isset( $_GET['tt_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_error'] ) ) : '';
        if ( $err !== '' ) {
            $map = [
                'missing_label' => __( 'Label is required.', 'talenttrack' ),
                'duplicate_key' => __( 'That key is already used by another category. Pick a different one.', 'talenttrack' ),
                'save_failed'   => __( 'The database rejected the save. Try again.', 'talenttrack' ),
            ];
            $text = $map[ $err ] ?? __( 'Something went wrong.', 'talenttrack' );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
        }
    }
}
