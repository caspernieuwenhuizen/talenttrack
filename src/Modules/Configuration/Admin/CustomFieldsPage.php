<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;

/**
 * CustomFieldsPage — Sprint 1H (v2.11.0).
 *
 * TalentTrack → Custom Fields. Replaces the v2.6.0 "Player Custom Fields"
 * configuration tab. Supports five entity types (player, person, team,
 * session, goal) via a tab bar across the top, and a positioning
 * control (insert_after) that points at any native field slug on the
 * target entity's edit form or at "(at end)".
 *
 * Routes under admin.php?page=tt-custom-fields:
 *   - default                → list for the selected entity (?entity=player)
 *   - crud=new               → create form
 *   - crud=edit&field_id=N   → edit form
 *
 * Handlers (registered in ConfigurationPage::init for historical reasons —
 * this page is conceptually part of configuration):
 *   - tt_save_custom_field   → create / update
 *   - tt_toggle_custom_field → activate / deactivate
 *
 * Sprint 1H notes:
 *   - No drag-and-drop reorder this sprint. Custom fields within the
 *     same insert_after slug are ordered by sort_order; for now that's
 *     set manually in the edit form. Drag-and-drop is deferred.
 *   - Global key uniqueness is per (entity_type, field_key), NOT global
 *     — two different entities can both define a `shirt_size` field.
 */
class CustomFieldsPage {

    private const CAP = 'tt_manage_settings';

    /* ═══════════════ Router ═══════════════ */

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $entity   = self::resolveEntity();
        $action   = isset( $_GET['crud'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['crud'] ) ) : 'list';
        $field_id = isset( $_GET['field_id'] ) ? absint( $_GET['field_id'] ) : 0;

        if ( $action === 'new' ) {
            self::renderForm( $entity, 0 );
            return;
        }
        if ( $action === 'edit' && $field_id > 0 ) {
            self::renderForm( $entity, $field_id );
            return;
        }

        self::renderList( $entity );
    }

    /* ═══════════════ Views ═══════════════ */

    private static function renderList( string $entity ): void {
        $repo   = new CustomFieldsRepository();
        $fields = $repo->getAll( $entity );
        $slugs  = FormSlugContract::slugsForEntity( $entity );
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Custom Fields', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-custom-fields&entity=' . urlencode( $entity ) . '&crud=new' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Add New', 'talenttrack' ); ?>
                </a>
            </h1>

            <?php self::renderMessages(); ?>

            <?php self::renderEntityTabs( $entity ); ?>

            <p class="description">
                <?php esc_html_e( 'Define extra attributes you want to capture for this entity. Each custom field appears on the edit form at the position you pick — between native fields, or at the end.', 'talenttrack' ); ?>
            </p>

            <?php if ( empty( $fields ) ) : ?>
                <p><em><?php esc_html_e( 'No custom fields defined yet for this entity.', 'talenttrack' ); ?></em></p>
                <?php return; ?>
            <?php endif; ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Label', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Key', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Inserts after', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Sort', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Required', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th style="width:200px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $fields as $f ) :
                    $edit_url = admin_url( 'admin.php?page=tt-custom-fields&entity=' . urlencode( $entity ) . '&crud=edit&field_id=' . (int) $f->id );
                    $toggle_url = wp_nonce_url(
                        admin_url( 'admin-post.php?action=tt_toggle_custom_field&id=' . (int) $f->id . '&entity=' . urlencode( $entity ) ),
                        'tt_toggle_field_' . (int) $f->id
                    );
                    $insert_after_label = self::insertAfterLabel( (string) ( $f->insert_after ?? '' ), $slugs );
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) $f->label ); ?></a></strong></td>
                        <td><code><?php echo esc_html( (string) $f->field_key ); ?></code></td>
                        <td><?php echo esc_html( self::typeLabel( (string) $f->field_type ) ); ?></td>
                        <td><?php echo esc_html( $insert_after_label ); ?></td>
                        <td><?php echo (int) $f->sort_order; ?></td>
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
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderForm( string $entity, int $id ): void {
        $repo  = new CustomFieldsRepository();
        $field = $id ? $repo->get( $id ) : null;

        // Guard: editing a field whose entity doesn't match the URL param
        // would be confusing. Redirect to the right entity tab.
        if ( $field && (string) $field->entity_type !== $entity ) {
            $entity = (string) $field->entity_type;
        }

        $slugs         = FormSlugContract::slugsForEntity( $entity );
        $options       = $field ? CustomFieldsRepository::decodeOptions( (string) ( $field->options ?? '' ) ) : [];
        $insert_after  = $field ? (string) ( $field->insert_after ?? '' ) : '';
        $current_type  = $field ? (string) $field->field_type : CustomFieldsRepository::TYPE_TEXT;
        ?>
        <div class="wrap">
            <h1>
                <?php echo $field
                    ? esc_html__( 'Edit Custom Field', 'talenttrack' )
                    : esc_html__( 'New Custom Field', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-custom-fields&entity=' . urlencode( $entity ) ) ); ?>" class="page-title-action">
                    <?php esc_html_e( '← Back', 'talenttrack' ); ?>
                </a>
            </h1>

            <?php self::renderMessages(); ?>

            <p class="description">
                <?php printf(
                    /* translators: %s is an entity label like Players or Teams. */
                    esc_html__( 'This custom field will appear on the %s edit form.', 'talenttrack' ),
                    '<strong>' . esc_html( self::entityLabel( $entity ) ) . '</strong>'
                ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:800px;">
                <?php wp_nonce_field( 'tt_save_custom_field', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_custom_field" />
                <input type="hidden" name="entity_type" value="<?php echo esc_attr( $entity ); ?>" />
                <?php if ( $field ) : ?>
                    <input type="hidden" name="id" value="<?php echo (int) $field->id; ?>" />
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="tt_cf_label"><?php esc_html_e( 'Label', 'talenttrack' ); ?> *</label></th>
                        <td>
                            <input type="text" name="label" id="tt_cf_label" class="regular-text"
                                   value="<?php echo esc_attr( $field->label ?? '' ); ?>" required />
                            <p class="description">
                                <?php esc_html_e( 'The label shown next to the field on the form. Use the language you want it displayed in — no translation system for user-defined fields.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="tt_cf_key"><?php esc_html_e( 'Key', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="text" name="field_key" id="tt_cf_key" class="regular-text"
                                   value="<?php echo esc_attr( $field->field_key ?? '' ); ?>"
                                   <?php echo $field ? 'readonly' : ''; ?> />
                            <p class="description">
                                <?php if ( $field ) : ?>
                                    <?php esc_html_e( 'The stable identifier for this field. Cannot be changed after creation.', 'talenttrack' ); ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'Auto-generated from the label if left blank. Used internally; must be unique per entity.', 'talenttrack' ); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="tt_cf_type"><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</label></th>
                        <td>
                            <select name="field_type" id="tt_cf_type" <?php echo $field ? 'disabled' : ''; ?> required>
                                <?php foreach ( CustomFieldsRepository::allowedTypes() as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $current_type, $t ); ?>>
                                        <?php echo esc_html( self::typeLabel( $t ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $field ) : ?>
                                <input type="hidden" name="field_type" value="<?php echo esc_attr( $current_type ); ?>" />
                            <?php endif; ?>
                            <p class="description">
                                <?php if ( $field ) : ?>
                                    <?php esc_html_e( 'Type cannot be changed after creation.', 'talenttrack' ); ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'Determines what input is shown on the form.', 'talenttrack' ); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="tt_cf_insert_after"><?php esc_html_e( 'Insert after', 'talenttrack' ); ?></label></th>
                        <td>
                            <select name="insert_after" id="tt_cf_insert_after">
                                <option value=""<?php selected( $insert_after, '' ); ?>><?php esc_html_e( '(at end of form)', 'talenttrack' ); ?></option>
                                <?php foreach ( $slugs as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $insert_after, $slug ); ?>>
                                        <?php echo esc_html( sprintf(
                                            /* translators: %s is a form field label, e.g. "First name". */
                                            __( 'After: %s', 'talenttrack' ),
                                            $label
                                        ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Choose where on the form this field appears. "(at end)" places it below all native fields.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="tt_cf_sort"><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label></th>
                        <td>
                            <input type="number" name="sort_order" id="tt_cf_sort" class="small-text"
                                   value="<?php echo (int) ( $field->sort_order ?? 10 ); ?>" step="1" min="0" />
                            <p class="description">
                                <?php esc_html_e( 'When multiple custom fields share the same "Insert after" slug, lower sort order renders first.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr id="tt_cf_options_row" style="display:<?php echo self::typeNeedsOptions( $current_type ) ? '' : 'none'; ?>;">
                        <th><label for="tt_cf_options"><?php esc_html_e( 'Options', 'talenttrack' ); ?></label></th>
                        <td>
                            <textarea name="options_text" id="tt_cf_options" rows="6" class="large-text code" placeholder="value|Label
another|Another label"><?php echo esc_textarea( self::optionsToText( $options ) ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'One option per line. Use "value|Label" to give a stored value a different display label. Lines with no pipe use the same string as both value and label.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Required', 'talenttrack' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_required" value="1" <?php checked( ! empty( $field->is_required ) ); ?> />
                                <?php esc_html_e( 'Required on the form', 'talenttrack' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p>
                    <?php submit_button(
                        $field ? __( 'Update field', 'talenttrack' ) : __( 'Add field', 'talenttrack' ),
                        'primary', 'submit', false
                    ); ?>
                </p>
            </form>
        </div>

        <script>
        // Show/hide the Options row based on field type.
        (function(){
            var typeSel = document.getElementById('tt_cf_type');
            var optsRow = document.getElementById('tt_cf_options_row');
            if (!typeSel || !optsRow) return;
            function needsOptions(v) { return v === 'select' || v === 'multi_select'; }
            typeSel.addEventListener('change', function(){
                optsRow.style.display = needsOptions(typeSel.value) ? '' : 'none';
            });

            // Auto-suggest a field_key from the label (only when creating).
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

    /* ═══════════════ Handlers ═══════════════ */

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_save_custom_field', 'tt_nonce' );

        $id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $entity       = isset( $_POST['entity_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['entity_type'] ) ) : CustomFieldsRepository::ENTITY_PLAYER;
        $label        = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
        $field_key    = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['field_key'] ) ) : '';
        $field_type   = isset( $_POST['field_type'] ) ? (string) wp_unslash( $_POST['field_type'] ) : '';
        $is_required  = ! empty( $_POST['is_required'] );
        $options_text = isset( $_POST['options_text'] ) ? (string) wp_unslash( $_POST['options_text'] ) : '';
        $insert_after = isset( $_POST['insert_after'] ) ? sanitize_key( (string) wp_unslash( $_POST['insert_after'] ) ) : '';
        $sort_order   = isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 10;

        if ( ! in_array( $entity, CustomFieldsRepository::allowedEntityTypes(), true ) ) {
            self::redirectWithError( 'invalid_entity', $id, $entity );
        }
        if ( $label === '' ) {
            self::redirectWithError( 'missing_label', $id, $entity );
        }
        if ( ! in_array( $field_type, CustomFieldsRepository::allowedTypes(), true ) ) {
            self::redirectWithError( 'invalid_type', $id, $entity );
        }

        // Validate insert_after slug against the known slugs for the entity
        // plus an empty string (meaning "at end"). Unknown slugs are cleared
        // defensively rather than stored — keeps data tidy.
        $known_slugs = array_keys( FormSlugContract::slugsForEntity( $entity ) );
        if ( $insert_after !== '' && ! in_array( $insert_after, $known_slugs, true ) ) {
            $insert_after = '';
        }

        $repo = new CustomFieldsRepository();

        // Parse options from textarea if the type uses them.
        $options = null;
        if ( self::typeNeedsOptions( $field_type ) ) {
            $options = self::parseOptionsText( $options_text );
            if ( empty( $options ) ) {
                self::redirectWithError( 'missing_options', $id, $entity );
            }
        }

        if ( $id ) {
            // Update: field_key, field_type, entity_type are locked.
            $repo->update( $id, [
                'label'        => $label,
                'is_required'  => $is_required ? 1 : 0,
                'options'      => $options,
                'insert_after' => $insert_after ?: null,
                'sort_order'   => $sort_order,
            ] );
        } else {
            // Create: ensure key uniqueness within this entity.
            if ( $field_key === '' ) {
                $field_key = $repo->generateUniqueKey( $entity, $label );
            } elseif ( $repo->getByKey( $entity, $field_key ) !== null ) {
                $field_key = $repo->generateUniqueKey( $entity, $field_key );
            }
            $repo->create( [
                'entity_type'  => $entity,
                'field_key'    => $field_key,
                'label'        => $label,
                'field_type'   => $field_type,
                'is_required'  => $is_required ? 1 : 0,
                'options'      => $options,
                'insert_after' => $insert_after ?: null,
                'sort_order'   => $sort_order,
                'is_active'    => 1,
            ] );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-custom-fields&entity=' . urlencode( $entity ) . '&tt_msg=saved' ) );
        exit;
    }

    public static function handleToggle(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_toggle_field_' . $id );

        $entity = isset( $_GET['entity'] ) ? sanitize_key( (string) wp_unslash( $_GET['entity'] ) ) : CustomFieldsRepository::ENTITY_PLAYER;
        if ( ! in_array( $entity, CustomFieldsRepository::allowedEntityTypes(), true ) ) {
            $entity = CustomFieldsRepository::ENTITY_PLAYER;
        }

        $repo  = new CustomFieldsRepository();
        $field = $repo->get( $id );
        if ( $field ) {
            $repo->setActive( $id, empty( $field->is_active ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tt-custom-fields&entity=' . urlencode( $entity ) . '&tt_msg=saved' ) );
        exit;
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function resolveEntity(): string {
        $raw = isset( $_GET['entity'] ) ? sanitize_key( (string) wp_unslash( $_GET['entity'] ) ) : '';
        if ( $raw && in_array( $raw, CustomFieldsRepository::allowedEntityTypes(), true ) ) {
            return $raw;
        }
        return CustomFieldsRepository::ENTITY_PLAYER;
    }

    private static function renderEntityTabs( string $current ): void {
        $entities = [
            CustomFieldsRepository::ENTITY_PLAYER,
            CustomFieldsRepository::ENTITY_PERSON,
            CustomFieldsRepository::ENTITY_TEAM,
            CustomFieldsRepository::ENTITY_SESSION,
            CustomFieldsRepository::ENTITY_GOAL,
        ];
        ?>
        <nav class="nav-tab-wrapper" style="margin-top:12px;">
            <?php foreach ( $entities as $e ) :
                $url = admin_url( 'admin.php?page=tt-custom-fields&entity=' . urlencode( $e ) );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="nav-tab <?php echo $current === $e ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( self::entityLabel( $e ) ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    public static function entityLabel( string $entity ): string {
        switch ( $entity ) {
            case CustomFieldsRepository::ENTITY_PLAYER:  return __( 'Players', 'talenttrack' );
            case CustomFieldsRepository::ENTITY_PERSON:  return __( 'People', 'talenttrack' );
            case CustomFieldsRepository::ENTITY_TEAM:    return __( 'Teams', 'talenttrack' );
            case CustomFieldsRepository::ENTITY_SESSION: return __( 'Sessions', 'talenttrack' );
            case CustomFieldsRepository::ENTITY_GOAL:    return __( 'Goals', 'talenttrack' );
        }
        return ucfirst( $entity );
    }

    public static function typeLabel( string $type ): string {
        switch ( $type ) {
            case CustomFieldsRepository::TYPE_TEXT:         return __( 'Text', 'talenttrack' );
            case CustomFieldsRepository::TYPE_TEXTAREA:     return __( 'Long text', 'talenttrack' );
            case CustomFieldsRepository::TYPE_NUMBER:       return __( 'Number', 'talenttrack' );
            case CustomFieldsRepository::TYPE_SELECT:       return __( 'Select (dropdown)', 'talenttrack' );
            case CustomFieldsRepository::TYPE_MULTI_SELECT: return __( 'Multi-select', 'talenttrack' );
            case CustomFieldsRepository::TYPE_CHECKBOX:     return __( 'Checkbox', 'talenttrack' );
            case CustomFieldsRepository::TYPE_DATE:         return __( 'Date', 'talenttrack' );
            case CustomFieldsRepository::TYPE_URL:          return __( 'URL', 'talenttrack' );
            case CustomFieldsRepository::TYPE_EMAIL:        return __( 'Email', 'talenttrack' );
            case CustomFieldsRepository::TYPE_PHONE:        return __( 'Phone', 'talenttrack' );
        }
        return $type;
    }

    private static function typeNeedsOptions( string $type ): bool {
        return in_array( $type, [
            CustomFieldsRepository::TYPE_SELECT,
            CustomFieldsRepository::TYPE_MULTI_SELECT,
        ], true );
    }

    private static function insertAfterLabel( string $slug, array $slugs ): string {
        if ( $slug === '' ) return __( '(at end)', 'talenttrack' );
        return isset( $slugs[ $slug ] )
            ? $slugs[ $slug ]
            : sprintf( '%s (%s)', $slug, __( 'missing', 'talenttrack' ) );
    }

    /**
     * Convert an array of {value,label} option records into one-per-line
     * "value|label" text for the textarea editor.
     *
     * @param array<int, array{value:string, label:string}> $options
     */
    private static function optionsToText( array $options ): string {
        $lines = [];
        foreach ( $options as $o ) {
            $v = isset( $o['value'] ) ? (string) $o['value'] : '';
            $l = isset( $o['label'] ) ? (string) $o['label'] : $v;
            if ( $v === '' ) continue;
            $lines[] = $v === $l ? $v : ( $v . '|' . $l );
        }
        return implode( "\n", $lines );
    }

    /**
     * Parse the options textarea into {value,label} records.
     *
     * @return array<int, array{value:string, label:string}>
     */
    private static function parseOptionsText( string $raw ): array {
        $out = [];
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        if ( ! is_array( $lines ) ) return [];
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) continue;
            $parts = array_map( 'trim', explode( '|', $line, 2 ) );
            $v = isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '';
            $l = isset( $parts[1] ) && $parts[1] !== '' ? sanitize_text_field( $parts[1] ) : $v;
            if ( $v === '' ) continue;
            $out[] = [ 'value' => $v, 'label' => $l ];
        }
        return $out;
    }

    private static function redirectWithError( string $code, int $id, string $entity ): void {
        $url = add_query_arg(
            [
                'page'     => 'tt-custom-fields',
                'entity'   => $entity,
                'crud'     => $id ? 'edit' : 'new',
                'field_id' => $id,
                'tt_error' => $code,
            ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
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
                'missing_label'   => __( 'Label is required.', 'talenttrack' ),
                'invalid_type'    => __( 'That field type is not supported.', 'talenttrack' ),
                'invalid_entity'  => __( 'That entity type is not supported.', 'talenttrack' ),
                'missing_options' => __( 'Select and multi-select fields require at least one option.', 'talenttrack' ),
            ];
            $text = $map[ $err ] ?? __( 'Something went wrong.', 'talenttrack' );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
        }
    }
}
