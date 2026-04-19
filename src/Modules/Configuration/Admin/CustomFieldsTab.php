<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Shared\Admin\OptionSetEditor;

/**
 * CustomFieldsTab — Configuration → Player Custom Fields.
 *
 * Extracted from ConfigurationPage to keep that class manageable.
 * ConfigurationPage::tab_custom_fields() simply calls CustomFieldsTab::render().
 *
 * Handlers:
 *   - tt_save_custom_field      → create / update a field
 *   - tt_toggle_custom_field    → activate / deactivate
 *   - tt_reorder_custom_fields  → persist drag-reorder via form POST
 */
class CustomFieldsTab {

    public static function registerHandlers(): void {
        add_action( 'admin_post_tt_save_custom_field',     [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_toggle_custom_field',   [ __CLASS__, 'handle_toggle' ] );
        add_action( 'admin_post_tt_reorder_custom_fields', [ __CLASS__, 'handle_reorder' ] );
    }

    public static function render(): void {
        $action = isset( $_GET['crud'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['crud'] ) ) : 'list';
        $id     = isset( $_GET['field_id'] ) ? absint( $_GET['field_id'] ) : 0;

        if ( $action === 'new' || $action === 'edit' ) {
            self::render_form( $action === 'edit' ? $id : 0 );
            return;
        }
        self::render_list();
    }

    private static function render_list(): void {
        $repo   = new CustomFieldsRepository();
        $fields = $repo->getAll( CustomFieldsRepository::ENTITY_PLAYER );
        $tab    = 'custom_fields';
        ?>
        <h2>
            <?php esc_html_e( 'Player Custom Fields', 'talenttrack' ); ?>
            <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=new" ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'talenttrack' ); ?>
            </a>
        </h2>
        <p class="description">
            <?php esc_html_e( 'Define additional attributes you want to capture for each player. Fields you add here appear on the Add/Edit Player form.', 'talenttrack' ); ?>
        </p>

        <?php if ( empty( $fields ) ) : ?>
            <p><em><?php esc_html_e( 'No custom fields defined yet.', 'talenttrack' ); ?></em></p>
            <?php return; ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_reorder_custom_fields', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_reorder_custom_fields" />
            <input type="hidden" name="entity_type" value="<?php echo esc_attr( CustomFieldsRepository::ENTITY_PLAYER ); ?>" />

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th><?php esc_html_e( 'Label', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Key', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Required', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th style="width:200px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody data-sortable="1" class="tt-field-rows">
                    <?php foreach ( $fields as $f ) :
                        $edit_url   = admin_url( "admin.php?page=tt-config&tab=custom_fields&crud=edit&field_id={$f->id}" );
                        $toggle_url = wp_nonce_url(
                            admin_url( "admin-post.php?action=tt_toggle_custom_field&id={$f->id}" ),
                            'tt_toggle_field_' . $f->id
                        );
                    ?>
                        <tr>
                            <td style="cursor:move;color:#888;text-align:center;">☰</td>
                            <td><strong><?php echo esc_html( (string) $f->label ); ?></strong></td>
                            <td><code><?php echo esc_html( (string) $f->field_key ); ?></code></td>
                            <td><?php echo esc_html( self::typeLabel( (string) $f->field_type ) ); ?></td>
                            <td><?php echo $f->is_required ? '<span class="dashicons dashicons-yes" style="color:#2271b1;"></span>' : '—'; ?></td>
                            <td>
                                <?php if ( $f->is_active ) : ?>
                                    <span style="color:#00a32a;">●</span> <?php esc_html_e( 'Active', 'talenttrack' ); ?>
                                <?php else : ?>
                                    <span style="color:#888;">●</span> <?php esc_html_e( 'Inactive', 'talenttrack' ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                                |
                                <a href="<?php echo esc_url( $toggle_url ); ?>">
                                    <?php echo $f->is_active
                                        ? esc_html__( 'Deactivate', 'talenttrack' )
                                        : esc_html__( 'Activate', 'talenttrack' ); ?>
                                </a>
                            </td>
                            <input type="hidden" name="order[]" value="<?php echo (int) $f->id; ?>" />
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:10px;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Order', 'talenttrack' ); ?></button>
                <span class="description" style="margin-left:10px;"><?php esc_html_e( 'Drag rows to reorder, then click Save Order.', 'talenttrack' ); ?></span>
            </p>
        </form>

        <script>
        // Re-collect the hidden order[] inputs after each drag so the submitted
        // order matches the visible DOM order.
        (function(){
            var tbody = document.querySelector('.tt-field-rows');
            if (!tbody) return;
            tbody.addEventListener('tt:sortable:end', function(){
                var inputs = tbody.querySelectorAll('input[name="order[]"]');
                inputs.forEach(function(inp){ inp.parentNode.removeChild(inp); });
                tbody.querySelectorAll('tr').forEach(function(tr){
                    var id = tr.querySelector('code');
                    // Grab id from the Edit link — safer than scraping text.
                    var editLink = tr.querySelector('a[href*="field_id="]');
                    if (!editLink) return;
                    var m = editLink.href.match(/field_id=(\d+)/);
                    if (!m) return;
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'order[]';
                    hidden.value = m[1];
                    tr.appendChild(hidden);
                });
            });
        })();
        </script>
        <?php
    }

    private static function render_form( int $id ): void {
        $repo  = new CustomFieldsRepository();
        $field = $id ? $repo->get( $id ) : null;
        $tab   = 'custom_fields';

        $options_initial = $field ? CustomFieldsRepository::decodeOptions( $field->options ) : [];

        ?>
        <h2>
            <?php echo $field ? esc_html__( 'Edit Custom Field', 'talenttrack' ) : esc_html__( 'New Custom Field', 'talenttrack' ); ?>
            <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab" ) ); ?>" class="page-title-action">
                <?php esc_html_e( '← Back', 'talenttrack' ); ?>
            </a>
        </h2>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
            <?php wp_nonce_field( 'tt_save_custom_field', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_custom_field" />
            <input type="hidden" name="entity_type" value="<?php echo esc_attr( CustomFieldsRepository::ENTITY_PLAYER ); ?>" />
            <?php if ( $field ) : ?>
                <input type="hidden" name="id" value="<?php echo (int) $field->id; ?>" />
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th><label for="tt_cf_label"><?php esc_html_e( 'Label', 'talenttrack' ); ?> *</label></th>
                    <td>
                        <input id="tt_cf_label" type="text" name="label" value="<?php echo esc_attr( $field->label ?? '' ); ?>" class="regular-text" required />
                        <p class="description"><?php esc_html_e( 'The label shown next to the input on forms.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="tt_cf_key"><?php esc_html_e( 'Key', 'talenttrack' ); ?></label></th>
                    <td>
                        <input id="tt_cf_key" type="text" name="field_key"
                               value="<?php echo esc_attr( $field->field_key ?? '' ); ?>"
                               class="regular-text"
                               <?php echo $field ? 'readonly' : ''; ?> />
                        <p class="description">
                            <?php echo $field
                                ? esc_html__( 'Keys are locked once saved (changing a key would orphan existing values).', 'talenttrack' )
                                : esc_html__( 'Auto-generated from Label when blank. Lower-case, snake_case. Locked after saving.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="tt_cf_type"><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</label></th>
                    <td>
                        <select id="tt_cf_type" name="field_type" required <?php echo $field ? 'disabled' : ''; ?>>
                            <?php foreach ( CustomFieldsRepository::allowedTypes() as $t ) : ?>
                                <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $field->field_type ?? '', $t ); ?>>
                                    <?php echo esc_html( self::typeLabel( $t ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ( $field ) : ?>
                            <input type="hidden" name="field_type" value="<?php echo esc_attr( (string) $field->field_type ); ?>" />
                            <p class="description"><?php esc_html_e( 'Field type is locked once saved.', 'talenttrack' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Required', 'talenttrack' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_required" value="1" <?php checked( ! empty( $field->is_required ) ); ?> />
                            <?php esc_html_e( 'Players cannot be saved without a value for this field.', 'talenttrack' ); ?>
                        </label>
                    </td>
                </tr>
                <tr id="tt_cf_options_row" style="<?php echo ( ( $field->field_type ?? '' ) === CustomFieldsRepository::TYPE_SELECT || ! $field ) ? '' : 'display:none;'; ?>">
                    <th><?php esc_html_e( 'Options', 'talenttrack' ); ?></th>
                    <td>
                        <?php OptionSetEditor::render( 'options_json', $options_initial, 'tt-cf-opts' ); ?>
                        <p class="description"><?php esc_html_e( 'Only used for select-type fields.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( $field ? __( 'Update Field', 'talenttrack' ) : __( 'Add Field', 'talenttrack' ) ); ?>
        </form>

        <script>
        (function(){
            var typeSel = document.getElementById('tt_cf_type');
            var optsRow = document.getElementById('tt_cf_options_row');
            if (!typeSel || !optsRow) return;
            function sync(){
                optsRow.style.display = (typeSel.value === 'select') ? '' : 'none';
            }
            typeSel.addEventListener('change', sync);
            sync();

            // Auto-populate key from label when creating and key is empty.
            var labelInput = document.getElementById('tt_cf_label');
            var keyInput   = document.getElementById('tt_cf_key');
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

    /* ═══ Handlers ═══ */

    public static function handle_save(): void {
        if ( ! current_user_can( 'tt_manage_players' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_save_custom_field', 'tt_nonce' );

        $id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $entity_type = isset( $_POST['entity_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['entity_type'] ) ) : CustomFieldsRepository::ENTITY_PLAYER;
        $label       = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
        $field_key   = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['field_key'] ) ) : '';
        $field_type  = isset( $_POST['field_type'] ) ? (string) wp_unslash( $_POST['field_type'] ) : '';
        $is_required = ! empty( $_POST['is_required'] );
        $options_raw = isset( $_POST['options_json'] ) ? (string) wp_unslash( $_POST['options_json'] ) : '';

        if ( $label === '' ) {
            self::redirectWithError( 'missing_label', $id );
        }
        if ( ! in_array( $field_type, CustomFieldsRepository::allowedTypes(), true ) ) {
            self::redirectWithError( 'invalid_type', $id );
        }

        $repo = new CustomFieldsRepository();

        // Decode options when relevant; store JSON.
        $options = null;
        if ( $field_type === CustomFieldsRepository::TYPE_SELECT ) {
            $decoded = json_decode( $options_raw, true );
            if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                $clean = [];
                foreach ( $decoded as $item ) {
                    if ( ! is_array( $item ) ) continue;
                    $v = isset( $item['value'] ) ? sanitize_text_field( (string) $item['value'] ) : '';
                    $l = isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : $v;
                    if ( $v === '' ) continue;
                    $clean[] = [ 'value' => $v, 'label' => $l !== '' ? $l : $v ];
                }
                $options = $clean;
            }
            if ( empty( $options ) ) {
                self::redirectWithError( 'missing_options', $id );
            }
        }

        if ( $id ) {
            // Update: field_key and field_type are locked.
            $repo->update( $id, [
                'label'       => $label,
                'is_required' => $is_required ? 1 : 0,
                'options'     => $options,
            ] );
        } else {
            // Create: generate key if blank, ensure unique.
            if ( $field_key === '' ) {
                $field_key = $repo->generateUniqueKey( $entity_type, $label );
            } else {
                // If user supplied a key that collides, suffix it.
                if ( $repo->getByKey( $entity_type, $field_key ) !== null ) {
                    $field_key = $repo->generateUniqueKey( $entity_type, $field_key );
                }
            }
            $repo->create( [
                'entity_type' => $entity_type,
                'field_key'   => $field_key,
                'label'       => $label,
                'field_type'  => $field_type,
                'is_required' => $is_required ? 1 : 0,
                'options'     => $options,
                'sort_order'  => self::nextSortOrder( $repo, $entity_type ),
                'is_active'   => 1,
            ] );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-config&tab=custom_fields&tt_msg=saved' ) );
        exit;
    }

    public static function handle_toggle(): void {
        if ( ! current_user_can( 'tt_manage_players' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_toggle_field_' . $id );

        $repo  = new CustomFieldsRepository();
        $field = $repo->get( $id );
        if ( $field ) {
            $repo->setActive( $id, empty( $field->is_active ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-config&tab=custom_fields&tt_msg=saved' ) );
        exit;
    }

    public static function handle_reorder(): void {
        if ( ! current_user_can( 'tt_manage_players' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_reorder_custom_fields', 'tt_nonce' );

        $order = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? $_POST['order'] : [];
        $pairs = [];
        foreach ( $order as $position => $field_id ) {
            $fid = absint( $field_id );
            if ( $fid ) {
                $pairs[ $fid ] = (int) $position;
            }
        }
        if ( ! empty( $pairs ) ) {
            ( new CustomFieldsRepository() )->reorder( $pairs );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-config&tab=custom_fields&tt_msg=saved' ) );
        exit;
    }

    /* ═══ Helpers ═══ */

    private static function redirectWithError( string $code, int $id ): void {
        $url = add_query_arg(
            [
                'page'     => 'tt-config',
                'tab'      => 'custom_fields',
                'crud'     => $id ? 'edit' : 'new',
                'field_id' => $id,
                'tt_error' => $code,
            ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    private static function typeLabel( string $type ): string {
        switch ( $type ) {
            case CustomFieldsRepository::TYPE_TEXT:     return __( 'Text', 'talenttrack' );
            case CustomFieldsRepository::TYPE_NUMBER:   return __( 'Number', 'talenttrack' );
            case CustomFieldsRepository::TYPE_SELECT:   return __( 'Select (dropdown)', 'talenttrack' );
            case CustomFieldsRepository::TYPE_CHECKBOX: return __( 'Checkbox', 'talenttrack' );
            case CustomFieldsRepository::TYPE_DATE:     return __( 'Date', 'talenttrack' );
            default: return $type;
        }
    }

    private static function nextSortOrder( CustomFieldsRepository $repo, string $entity_type ): int {
        $all = $repo->getAll( $entity_type );
        $max = 0;
        foreach ( $all as $f ) {
            if ( (int) $f->sort_order > $max ) $max = (int) $f->sort_order;
        }
        return $max + 10;
    }
}
