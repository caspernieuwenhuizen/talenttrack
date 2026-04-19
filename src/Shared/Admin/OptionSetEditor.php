<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OptionSetEditor — reusable admin UI block for managing a list of
 * {value, label} options.
 *
 * Renders:
 *   - A table of existing rows with drag-to-reorder, label + value inputs,
 *     and a delete button per row.
 *   - An "Add option" button.
 *   - A hidden input that receives the final JSON array on form submit.
 *
 * Initial reuse target: select-type custom field options.
 * Future reuse: anywhere we need to manage a dropdown's option list
 * (potentially replacing the per-type tt_lookups tabs).
 *
 * Depends on:
 *   - assets/js/admin-sortable.js  (drag reorder behaviour)
 *   - inline scripts emitted here for add/remove/serialize
 */
class OptionSetEditor {

    /**
     * Render the editor. Must be called inside a <form>.
     *
     * @param string $input_name        Name attribute of the hidden input that
     *                                   will hold the JSON payload on submit.
     * @param array<int, array{value:string,label:string}> $initial_options
     * @param string $id_prefix         Unique prefix if rendering multiple on one page.
     */
    public static function render( string $input_name, array $initial_options = [], string $id_prefix = 'tt-opts' ): void {
        // Ensure the sortable JS is enqueued (idempotent).
        wp_enqueue_script( 'tt-admin-sortable' );

        $container_id = esc_attr( $id_prefix . '-' . wp_generate_password( 6, false ) );
        $payload      = wp_json_encode( $initial_options, JSON_UNESCAPED_UNICODE );
        ?>
        <div class="tt-option-set-editor" id="<?php echo $container_id; ?>">
            <table class="widefat striped tt-option-set-table" style="max-width:620px;">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th><?php esc_html_e( 'Label', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'talenttrack' ); ?></th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody class="tt-option-rows" data-sortable="1">
                    <?php foreach ( $initial_options as $opt ) : ?>
                        <?php self::row( $opt['label'], $opt['value'] ); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button tt-add-option">
                    <?php esc_html_e( '+ Add option', 'talenttrack' ); ?>
                </button>
                <span class="description" style="margin-left:10px;">
                    <?php esc_html_e( 'Drag rows to reorder. Leave Value blank to auto-fill from Label.', 'talenttrack' ); ?>
                </span>
            </p>
            <input type="hidden"
                   name="<?php echo esc_attr( $input_name ); ?>"
                   class="tt-option-set-json"
                   value="<?php echo esc_attr( (string) $payload ); ?>" />
        </div>

        <template class="tt-option-row-template"><?php self::row( '', '' ); ?></template>

        <script>
        (function(){
            var container = document.getElementById('<?php echo $container_id; ?>');
            if (!container) return;
            var tbody    = container.querySelector('.tt-option-rows');
            var addBtn   = container.querySelector('.tt-add-option');
            var hiddenIn = container.querySelector('.tt-option-set-json');
            var tmpl     = container.querySelector('.tt-option-row-template');

            function serialise() {
                var out = [];
                var rows = tbody.querySelectorAll('tr.tt-option-row');
                rows.forEach(function(tr){
                    var lbl = tr.querySelector('.tt-opt-label').value.trim();
                    var val = tr.querySelector('.tt-opt-value').value.trim();
                    if (!lbl && !val) return; // skip entirely empty rows
                    if (!val) val = lbl.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
                    if (!val) return;
                    out.push({ value: val, label: lbl || val });
                });
                hiddenIn.value = JSON.stringify(out);
            }

            function bindRow(tr) {
                tr.querySelectorAll('input').forEach(function(inp){
                    inp.addEventListener('input', serialise);
                });
                var del = tr.querySelector('.tt-opt-delete');
                if (del) del.addEventListener('click', function(){
                    tr.parentNode.removeChild(tr);
                    serialise();
                });
            }

            // Bind existing rows.
            tbody.querySelectorAll('tr.tt-option-row').forEach(bindRow);

            // Add button — clone template.
            addBtn.addEventListener('click', function(){
                var clone = tmpl.content.firstElementChild.cloneNode(true);
                tbody.appendChild(clone);
                bindRow(clone);
                clone.querySelector('.tt-opt-label').focus();
            });

            // Re-serialise on sort end.
            container.addEventListener('tt:sortable:end', serialise);

            // Ensure final serialise before the form submits.
            var form = container.closest('form');
            if (form) form.addEventListener('submit', serialise, true);
        })();
        </script>
        <?php
    }

    private static function row( string $label, string $value ): void {
        ?>
        <tr class="tt-option-row">
            <td class="tt-opt-drag" style="cursor:move;text-align:center;color:#888;">☰</td>
            <td><input type="text" class="tt-opt-label regular-text" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Option label', 'talenttrack' ); ?>" /></td>
            <td><input type="text" class="tt-opt-value regular-text" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php esc_attr_e( 'auto', 'talenttrack' ); ?>" /></td>
            <td><button type="button" class="button-link-delete tt-opt-delete" title="<?php esc_attr_e( 'Remove', 'talenttrack' ); ?>">✕</button></td>
        </tr>
        <?php
    }
}
