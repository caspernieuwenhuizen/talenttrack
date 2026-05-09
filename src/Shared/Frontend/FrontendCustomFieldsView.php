<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;

/**
 * FrontendCustomFieldsView — frontend admin-tier surface for the
 * custom fields editor.
 *
 * #0019 Sprint 5. List via FrontendListTable filtered by entity_type;
 * create/edit via separate ?action=new / ?id=N routes; up/down arrow
 * reorder per Sprint 4 pattern.
 */
class FrontendCustomFieldsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $cf_label = __( 'Custom fields', 'talenttrack' );
        if ( $action === 'new' || $id > 0 ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                $action === 'new' ? __( 'New custom field', 'talenttrack' ) : __( 'Edit custom field', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'custom-fields', $cf_label ) ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $cf_label );
        }

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New custom field', 'talenttrack' ) );
            self::renderForm( null );
            return;
        }
        if ( $id > 0 ) {
            $field = ( new CustomFieldsRepository() )->get( $id );
            self::renderHeader( $field ? sprintf( __( 'Edit custom field — %s', 'talenttrack' ), (string) $field->label ) : __( 'Custom field not found', 'talenttrack' ) );
            if ( ! $field ) {
                echo '<p class="tt-notice">' . esc_html__( 'That custom field no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $field );
            return;
        }

        self::renderHeader( __( 'Custom fields', 'talenttrack' ) );
        self::renderList();
    }

    private static function renderList(): void {
        $base = remove_query_arg( [ 'action', 'id' ] );
        $new  = add_query_arg( [ 'tt_view' => 'custom-fields', 'action' => 'new' ], $base );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new ) . '">'
            . esc_html__( 'New custom field', 'talenttrack' )
            . '</a></p>';

        $entity_options = [];
        foreach ( CustomFieldsRepository::allowedEntityTypes() as $e ) {
            $entity_options[ $e ] = ucfirst( $e );
        }

        $row_actions = [
            'edit' => [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'custom-fields', 'id' => '{id}' ], $base ),
            ],
            'delete' => [
                'label'       => __( 'Delete', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'custom-fields/{id}',
                'confirm'     => __( 'Delete this custom field? Any existing values will block the delete.', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'custom-fields',
            'columns'   => [
                'entity_type' => [ 'label' => __( 'Entity', 'talenttrack' ) ],
                'label'       => [ 'label' => __( 'Label',  'talenttrack' ) ],
                'field_key'   => [ 'label' => __( 'Key',    'talenttrack' ) ],
                'field_type'  => [ 'label' => __( 'Type',   'talenttrack' ) ],
            ],
            'filters' => [
                'entity_type' => [
                    'type'    => 'select',
                    'label'   => __( 'Entity', 'talenttrack' ),
                    'options' => $entity_options,
                ],
            ],
            'row_actions' => $row_actions,
            'search'      => [ 'placeholder' => __( 'Search label or key…', 'talenttrack' ) ],
            'empty_state' => __( 'No custom fields defined yet.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function renderForm( ?object $field ): void {
        $is_edit   = $field !== null;
        $rest_path = $is_edit ? 'custom-fields/' . (int) $field->id : 'custom-fields';
        $rest_meth = $is_edit ? 'PUT' : 'POST';

        $current_options = [];
        if ( $is_edit && ! empty( $field->options ) ) {
            $opts = json_decode( (string) $field->options, true );
            if ( is_array( $opts ) ) $current_options = array_map( 'strval', $opts );
        }

        ?>
        <form id="tt-customfield-form" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>">
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-cf-entity"><?php esc_html_e( 'Entity', 'talenttrack' ); ?></label>
                    <select id="tt-cf-entity" class="tt-input" name="entity_type" required <?php echo $is_edit ? 'disabled' : ''; ?>>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( CustomFieldsRepository::allowedEntityTypes() as $e ) : ?>
                            <option value="<?php echo esc_attr( $e ); ?>" <?php selected( (string) ( $field->entity_type ?? '' ), $e ); ?>><?php echo esc_html( ucfirst( $e ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ( $is_edit ) : ?>
                        <input type="hidden" name="entity_type" value="<?php echo esc_attr( (string) $field->entity_type ); ?>" />
                        <span class="tt-field-hint"><?php esc_html_e( 'Entity type cannot be changed after creation.', 'talenttrack' ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-cf-label"><?php esc_html_e( 'Label', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-cf-label" class="tt-input" name="label" required value="<?php echo esc_attr( (string) ( $field->label ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-cf-key"><?php esc_html_e( 'Key', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-cf-key" class="tt-input" name="field_key" pattern="[a-z0-9_]*" value="<?php echo esc_attr( (string) ( $field->field_key ?? '' ) ); ?>" />
                    <span class="tt-field-hint"><?php esc_html_e( 'Optional. Auto-generated from the label if empty. Cannot be changed once created.', 'talenttrack' ); ?></span>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-cf-type"><?php esc_html_e( 'Type', 'talenttrack' ); ?></label>
                    <select id="tt-cf-type" class="tt-input" name="field_type" required>
                        <?php foreach ( CustomFieldsRepository::allowedTypes() as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( (string) ( $field->field_type ?? '' ), $t ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-cf-options"><?php esc_html_e( 'Options (one per line, for select / multi-select / checkbox group)', 'talenttrack' ); ?></label>
                <textarea id="tt-cf-options" class="tt-input" name="options_text" rows="4"><?php echo esc_textarea( implode( "\n", $current_options ) ); ?></textarea>
                <span class="tt-field-hint"><?php esc_html_e( 'Ignored for non-list field types.', 'talenttrack' ); ?></span>
            </div>

            <div class="tt-field">
                <label>
                    <input type="checkbox" name="is_required" value="1" <?php checked( ! empty( $field->is_required ) ); ?> />
                    <?php esc_html_e( 'Required field', 'talenttrack' ); ?>
                </label>
            </div>
            <div class="tt-field">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?php checked( $is_edit ? ! empty( $field->is_active ) : true ); ?> />
                    <?php esc_html_e( 'Active (uncheck to soft-disable without deleting stored values)', 'talenttrack' ); ?>
                </label>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update field', 'talenttrack' ) : __( 'Save field', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>

        <script>
        // Convert the textarea "options_text" into an `options[]` array
        // before public.js's REST submit handler reads the form.
        (function(){
            var form = document.getElementById('tt-customfield-form');
            if (!form) return;
            form.addEventListener('submit', function(){
                var ta = form.querySelector('textarea[name="options_text"]');
                if (!ta) return;
                var values = ta.value.split(/\r?\n/).map(function(s){ return s.trim(); }).filter(Boolean);
                // Inject hidden fields named options[]
                form.querySelectorAll('input[name="options[]"]').forEach(function(el){ el.remove(); });
                values.forEach(function(v){
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'options[]';
                    input.value = v;
                    form.appendChild(input);
                });
                // The textarea itself isn't a real field server-side.
                ta.removeAttribute('name');
            }, true); // capture phase so this runs before public.js's submit
        })();
        </script>
        <?php
    }
}
