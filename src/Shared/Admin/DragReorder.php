<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DragReorder — drag-to-reorder UI for tables with a sort_order column.
 *
 * Sprint v2.19.0. Admin pages listing entries with a `sort_order` field
 * (tt_lookups, tt_eval_categories, tt_eval_subcategories, …) gain a
 * drag handle per row. Reordering triggers an AJAX POST to the shared
 * handler below; the server assigns sequential 0..N sort_order values
 * matching the dragged order and returns the new list.
 *
 * Usage inside a list-table render:
 *
 *   DragReorder::renderScript( 'lookup', $lookup_type_key );
 *   echo '<table class="tt-sortable-table">';
 *   echo '<tbody data-tt-sortable="1">';
 *   foreach ( $rows as $row ) {
 *     echo '<tr data-id="' . (int) $row->id . '">';
 *     echo '<td class="tt-drag-handle" title="Drag to reorder">⋮⋮</td>';
 *     // ... other cells
 *     echo '</tr>';
 *   }
 *
 * Server side:
 *   DragReorder::init();  // call once at plugin boot, wires the handler
 */
class DragReorder {

    public const NONCE_ACTION = 'tt_drag_reorder';

    /**
     * Supported tables. Entity key => ['table' => ..., 'scope_col' => ...]
     *
     * `scope_col` is the column used to narrow the update to a single
     * sub-list (e.g. only reorder rows of lookup_type='position',
     * leaving lookup_type='age_group' alone). NULL means no scope —
     * reorder affects the whole table.
     */
    private const TABLES = [
        'lookup'           => [ 'table' => 'tt_lookups',           'scope_col' => 'lookup_type', 'order_col' => 'sort_order' ],
        'eval_category'    => [ 'table' => 'tt_eval_categories',   'scope_col' => null,          'order_col' => 'display_order' ],
        'eval_subcategory' => [ 'table' => 'tt_eval_subcategories','scope_col' => 'main_id',     'order_col' => 'display_order' ],
    ];

    public static function init(): void {
        add_action( 'wp_ajax_tt_drag_reorder', [ __CLASS__, 'handle' ] );
    }

    /**
     * Emit the SortableJS + handler script. Call once per page.
     *
     * @param string $entity     One of the TABLES keys above.
     * @param string $scope      Value for the scope_col, or '' if none.
     */
    public static function renderScript( string $entity, string $scope = '' ): void {
        if ( ! isset( self::TABLES[ $entity ] ) ) return;

        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
        <style>
        .tt-sortable-table .tt-drag-handle {
            cursor: grab;
            color: #999;
            font-size: 20px;
            text-align: center;
            width: 30px;
            user-select: none;
            line-height: 1;
            letter-spacing: -3px;
        }
        .tt-sortable-table .tt-drag-handle:hover { color: #2271b1; }
        .tt-sortable-table tr.sortable-ghost {
            opacity: 0.4;
            background: #eaf4ff !important;
        }
        .tt-sortable-table tr.sortable-chosen {
            cursor: grabbing;
        }
        .tt-reorder-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 16px;
            background: #00a32a;
            color: #fff;
            border-radius: 4px;
            font-size: 13px;
            opacity: 0;
            transition: opacity 300ms ease;
            pointer-events: none;
            z-index: 999999;
        }
        .tt-reorder-toast.is-visible { opacity: 1; }
        .tt-reorder-toast.is-error { background: #b32d2e; }
        </style>
        <div class="tt-reorder-toast" id="tt-reorder-toast"></div>
        <script>
        (function(){
            var tbody = document.querySelector('[data-tt-sortable="1"]');
            if (!tbody || typeof Sortable === 'undefined') return;

            var toast = document.getElementById('tt-reorder-toast');
            function showToast(msg, isError){
                toast.textContent = msg;
                toast.classList.toggle('is-error', !!isError);
                toast.classList.add('is-visible');
                setTimeout(function(){ toast.classList.remove('is-visible'); }, 2200);
            }

            Sortable.create(tbody, {
                handle: '.tt-drag-handle',
                animation: 160,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onEnd: function(evt){
                    if (evt.oldIndex === evt.newIndex) return;
                    var ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
                        .map(function(tr){ return parseInt(tr.getAttribute('data-id'), 10); })
                        .filter(function(n){ return n > 0; });
                    var form = new FormData();
                    form.append('action', 'tt_drag_reorder');
                    form.append('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
                    form.append('entity', <?php echo wp_json_encode( $entity ); ?>);
                    form.append('scope',  <?php echo wp_json_encode( $scope ); ?>);
                    form.append('ids',    ids.join(','));
                    fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: form
                    }).then(function(r){ return r.json(); }).then(function(resp){
                        if (resp && resp.success) {
                            // Update visible sort_order values in the first cell of each row.
                            ids.forEach(function(id, idx){
                                var row = tbody.querySelector('tr[data-id="' + id + '"]');
                                if (!row) return;
                                var sortCell = row.querySelector('.tt-sort-order-cell');
                                if (sortCell) sortCell.textContent = idx;
                            });
                            showToast(<?php echo wp_json_encode( __( 'Order saved.', 'talenttrack' ) ); ?>);
                        } else {
                            showToast(<?php echo wp_json_encode( __( 'Save failed.', 'talenttrack' ) ); ?>, true);
                        }
                    }).catch(function(){
                        showToast(<?php echo wp_json_encode( __( 'Network error.', 'talenttrack' ) ); ?>, true);
                    });
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler. Receives entity + scope + comma-separated ids in
     * the target order; writes sequential sort_order values.
     */
    public static function handle(): void {
        check_ajax_referer( self::NONCE_ACTION );
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            wp_send_json_error( [ 'message' => 'unauthorized' ], 403 );
        }

        $entity = isset( $_POST['entity'] ) ? sanitize_key( (string) $_POST['entity'] ) : '';
        if ( ! isset( self::TABLES[ $entity ] ) ) {
            wp_send_json_error( [ 'message' => 'bad_entity' ] );
        }

        $scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['scope'] ) ) : '';
        $raw_ids = isset( $_POST['ids'] ) ? (string) wp_unslash( (string) $_POST['ids'] ) : '';
        $ids = array_values( array_filter( array_map( 'intval', explode( ',', $raw_ids ) ) ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => 'no_ids' ] );
        }

        $config = self::TABLES[ $entity ];
        global $wpdb;
        $table = $wpdb->prefix . $config['table'];

        // Validate ids all belong to the table (and, if scoped, the scope).
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        if ( $config['scope_col'] ) {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders}) AND {$config['scope_col']} = %s";
            $args = array_merge( $ids, [ $scope ] );
        } else {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})";
            $args = $ids;
        }
        $found = (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
        if ( $found !== count( $ids ) ) {
            wp_send_json_error( [ 'message' => 'id_mismatch' ] );
        }

        // Write sequential values in the table's order column.
        $order_col = $config['order_col'];
        foreach ( $ids as $idx => $id ) {
            $wpdb->update( $table, [ $order_col => $idx ], [ 'id' => $id ] );
        }

        wp_send_json_success( [ 'count' => count( $ids ) ] );
    }
}
