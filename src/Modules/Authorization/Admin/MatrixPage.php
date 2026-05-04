<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\Admin\MatrixEntityCatalog;

/**
 * MatrixPage — Authorization → Matrix admin UI (#0033 Sprint 3).
 *
 * Renders the persona × entity grid with three-pill toggles per cell
 * (read / change / create_delete) and a scope dropdown. Default seed
 * values render dimmed; admin-edited values render bold. A "Reset to
 * defaults" button reseeds the table from `config/authorization_seed.php`.
 *
 * Capability gate: WordPress `administrator` (sharper than
 * `tt_edit_settings` — redefining what every role can do is the kind
 * of action that should NOT be delegable to a non-admin).
 *
 * Audit: every save writes to `tt_authorization_changelog` (bridge until
 * #0021 ships and the audit log absorbs it).
 */
class MatrixPage {

    public static function init(): void {
        add_action( 'admin_post_tt_matrix_save',  [ __CLASS__, 'handleSave' ] );
        add_action( 'admin_post_tt_matrix_reset', [ __CLASS__, 'handleReset' ] );
        // v3.89.0 — round-trip: export current matrix as XLSX/CSV,
        // import an edited file with diff preview before apply.
        add_action( 'admin_post_tt_matrix_export', [ __CLASS__, 'handleExport' ] );
        add_action( 'admin_post_tt_matrix_import_preview', [ __CLASS__, 'handleImportPreview' ] );
        add_action( 'admin_post_tt_matrix_import_apply',   [ __CLASS__, 'handleImportApply' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $repo = new MatrixRepository();
        $personas = $repo->personas();
        $entities = $repo->entities();
        $grid = $repo->asGrid();

        $activities = [
            'read'          => __( 'R', 'talenttrack' ),
            'change'        => __( 'C', 'talenttrack' ),
            'create_delete' => __( 'D', 'talenttrack' ),
        ];
        $scope_kinds = [ 'global', 'team', 'player', 'self' ];

        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_msg'] ) ) : '';

        // Discoverability — is the matrix bridge actually applying?
        // When `tt_authorization_active` is 0, the user_has_cap filter
        // doesn't fire and matrix edits have no runtime effect; the
        // operator needs to know that explicitly so they don't think
        // "I changed it but nothing happened" is a bug in their edit.
        $matrix_active = false;
        if ( class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            $cfg = new \TT\Infrastructure\Config\ConfigService();
            $matrix_active = (bool) $cfg->getBool( 'tt_authorization_active', false );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Authorization Matrix', 'talenttrack' ); ?> <?php \TT\Shared\Admin\HelpLink::render( 'access-control' ); ?></h1>
            <p style="color:#5b6e75; max-width: 800px;">
                <?php esc_html_e( 'Define what each persona can do on each entity. R = read, C = change (edit), D = create/delete. Scope narrows the grant: "global" applies everywhere; "team" or "player" require the user to also have that scope assignment.', 'talenttrack' ); ?>
            </p>

            <?php if ( ! $matrix_active ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e( 'The matrix is currently dormant.', 'talenttrack' ); ?></strong>
                        <?php esc_html_e( 'Your edits are saved but have no runtime effect: native WP capability checks decide instead. Enable the bridge on Authorization → Migration preview to make the matrix authoritative.', 'talenttrack' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-matrix-preview' ) ); ?>"><?php esc_html_e( 'Open Migration preview →', 'talenttrack' ); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( $msg === 'saved' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Matrix saved.', 'talenttrack' ); ?></p></div>
            <?php elseif ( $msg === 'reset' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Matrix reset to defaults.', 'talenttrack' ); ?></p></div>
            <?php elseif ( $msg === 'imported' ) :
                $n = isset( $_GET['tt_n'] ) ? (int) $_GET['tt_n'] : 0; ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    /* translators: %d: number of permission rows imported */
                    printf( esc_html__( 'Matrix imported — %d rows applied.', 'talenttrack' ), $n );
                    ?>
                </p></div>
            <?php endif; ?>

            <p style="margin-top:14px;">
                <label for="tt-matrix-search" style="display:inline-block; margin-right:8px; font-weight:600;">
                    <?php esc_html_e( 'Find entity:', 'talenttrack' ); ?>
                </label>
                <input
                    type="search"
                    id="tt-matrix-search"
                    placeholder="<?php echo esc_attr__( 'Type to filter — matches entity slug, label, or consumer (e.g. \'audit\', \'podium\', \'lookups\')', 'talenttrack' ); ?>"
                    style="min-width:420px; padding:6px 10px;"
                />
                <span id="tt-matrix-search-count" style="margin-left:8px; color:#888; font-size:12px;"></span>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tt-matrix-form">
                <?php wp_nonce_field( 'tt_matrix_save', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_save" />

                <table class="widefat striped tt-matrix-table" style="margin-top:14px;">
                    <thead style="position:sticky; top:32px; background:#fff; z-index:3;">
                        <tr>
                            <th style="position:sticky; left:0; background:#fff; z-index:4; min-width:280px;"><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                            <?php foreach ( $personas as $persona ) : ?>
                                <th style="background:#fff;"><?php echo esc_html( self::personaLabel( $persona ) ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $persona_cols = max( 1, count( $personas ) );
                    $grouped = self::groupEntitiesByCategory( $entities );
                    foreach ( $grouped as $category => $rows_in_category ) :
                        ?>
                        <tr class="tt-matrix-category">
                            <th colspan="<?php echo (int) ( 1 + $persona_cols ); ?>"
                                style="position:sticky; left:0; background:#eef2f5; color:#1d3a4a; text-align:left; padding:8px 12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; font-size:11px;">
                                <?php echo esc_html( $category ); ?>
                            </th>
                        </tr>
                        <?php
                        foreach ( $rows_in_category as $entity_row ) :
                            $entity = $entity_row['entity'];
                            $module = $entity_row['module_class'];
                            $entity_label = MatrixEntityCatalog::entityLabel( $entity );
                            $consumers   = MatrixEntityCatalog::consumersOf( $entity );
                            $consumer_labels = array_map(
                                static fn( array $c ): string => (string) $c['label'],
                                $consumers
                            );
                            $consumer_blob = implode( ' ', $consumer_labels );
                            // data-tt-haystack drives the fuzzy search filter.
                            $haystack = strtolower( $entity . ' ' . $entity_label . ' ' . self::shortModule( $module ) . ' ' . $consumer_blob );
                            ?>
                        <tr data-tt-haystack="<?php echo esc_attr( $haystack ); ?>">
                            <td style="position:sticky; left:0; background:#fff; font-weight:600; min-width:280px;">
                                <?php echo esc_html( $entity_label ); ?>
                                <small style="display:block; color:#888; font-weight:400; font-family:monospace;"><?php echo esc_html( $entity ); ?> · <?php echo esc_html( self::shortModule( $module ) ); ?></small>
                                <?php if ( ! empty( $consumers ) ) : ?>
                                    <small class="tt-tile-used-by" style="display:block; color:#1d3a4a; font-weight:400; margin-top:2px;">
                                        <?php esc_html_e( 'Used by:', 'talenttrack' ); ?>
                                        <?php foreach ( $consumers as $idx => $cons ) : ?>
                                            <?php if ( $idx > 0 ) echo ', '; ?>
                                            <?php self::renderConsumerChip( $cons, $entity ); ?>
                                        <?php endforeach; ?>
                                    </small>
                                <?php else : ?>
                                    <small style="display:block; color:#b32d2e; font-weight:400; margin-top:2px;">
                                        <?php esc_html_e( 'No tile / admin surface gates on this entity yet.', 'talenttrack' ); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <?php foreach ( $personas as $persona ) :
                                $cell = $grid[ $persona ][ $entity ] ?? [];
                                ?>
                                <td style="text-align:center; padding:6px;">
                                    <?php foreach ( $activities as $activity => $abbrev ) :
                                        $details   = $cell[ $activity ] ?? null;
                                        $is_set    = (bool) $details;
                                        $is_default= $details ? (int) $details['is_default'] : 1;
                                        $css = $is_set
                                            ? ( $is_default
                                                ? 'background:#c5e8d2; color:#196a32; opacity:0.7;'
                                                : 'background:#196a32; color:#fff; font-weight:700;' )
                                            : 'background:#f0f0f1; color:#999;';
                                        ?>
                                        <label style="display:inline-block; padding:2px 4px; margin:0 1px; border-radius:3px; cursor:pointer; font-family:monospace; font-size:11px; <?php echo $css; ?>" title="<?php echo esc_attr( self::cellTitle( $persona, $entity, $activity, $is_set, (bool) $is_default ) ); ?>">
                                            <input type="checkbox"
                                                   name="cell[<?php echo esc_attr( $persona . '|' . $entity . '|' . $activity ); ?>]"
                                                   value="1"
                                                   style="display:none;"
                                                   <?php checked( $is_set ); ?> />
                                            <?php echo esc_html( $abbrev ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <br>
                                    <select name="scope[<?php echo esc_attr( $persona . '|' . $entity ); ?>]" style="font-size:11px; padding:1px;">
                                        <?php
                                        $current_scope = '';
                                        foreach ( [ 'read', 'change', 'create_delete' ] as $a ) {
                                            if ( isset( $cell[ $a ]['scope_kind'] ) ) {
                                                $current_scope = $cell[ $a ]['scope_kind'];
                                                break;
                                            }
                                        }
                                        if ( $current_scope === '' ) $current_scope = 'global';
                                        foreach ( $scope_kinds as $sk ) :
                                            ?>
                                            <option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $current_scope, $sk ); ?>><?php echo esc_html( $sk ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:14px; display:flex; align-items:center; gap:12px;">
                    <?php submit_button( __( 'Save matrix', 'talenttrack' ), 'primary', 'submit', false ); ?>
                    <span id="tt-matrix-dirty-pill" style="display:none; background:#dba617; color:#1f1300; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:0.04em;">
                        <?php esc_html_e( 'UNSAVED CHANGES', 'talenttrack' ); ?>
                    </span>
                </p>
            </form>

            <style>
            /* #0080 Wave C2 — tile-consumer chips on the entity rows. */
            .tt-tile-chip { background:#eef2f5; border:1px solid #c4d3dc; padding:1px 8px; border-radius:10px; cursor:pointer; font:inherit; color:#1d3a4a; line-height:1.4; }
            .tt-tile-chip:hover { background:#dde6ec; }
            .tt-tile-chip[aria-expanded="true"] { background:#1d3a4a; color:#fff; border-color:#1d3a4a; }
            .tt-tile-chip-wrap { position:relative; display:inline-block; }
            .tt-tile-popover { position:absolute; top:calc(100% + 4px); left:0; min-width:260px; max-width:380px; background:#fff; border:1px solid #c4d3dc; border-radius:6px; padding:10px 12px; box-shadow:0 6px 16px rgba(0,0,0,.08); z-index:30; font-size:11px; line-height:1.5; color:#333; text-align:left; }
            .tt-tile-popover[hidden] { display:none; }
            .tt-tile-popover code { background:#f6f7f8; padding:1px 4px; border-radius:3px; font-family:monospace; font-size:11px; color:#1d3a4a; }
            .tt-tile-popover dl { margin:6px 0 0; display:grid; grid-template-columns:90px 1fr; gap:2px 8px; }
            .tt-tile-popover dt { font-weight:600; color:#5b6e75; margin:0; }
            .tt-tile-popover dd { margin:0; word-break:break-word; }
            .tt-tile-popover-type { color:#888; font-weight:400; margin-left:4px; }
            .tt-tile-popover-precedence { margin:8px 0 0; color:#5b6e75; font-style:italic; border-top:1px solid #eef2f5; padding-top:6px; }
            </style>

            <script>
            // #0080 Wave C2 — toggle the per-tile gate popover.
            (function(){
                var chips = document.querySelectorAll('.tt-tile-chip');
                if (!chips.length) return;

                function closeAll() {
                    document.querySelectorAll('.tt-tile-chip[aria-expanded="true"]').forEach(function(c){
                        c.setAttribute('aria-expanded', 'false');
                        var pop = c.nextElementSibling;
                        if (pop) { pop.hidden = true; pop.setAttribute('aria-hidden', 'true'); }
                    });
                }

                chips.forEach(function(btn){
                    btn.addEventListener('click', function(e){
                        e.preventDefault();
                        e.stopPropagation();
                        var open = btn.getAttribute('aria-expanded') === 'true';
                        closeAll();
                        if (!open) {
                            btn.setAttribute('aria-expanded', 'true');
                            var pop = btn.nextElementSibling;
                            if (pop) { pop.hidden = false; pop.setAttribute('aria-hidden', 'false'); }
                        }
                    });
                });

                document.addEventListener('click', function(e){
                    if (!e.target.closest('.tt-tile-chip-wrap')) closeAll();
                });
                document.addEventListener('keydown', function(e){
                    if (e.key === 'Escape') closeAll();
                });
            })();
            </script>

            <script>
            // Inline visual feedback on cell click. The form is save-on-submit
            // (no per-click AJAX), so without this the labels look unchanged
            // and the user can't tell if their click registered.
            (function(){
                var ON_DEFAULT  = 'background:#c5e8d2; color:#196a32; opacity:0.7;';
                var ON_EDITED   = 'background:#196a32; color:#fff; font-weight:700;';
                var OFF         = 'background:#f0f0f1; color:#999;';
                var BASE        = 'display:inline-block; padding:2px 4px; margin:0 1px; border-radius:3px; cursor:pointer; font-family:monospace; font-size:11px; ';
                var pill = document.getElementById('tt-matrix-dirty-pill');
                document.querySelectorAll('input[type="checkbox"][name^="cell["]').forEach(function(cb){
                    cb.addEventListener('change', function(){
                        var label = cb.closest('label');
                        if (!label) return;
                        // After admin edits, the cell is no longer "default" — paint it edited (or off).
                        label.setAttribute('style', BASE + (cb.checked ? ON_EDITED : OFF));
                        if (pill) pill.style.display = 'inline-block';
                    });
                });
                document.querySelectorAll('select[name^="scope["]').forEach(function(sel){
                    sel.addEventListener('change', function(){ if (pill) pill.style.display = 'inline-block'; });
                });
            })();

            // Fuzzy filter on the entity rows. Matches against the
            // entity slug, localized label, owning module, and any
            // surface (tile/menu/dashboard) that consumes the entity.
            // Hides rows whose haystack doesn't match every search
            // token; category headers are hidden when every row in
            // their category is hidden.
            (function(){
                var input  = document.getElementById('tt-matrix-search');
                var counter = document.getElementById('tt-matrix-search-count');
                if (!input) return;
                var rows = Array.from(document.querySelectorAll('tr[data-tt-haystack]'));
                var headers = Array.from(document.querySelectorAll('tr.tt-matrix-category'));
                var totalRows = rows.length;

                function applyFilter(){
                    var q = (input.value || '').trim().toLowerCase();
                    var tokens = q.length ? q.split(/\s+/) : [];
                    var visible = 0;
                    rows.forEach(function(r){
                        var hay = r.getAttribute('data-tt-haystack') || '';
                        var match = tokens.every(function(t){ return hay.indexOf(t) !== -1; });
                        r.style.display = match ? '' : 'none';
                        if (match) visible++;
                    });
                    // Hide category headers whose subsequent rows are all hidden.
                    headers.forEach(function(h){
                        var anyVisible = false;
                        var n = h.nextElementSibling;
                        while (n && !n.classList.contains('tt-matrix-category')) {
                            if (n.style.display !== 'none') { anyVisible = true; break; }
                            n = n.nextElementSibling;
                        }
                        h.style.display = anyVisible ? '' : 'none';
                    });
                    if (counter) {
                        counter.textContent = q.length
                            ? (visible + ' / ' + totalRows)
                            : '';
                    }
                }
                input.addEventListener('input', applyFilter);
                // Esc clears.
                input.addEventListener('keydown', function(e){
                    if (e.key === 'Escape') { input.value = ''; applyFilter(); }
                });
            })();
            </script>

            <hr style="margin:24px 0;" />

            <h2><?php esc_html_e( 'Round-trip with a stakeholder (Excel / CSV)', 'talenttrack' ); ?></h2>
            <p style="color:#5b6e75; max-width: 800px;">
                <?php esc_html_e( 'Export the current matrix, edit it offline (Excel, Google Sheets, anything that handles CSV), then re-upload. A diff preview shows additions and removals before anything is committed. .xlsx is the cleaner format; .csv is universal — use it if your host blocks Office uploads.', 'talenttrack' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right:8px;">
                <?php wp_nonce_field( 'tt_matrix_export', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_export" />
                <input type="hidden" name="format" value="xlsx" />
                <button type="submit" class="button"><?php esc_html_e( 'Download .xlsx', 'talenttrack' ); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                <?php wp_nonce_field( 'tt_matrix_export', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_export" />
                <input type="hidden" name="format" value="csv" />
                <button type="submit" class="button"><?php esc_html_e( 'Download .csv', 'talenttrack' ); ?></button>
            </form>

            <h3 style="margin-top:18px;"><?php esc_html_e( 'Upload edited file', 'talenttrack' ); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'tt_matrix_import_preview', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_import_preview" />
                <input type="file" name="seed_file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Preview changes', 'talenttrack' ); ?></button>
                <p style="color:#888; font-size:12px; margin-top:6px;">
                    <?php esc_html_e( 'The file is parsed in-memory; never written to wp-content/uploads. If your host rejects .xlsx, the .csv path bypasses the Office allowlist entirely.', 'talenttrack' ); ?>
                </p>
            </form>

            <?php
            // Render the diff preview if we just came from an upload.
            self::renderImportPreviewIfPending();
            ?>

            <hr style="margin:24px 0;" />

            <h2><?php esc_html_e( 'Reset to defaults', 'talenttrack' ); ?></h2>
            <p style="color:#5b6e75;">
                <?php esc_html_e( 'Reseeds the matrix from the shipped seed file. Any admin-edited rows are lost. Logged in the changelog.', 'talenttrack' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  onsubmit="return confirm('<?php echo esc_js( __( 'Reset every persona\'s permissions to the shipped defaults? This cannot be undone (except by re-editing).', 'talenttrack' ) ); ?>');">
                <?php wp_nonce_field( 'tt_matrix_reset', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_reset" />
                <button type="submit" class="button"><?php esc_html_e( 'Reset to defaults', 'talenttrack' ); ?></button>
            </form>

            <hr style="margin:24px 0;" />

            <h2><?php esc_html_e( 'Tiles not controlled by the matrix', 'talenttrack' ); ?></h2>
            <p style="color:#5b6e75; max-width: 800px;">
                <?php esc_html_e( 'These tiles gate visibility on a custom callback rather than a capability tied to a matrix entity. Editing R/C/D for any persona above will not hide them. Use the dashboard tile\'s `hide_for_personas` field, or refactor the callback, to suppress them per persona.', 'talenttrack' ); ?>
            </p>
            <?php
            $callback_tiles = MatrixEntityCatalog::callbackGatedTiles();
            if ( empty( $callback_tiles ) ) {
                echo '<p><em>' . esc_html__( 'None — every tile is matrix-controlled. Nice.', 'talenttrack' ) . '</em></p>';
            } else {
                echo '<ul style="margin-left:18px; list-style:disc;">';
                foreach ( $callback_tiles as $t ) {
                    echo '<li>' . esc_html( $t['label'] );
                    if ( $t['view_slug'] !== '' ) {
                        echo ' <code style="color:#888;">' . esc_html( $t['view_slug'] ) . '</code>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            ?>

            <hr style="margin:24px 0;" />

            <h2><?php esc_html_e( 'Recent changes', 'talenttrack' ); ?></h2>
            <?php self::renderChangelog(); ?>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_save', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'matrix.save' );

        $repo = new MatrixRepository();
        $entities = $repo->entities();
        $personas = $repo->personas();
        $grid = $repo->asGrid();

        $cells = isset( $_POST['cell'] ) && is_array( $_POST['cell'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['cell'] ) )
            : [];
        $scopes = isset( $_POST['scope'] ) && is_array( $_POST['scope'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['scope'] ) )
            : [];

        $entity_module = [];
        foreach ( $entities as $e ) $entity_module[ $e['entity'] ] = $e['module_class'];

        $activities = [ 'read', 'change', 'create_delete' ];
        $scope_kinds = [ 'global', 'team', 'player', 'self' ];

        global $wpdb;
        $p = $wpdb->prefix;
        $now = current_time( 'mysql' );
        $actor = get_current_user_id();

        foreach ( $personas as $persona ) {
            foreach ( $entities as $e ) {
                $entity = $e['entity'];
                $module = $e['module_class'];
                $scope_key = $persona . '|' . $entity;
                $scope_kind = isset( $scopes[ $scope_key ] ) && in_array( $scopes[ $scope_key ], $scope_kinds, true )
                    ? $scopes[ $scope_key ]
                    : 'global';
                foreach ( $activities as $activity ) {
                    $cell_key = $persona . '|' . $entity . '|' . $activity;
                    $now_set = isset( $cells[ $cell_key ] );
                    $was_details = $grid[ $persona ][ $entity ][ $activity ] ?? null;
                    $was_set = (bool) $was_details;
                    $was_scope = $was_details['scope_kind'] ?? 'global';

                    if ( $now_set === $was_set && $was_scope === $scope_kind ) continue;

                    if ( $now_set ) {
                        // If the row exists with a different scope, remove + insert
                        // (PRIMARY UNIQUE is on (persona, entity, activity, scope_kind)).
                        if ( $was_set && $was_scope !== $scope_kind ) {
                            $repo->removeRow( $persona, $entity, $activity, $was_scope );
                        }
                        $repo->setRow( $persona, $entity, $activity, $scope_kind, $module );
                        $wpdb->insert( "{$p}tt_authorization_changelog", [
                            'persona'      => $persona,
                            'entity'       => $entity,
                            'activity'     => $activity,
                            'scope_kind'   => $scope_kind,
                            'change_type'  => $was_set ? 'scope_change' : 'grant',
                            'before_value' => $was_set ? 1 : 0,
                            'after_value'  => 1,
                            'actor_user_id'=> $actor,
                            'note'         => $was_set ? "scope: {$was_scope} → {$scope_kind}" : null,
                            'created_at'   => $now,
                        ] );
                    } elseif ( $was_set ) {
                        $repo->removeRow( $persona, $entity, $activity, $was_scope );
                        $wpdb->insert( "{$p}tt_authorization_changelog", [
                            'persona'      => $persona,
                            'entity'       => $entity,
                            'activity'     => $activity,
                            'scope_kind'   => $was_scope,
                            'change_type'  => 'revoke',
                            'before_value' => 1,
                            'after_value'  => 0,
                            'actor_user_id'=> $actor,
                            'note'         => null,
                            'created_at'   => $now,
                        ] );
                    }
                }
            }
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix', 'tt_msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleReset(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_reset', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'matrix.reset' );

        $repo = new MatrixRepository();
        $repo->reseed();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_authorization_changelog", [
            'persona'      => '*',
            'entity'       => '*',
            'activity'     => '*',
            'scope_kind'   => '*',
            'change_type'  => 'reset',
            'actor_user_id'=> get_current_user_id(),
            'note'         => 'matrix reset to seed defaults',
            'created_at'   => current_time( 'mysql' ),
        ] );

        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix', 'tt_msg' => 'reset' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function renderChangelog(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_authorization_changelog";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            echo '<p><em>' . esc_html__( 'Changelog table not found. Run pending migrations.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 30" );
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No matrix changes yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'When', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actor', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Persona', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Activity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Scope', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Change', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Note', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $actor = (int) $r->actor_user_id > 0 ? get_userdata( (int) $r->actor_user_id ) : null;
                ?>
                <tr>
                    <td><?php echo esc_html( (string) $r->created_at ); ?></td>
                    <td><?php echo esc_html( $actor ? (string) $actor->display_name : '—' ); ?></td>
                    <td><?php echo esc_html( (string) $r->persona ); ?></td>
                    <td><?php echo esc_html( (string) $r->entity ); ?></td>
                    <td><?php echo esc_html( (string) $r->activity ); ?></td>
                    <td><?php echo esc_html( (string) $r->scope_kind ); ?></td>
                    <td><?php echo esc_html( (string) $r->change_type ); ?></td>
                    <td><?php echo esc_html( (string) ( $r->note ?? '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function personaLabel( string $persona ): string {
        $map = [
            'player'              => __( 'Player', 'talenttrack' ),
            'parent'              => __( 'Parent', 'talenttrack' ),
            'assistant_coach'     => __( 'Assistant Coach', 'talenttrack' ),
            'head_coach'          => __( 'Head Coach', 'talenttrack' ),
            'head_of_development' => __( 'Head of Dev', 'talenttrack' ),
            'scout'               => __( 'Scout', 'talenttrack' ),
            'team_manager'        => __( 'Team Manager', 'talenttrack' ),
            'academy_admin'       => __( 'Academy Admin', 'talenttrack' ),
        ];
        return $map[ $persona ] ?? $persona;
    }

    /**
     * #0080 Wave C2 — render a tile-consumer label as an interactive
     * chip. Click reveals a popover with the gate metadata (entity,
     * cap, cap_callback source) + a precedence one-liner. The popover
     * is rendered as a sibling element so click-outside JS can toggle
     * via a single delegated handler.
     *
     * @param array{type:string, label:string, cap:string, entity_declared:?string, cap_callback:?string, view_slug:string} $cons
     */
    private static function renderConsumerChip( array $cons, string $row_entity ): void {
        $type_label = [
            'tile'             => __( 'frontend tile', 'talenttrack' ),
            'admin_menu'       => __( 'admin menu', 'talenttrack' ),
            'admin_dashboard'  => __( 'admin dashboard tile', 'talenttrack' ),
        ][ $cons['type'] ] ?? $cons['type'];

        $cap_tuple = $cons['cap'] !== '' ? \TT\Modules\Authorization\LegacyCapMapper::tupleFor( $cons['cap'] ) : null;
        $activity  = $cap_tuple ? (string) $cap_tuple[1] : '';

        $entity_declared = $cons['entity_declared'];
        $entity_line     = $entity_declared !== null
            ? sprintf( __( '%1$s (declared on tile)', 'talenttrack' ), $entity_declared )
            : sprintf( __( '%1$s (via cap mapping)', 'talenttrack' ), $row_entity );
        ?>
        <span class="tt-tile-chip-wrap">
            <button type="button"
                    class="tt-tile-chip"
                    aria-expanded="false"
                    aria-haspopup="dialog">
                <?php echo esc_html( $cons['label'] ); ?>
            </button>
            <span class="tt-tile-popover" role="dialog" aria-hidden="true" hidden>
                <strong><?php echo esc_html( $cons['label'] ); ?></strong>
                <small class="tt-tile-popover-type">(<?php echo esc_html( $type_label ); ?>)</small>
                <dl>
                    <dt><?php esc_html_e( 'entity', 'talenttrack' ); ?></dt>
                    <dd><code><?php echo esc_html( $entity_line ); ?></code></dd>
                    <?php if ( $cons['cap'] !== '' ) : ?>
                        <dt><?php esc_html_e( 'cap', 'talenttrack' ); ?></dt>
                        <dd><code><?php echo esc_html( $cons['cap'] ); ?></code></dd>
                    <?php endif; ?>
                    <?php if ( $activity !== '' ) : ?>
                        <dt><?php esc_html_e( 'activity', 'talenttrack' ); ?></dt>
                        <dd><code><?php echo esc_html( $activity ); ?></code></dd>
                    <?php endif; ?>
                    <dt><?php esc_html_e( 'cap_callback', 'talenttrack' ); ?></dt>
                    <dd>
                        <?php if ( $cons['cap_callback'] !== null ) : ?>
                            <code><?php echo esc_html( $cons['cap_callback'] ); ?></code>
                        <?php else : ?>
                            <em>—</em>
                        <?php endif; ?>
                    </dd>
                    <?php if ( $cons['view_slug'] !== '' ) : ?>
                        <dt><?php esc_html_e( 'view slug', 'talenttrack' ); ?></dt>
                        <dd><code><?php echo esc_html( $cons['view_slug'] ); ?></code></dd>
                    <?php endif; ?>
                </dl>
                <p class="tt-tile-popover-precedence">
                    <?php esc_html_e( 'Precedence: matrix when active; cap as fallback; cap_callback as second fallback.', 'talenttrack' ); ?>
                </p>
            </span>
        </span>
        <?php
    }

    private static function shortModule( string $class ): string {
        $parts = explode( '\\', $class );
        return end( $parts );
    }

    /**
     * Group the flat entity list under category headers.
     *
     * v3.87.0 — primary grouping is now derived from the frontend
     * tile registry: an entity lives under whatever group its
     * consuming tile sits in on the persona dashboard. This keeps
     * the matrix admin and the frontend dashboard aligned in both
     * structure AND locale, since tile groups are registered with
     * `__()` and resolve to the operator's language at render time.
     *
     * Module-class fallback (the v3.0 behaviour) still kicks in for
     * entities that no tile consumes — typical for back-office-only
     * surfaces like `authorization_changelog` or `impersonation_log`.
     *
     * Group order respects whichever group label appears first when
     * walking entities, then trails with the module-fallback "Other"
     * bucket. This means the order matches the dashboard's natural
     * reading order without an extra hard-coded list to maintain.
     *
     * @param array<int, array{entity:string, module_class:string}> $entities
     * @return array<string, array<int, array{entity:string, module_class:string}>>
     */
    private static function groupEntitiesByCategory( array $entities ): array {
        $module_to_category = [
            'PlayersModule'         => __( 'Players', 'talenttrack' ),
            'PeopleModule'          => __( 'Players', 'talenttrack' ),
            'TeamsModule'           => __( 'Teams', 'talenttrack' ),
            'ActivitiesModule'      => __( 'Activities', 'talenttrack' ),
            'EvaluationsModule'     => __( 'Evaluations', 'talenttrack' ),
            'GoalsModule'           => __( 'Development', 'talenttrack' ),
            'PdpModule'             => __( 'Development', 'talenttrack' ),
            'MethodologyModule'     => __( 'Development', 'talenttrack' ),
            'TeamDevelopmentModule' => __( 'Development', 'talenttrack' ),
            'DevelopmentModule'     => __( 'Development', 'talenttrack' ),
            'ReportsModule'         => __( 'Insights', 'talenttrack' ),
            'StatsModule'           => __( 'Insights', 'talenttrack' ),
            'WorkflowModule'        => __( 'Operations', 'talenttrack' ),
            'InvitationsModule'     => __( 'Operations', 'talenttrack' ),
            'DocumentationModule'   => __( 'Operations', 'talenttrack' ),
            'OnboardingModule'      => __( 'Operations', 'talenttrack' ),
            'AuthorizationModule'   => __( 'Administration', 'talenttrack' ),
            'ConfigurationModule'   => __( 'Administration', 'talenttrack' ),
            'LicenseModule'         => __( 'Administration', 'talenttrack' ),
            'BackupModule'          => __( 'Administration', 'talenttrack' ),
            'DemoDataModule'        => __( 'Administration', 'talenttrack' ),
        ];

        $group_order = [];
        $buckets     = [];
        foreach ( $entities as $row ) {
            $entity = (string) ( $row['entity'] ?? '' );
            $cat    = MatrixEntityCatalog::groupForEntity( $entity );
            if ( $cat === null || $cat === '' ) {
                $short = self::shortModule( (string) $row['module_class'] );
                $cat   = $module_to_category[ $short ] ?? __( 'Other', 'talenttrack' );
            }
            if ( ! isset( $buckets[ $cat ] ) ) {
                $group_order[]   = $cat;
                $buckets[ $cat ] = [];
            }
            $buckets[ $cat ][] = $row;
        }
        foreach ( $buckets as &$rows ) {
            usort( $rows, static fn( $a, $b ) => strcmp( (string) $a['entity'], (string) $b['entity'] ) );
        }
        unset( $rows );

        // Honour the order in which groups first appeared. Push the
        // "Other" bucket to the end if it exists so back-office
        // entities don't shove user-facing groups around.
        $other = __( 'Other', 'talenttrack' );
        if ( isset( $buckets[ $other ] ) ) {
            $group_order = array_values( array_filter( $group_order, static fn( $g ) => $g !== $other ) );
            $group_order[] = $other;
        }

        $ordered = [];
        foreach ( $group_order as $cat ) {
            $ordered[ $cat ] = $buckets[ $cat ];
        }
        return $ordered;
    }

    private static function cellTitle( string $persona, string $entity, string $activity, bool $is_set, bool $is_default ): string {
        if ( ! $is_set ) {
            return sprintf(
                /* translators: 1: persona, 2: entity, 3: activity */
                __( '%1$s cannot %3$s on %2$s. Click to grant.', 'talenttrack' ),
                $persona, $entity, $activity
            );
        }
        if ( $is_default ) {
            return sprintf(
                /* translators: 1: persona, 2: entity, 3: activity */
                __( '%1$s can %3$s on %2$s (shipped default). Click to revoke.', 'talenttrack' ),
                $persona, $entity, $activity
            );
        }
        return sprintf(
            /* translators: 1: persona, 2: entity, 3: activity */
            __( '%1$s can %3$s on %2$s (admin-edited). Click to revoke.', 'talenttrack' ),
            $persona, $entity, $activity
        );
    }

    /* ───────────────────────────────────────────────────────────
       v3.89.0 — round-trip handlers (export + import preview/apply)
       ─────────────────────────────────────────────────────────── */

    public static function handleExport(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_export', 'tt_nonce' );

        $format = isset( $_POST['format'] ) ? sanitize_key( (string) wp_unslash( $_POST['format'] ) ) : 'xlsx';
        $stamp  = gmdate( 'Y-m-d-Hi' );

        if ( $format === 'csv' ) {
            SeedExporter::streamCsv( "talenttrack-matrix-seed-{$stamp}.csv" );
            exit;
        }
        $ok = SeedExporter::streamXlsx( "talenttrack-matrix-seed-{$stamp}.xlsx" );
        if ( ! $ok ) {
            // PhpSpreadsheet missing — fall back to CSV with a notice
            // baked into the filename so the operator notices.
            SeedExporter::streamCsv( "talenttrack-matrix-seed-{$stamp}-fallback.csv" );
        }
        exit;
    }

    public static function handleImportPreview(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_import_preview', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'matrix.import' );

        $file = $_FILES['seed_file'] ?? null;
        if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            self::stashFlash( 'import_error', __( 'No file received. Your host may have rejected the upload before PHP saw it. Try the .csv format.', 'talenttrack' ) );
            wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $tmp_path  = (string) $file['tmp_name'];
        $orig_name = (string) ( $file['name'] ?? 'upload' );

        $parsed = SeedImporter::parseUpload( $tmp_path, $orig_name );
        if ( empty( $parsed['ok'] ) ) {
            self::stashFlash( 'import_error', (string) ( $parsed['error'] ?? __( 'Could not parse the upload.', 'talenttrack' ) ) );
            wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $token = SeedImporter::stashForApply( $parsed['rows'] );
        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix', 'tt_import_token' => $token ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleImportApply(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_import_apply', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'matrix.import_apply' );

        $token = isset( $_POST['tt_import_token'] ) ? sanitize_key( (string) wp_unslash( $_POST['tt_import_token'] ) ) : '';
        $rows  = SeedImporter::fetchStash( $token );
        if ( $rows === null ) {
            self::stashFlash( 'import_error', __( 'Import token expired. Re-upload the file.', 'talenttrack' ) );
            wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $result = SeedImporter::apply( $rows );
        SeedImporter::clearStash( $token );

        if ( empty( $result['ok'] ) ) {
            self::stashFlash( 'import_error', (string) ( $result['error'] ?? __( 'Import failed.', 'talenttrack' ) ) );
            wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix', 'tt_msg' => 'imported', 'tt_n' => (int) $result['inserted'] ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Render the diff preview when the page is loaded with a valid
     * `tt_import_token` query arg, OR an error notice when an
     * import_error was stashed by a prior handler.
     */
    private static function renderImportPreviewIfPending(): void {
        $err = self::popFlash( 'import_error' );
        if ( $err !== '' ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
            return;
        }

        $token = isset( $_GET['tt_import_token'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_import_token'] ) ) : '';
        if ( $token === '' ) return;

        $rows = SeedImporter::fetchStash( $token );
        if ( $rows === null ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Import token expired. Re-upload the file.', 'talenttrack' ) . '</p></div>';
            return;
        }

        $diff = SeedImporter::computeDiff( $rows );
        ?>
        <div class="notice notice-info" style="margin-top:18px;">
            <p>
                <?php
                printf(
                    /* translators: 1: incoming row count, 2: current row count, 3: adds, 4: removes, 5: unchanged */
                    esc_html__( 'Parsed %1$d rows from upload. Current matrix has %2$d. Adds: %3$d, removes: %4$d, unchanged: %5$d.', 'talenttrack' ),
                    (int) $diff['counts']['incoming'],
                    (int) $diff['counts']['current'],
                    count( $diff['adds'] ),
                    count( $diff['removes'] ),
                    (int) $diff['unchanged']
                );
                ?>
            </p>
        </div>

        <?php if ( ! empty( $diff['adds'] ) ) : ?>
            <details open style="margin:12px 0;">
                <summary style="cursor:pointer; font-weight:700; color:#196a32;">
                    <?php
                    /* translators: %d: number of permission rows being added */
                    printf( esc_html__( 'Adds (%d)', 'talenttrack' ), count( $diff['adds'] ) );
                    ?>
                </summary>
                <?php self::renderDiffTable( $diff['adds'] ); ?>
            </details>
        <?php endif; ?>

        <?php if ( ! empty( $diff['removes'] ) ) : ?>
            <details open style="margin:12px 0;">
                <summary style="cursor:pointer; font-weight:700; color:#b32d2e;">
                    <?php
                    /* translators: %d: number of permission rows being removed */
                    printf( esc_html__( 'Removes (%d)', 'talenttrack' ), count( $diff['removes'] ) );
                    ?>
                </summary>
                <?php self::renderDiffTable( $diff['removes'] ); ?>
            </details>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('<?php echo esc_js( __( 'Replace the live matrix with the uploaded set? Current rows not present in the upload will be removed.', 'talenttrack' ) ); ?>');"
              style="margin-top:12px;">
            <?php wp_nonce_field( 'tt_matrix_import_apply', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_matrix_import_apply" />
            <input type="hidden" name="tt_import_token" value="<?php echo esc_attr( $token ); ?>" />
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply changes', 'talenttrack' ); ?></button>
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tt-matrix' ) ); ?>"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></a>
        </form>
        <?php
    }

    private static function renderDiffTable( array $rows ): void {
        ?>
        <table class="widefat striped" style="margin-top:6px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'persona', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'entity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'activity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'scope', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) ( $r['persona']    ?? '' ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $r['entity']     ?? '' ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $r['activity']   ?? '' ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $r['scope_kind'] ?? '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function stashFlash( string $key, string $message ): void {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return;
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        $cfg->set( 'tt_matrix_flash_' . $key . '_' . get_current_user_id(), $message );
    }

    private static function popFlash( string $key ): string {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return '';
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        $cfg_key = 'tt_matrix_flash_' . $key . '_' . get_current_user_id();
        $msg = (string) $cfg->get( $cfg_key, '' );
        if ( $msg !== '' ) $cfg->set( $cfg_key, '' );
        return $msg;
    }
}
