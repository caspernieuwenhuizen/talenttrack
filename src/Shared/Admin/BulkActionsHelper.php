<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;

/**
 * BulkActionsHelper — renders the shared UI bits for bulk archive/delete.
 *
 * Sprint v2.17.0. Every admin list view (players, teams, evaluations,
 * sessions, goals, people) wires up with a couple of method calls:
 *
 *   // Top of list, above the table:
 *   BulkActionsHelper::renderStatusTabs( 'player', $current_view, $base_url );
 *   BulkActionsHelper::openForm( 'player', $current_view );
 *   BulkActionsHelper::renderActionBar( $current_view );
 *
 *   // <table> ...
 *   //   <th class="check-column"><input type="checkbox" class="tt-bulk-select-all"></th>
 *   //   ... (per row) <td class="check-column"><input type="checkbox" name="ids[]" value="<?= $id ?>"></td>
 *   // </table>
 *
 *   BulkActionsHelper::renderActionBar( $current_view );  // bottom bar (optional)
 *   BulkActionsHelper::closeForm();
 *
 * The form action points at admin-post.php with action=tt_bulk_action.
 * The handler dispatches based on the selected bulk-action value.
 */
class BulkActionsHelper {

    /* ═══════════════ Registration ═══════════════ */

    public static function init(): void {
        add_action( 'admin_post_tt_bulk_action', [ __CLASS__, 'handle' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'maybeEnqueueScript' ] );
    }

    public static function maybeEnqueueScript( string $hook ): void {
        if ( strpos( $hook, 'talenttrack' ) === false && strpos( $hook, 'tt-' ) === false ) return;
        add_action( 'admin_footer', [ __CLASS__, 'inlineScript' ] );
    }

    public static function inlineScript(): void {
        $msg = esc_js( __( 'Delete permanently? This cannot be undone.', 'talenttrack' ) );
        ?>
        <script>
        (function(){
            // Select-all: toggles every .tt-bulk-row checkbox in the same form.
            document.addEventListener('change', function(e){
                if (!e.target.matches('.tt-bulk-select-all')) return;
                var form = e.target.closest('form');
                if (!form) return;
                var boxes = form.querySelectorAll('input.tt-bulk-row[type=checkbox]');
                boxes.forEach(function(b){ b.checked = e.target.checked; });
            });

            // Confirm before submitting a permanent-delete bulk action.
            document.addEventListener('submit', function(e){
                if (!e.target.matches('form.tt-bulk-form')) return;
                var sel = e.target.querySelector('select[name=tt_bulk_action]');
                if (!sel) return;
                if (sel.value === 'delete_permanent') {
                    var count = e.target.querySelectorAll('input.tt-bulk-row[type=checkbox]:checked').length;
                    if (count === 0) {
                        e.preventDefault();
                        alert(<?php echo wp_json_encode( __( 'No items selected.', 'talenttrack' ) ); ?>);
                        return;
                    }
                    if (!confirm(<?php echo wp_json_encode( __( 'Delete permanently? This cannot be undone.', 'talenttrack' ) ); ?> + ' (' + count + ')')) {
                        e.preventDefault();
                    }
                } else if (sel.value === '' || sel.value === '-1') {
                    e.preventDefault();
                    alert(<?php echo wp_json_encode( __( 'Select a bulk action first.', 'talenttrack' ) ); ?>);
                }
            });
        })();
        </script>
        <?php
    }

    /* ═══════════════ Rendering ═══════════════ */

    /**
     * Status tab bar — "Active (N) | Archived (N) | All (N)" links.
     *
     * @param string $entity     Entity key (see ArchiveRepository::TABLE_MAP)
     * @param string $current    Currently-active view
     * @param string $base_url   Base URL to append ?tt_view=X to (sans tt_view)
     */
    public static function renderStatusTabs( string $entity, string $current, string $base_url ): void {
        $repo   = new ArchiveRepository();
        $counts = $repo->counts( $entity );
        $base   = remove_query_arg( [ 'tt_view', 'paged' ], $base_url );

        $views = [
            'active'   => __( 'Active', 'talenttrack' ),
            'archived' => __( 'Archived', 'talenttrack' ),
            'all'      => __( 'All', 'talenttrack' ),
        ];
        ?>
        <ul class="subsubsub" style="margin:10px 0 8px;">
            <?php
            $i = 0;
            $total = count( $views );
            foreach ( $views as $key => $label ) :
                $url = add_query_arg( [ 'tt_view' => $key ], $base );
                $count = (int) ( $counts[ $key ] ?? 0 );
                $is_current = ( $current === $key );
                $sep = ++$i < $total ? ' |' : '';
                ?>
                <li>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="<?php echo $is_current ? 'current' : ''; ?>"
                       <?php if ( $is_current ) echo 'aria-current="page"'; ?>>
                        <?php echo esc_html( $label ); ?>
                        <span class="count">(<?php echo $count; ?>)</span>
                    </a><?php echo $sep; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <div style="clear:both;"></div>
        <?php
    }

    /**
     * Open a <form> wrapping the list table. Call closeForm() after the
     * table. The form posts to admin-post.php?action=tt_bulk_action.
     *
     * @param string $entity
     * @param string $current_view  Passed through so the handler can redirect back
     */
    public static function openForm( string $entity, string $current_view ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-bulk-form">
            <input type="hidden" name="action" value="tt_bulk_action" />
            <input type="hidden" name="entity" value="<?php echo esc_attr( $entity ); ?>" />
            <input type="hidden" name="tt_view" value="<?php echo esc_attr( $current_view ); ?>" />
            <?php wp_nonce_field( 'tt_bulk_action_' . $entity, 'tt_bulk_nonce' ); ?>
        <?php
    }

    public static function closeForm(): void {
        ?>
        </form>
        <?php
    }

    /**
     * The bulk-action dropdown + Apply button. Call once above and once
     * below the table.
     *
     * @param string $current_view  Determines which actions appear — 'archived'
     *                              view offers Restore + Delete permanently;
     *                              'active' offers Archive + Delete permanently.
     */
    public static function renderActionBar( string $current_view ): void {
        $can_hard_delete = current_user_can( 'tt_edit_settings' );
        ?>
        <div class="tablenav top" style="margin:8px 0;">
            <div class="alignleft actions bulkactions">
                <label for="tt_bulk_action" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'talenttrack' ); ?></label>
                <select name="tt_bulk_action" id="tt_bulk_action">
                    <option value="-1"><?php esc_html_e( 'Bulk actions', 'talenttrack' ); ?></option>
                    <?php if ( $current_view === 'archived' ) : ?>
                        <option value="restore"><?php esc_html_e( 'Restore', 'talenttrack' ); ?></option>
                    <?php else : ?>
                        <option value="archive"><?php esc_html_e( 'Archive', 'talenttrack' ); ?></option>
                    <?php endif; ?>
                    <?php if ( $can_hard_delete ) : ?>
                        <option value="delete_permanent"><?php esc_html_e( 'Delete permanently', 'talenttrack' ); ?></option>
                    <?php endif; ?>
                </select>
                <button type="submit" class="button action"><?php esc_html_e( 'Apply', 'talenttrack' ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Emit a single-row checkbox cell. Call this inside the <td>
     * (or let the caller wrap; this just emits the <input>).
     */
    public static function rowCheckbox( int $id ): void {
        ?>
        <input type="checkbox" class="tt-bulk-row" name="ids[]" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select row %d', 'talenttrack' ), $id ) ); ?>" />
        <?php
    }

    public static function selectAllCheckbox(): void {
        ?>
        <input type="checkbox" class="tt-bulk-select-all" aria-label="<?php echo esc_attr__( 'Select all rows', 'talenttrack' ); ?>" />
        <?php
    }

    /* ═══════════════ Handler ═══════════════ */

    /**
     * Process a bulk-action POST. Validates nonce, permissions, and
     * dispatches to the repository. Redirects back to the list with a
     * message querystring (tt_bulk_msg=archived:N, restored:N, etc.).
     */
    public static function handle(): void {
        $entity = isset( $_POST['entity'] ) ? sanitize_key( (string) $_POST['entity'] ) : '';
        $action = isset( $_POST['tt_bulk_action'] ) ? sanitize_key( (string) $_POST['tt_bulk_action'] ) : '';
        $view   = ArchiveRepository::sanitizeView( $_POST['tt_view'] ?? '' );
        $ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] )
            ? array_map( 'intval', (array) $_POST['ids'] )
            : [];

        // Nonce
        if ( ! check_admin_referer( 'tt_bulk_action_' . $entity, 'tt_bulk_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'talenttrack' ) );
        }

        // Permission — archive/restore require per-entity manage cap;
        // delete_permanent requires tt_manage_settings.
        $required_cap = self::capForEntity( $entity );
        if ( ! current_user_can( $required_cap ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        if ( $action === 'delete_permanent' && ! current_user_can( 'tt_edit_settings' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $repo = new ArchiveRepository();
        $count = 0;
        $msg_key = '';

        switch ( $action ) {
            case 'archive':
                $count = $repo->archive( $entity, $ids, get_current_user_id() );
                $msg_key = 'archived';
                break;
            case 'restore':
                $count = $repo->restore( $entity, $ids );
                $msg_key = 'restored';
                break;
            case 'delete_permanent':
                $count = $repo->deletePermanently( $entity, $ids );
                $msg_key = 'deleted';
                break;
            default:
                wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=talenttrack' ) );
                exit;
        }

        $back = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::pageSlugForEntity( $entity ) );
        $back = add_query_arg( [
            'tt_view'     => $view,
            'tt_bulk_msg' => $msg_key . ':' . $count,
        ], remove_query_arg( [ 'tt_bulk_msg' ], $back ) );
        wp_safe_redirect( $back );
        exit;
    }

    /**
     * Render the message banner if tt_bulk_msg is present in the URL.
     * Call at the top of each list view's wrap div.
     */
    public static function renderBulkMessage(): void {
        if ( ! isset( $_GET['tt_bulk_msg'] ) ) return;
        $raw = sanitize_text_field( wp_unslash( (string) $_GET['tt_bulk_msg'] ) );
        $parts = explode( ':', $raw, 2 );
        if ( count( $parts ) !== 2 ) return;
        [ $action, $count ] = $parts;
        $count = (int) $count;

        $text = '';
        switch ( $action ) {
            case 'archived':
                $text = sprintf(
                    /* translators: %d is number of items archived */
                    _n( '%d item archived.', '%d items archived.', $count, 'talenttrack' ),
                    $count
                );
                break;
            case 'restored':
                $text = sprintf(
                    _n( '%d item restored.', '%d items restored.', $count, 'talenttrack' ),
                    $count
                );
                break;
            case 'deleted':
                $text = sprintf(
                    _n( '%d item permanently deleted.', '%d items permanently deleted.', $count, 'talenttrack' ),
                    $count
                );
                break;
        }
        if ( $text === '' ) return;

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
    }

    private static function capForEntity( string $entity ): string {
        switch ( $entity ) {
            case 'player':     return 'tt_edit_players';
            case 'team':       return 'tt_edit_teams';
            case 'person':     return 'tt_edit_people';
            case 'evaluation': return 'tt_edit_evaluations';
            case 'session':    return 'tt_edit_sessions';
            case 'goal':       return 'tt_edit_goals';
            default:           return 'tt_edit_settings';
        }
    }

    private static function pageSlugForEntity( string $entity ): string {
        switch ( $entity ) {
            case 'player':     return 'tt-players';
            case 'team':       return 'tt-teams';
            case 'evaluation': return 'tt-evaluations';
            case 'session':    return 'tt-sessions';
            case 'goal':       return 'tt-goals';
            case 'person':     return 'tt-people';
            default:           return 'talenttrack';
        }
    }
}
