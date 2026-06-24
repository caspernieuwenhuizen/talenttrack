<?php
namespace TT\Modules\Vct\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctExercisesRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendVctLibraryView (#0095 VCT-11 / #950).
 *
 * HoD exercise library editor at ?tt_view=vct-library.
 *
 * Lists the per-club exercise catalogue with a category filter chip
 * row + inline "Add exercise" form at the top + inline edit form per
 * row (toggle on click). Archive button soft-deletes (sets
 * archived_at; engine's findCandidates filters NULL so archived rows
 * drop out without losing history).
 *
 * Save+Cancel exempt per CLAUDE.md §6 (b): inline lookup-editor
 * pattern; the list itself is the cancel target.
 *
 * Read: tt_vct_plan (coaches can browse the library).
 * Write: tt_vct_admin_library (HoD/admin only).
 */
class FrontendVctLibraryView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $can_read  = AuthorizationService::userCanOrMatrix( $user_id, 'tt_vct_plan' );
        $can_write = AuthorizationService::userCanOrMatrix( $user_id, 'tt_vct_admin_library' );

        if ( ! $can_read && ! $is_admin ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'VCT exercise library', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the VCT exercise library.', 'talenttrack' ) . '</p>';
            return;
        }

        // #1784 — shared archive-button handler (DELETE …/permanent +
        // surfaces any referential-integrity block reason).
        self::enqueueAssets();

        // Handle inline POSTs (add / edit / archive).
        if ( $can_write && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            self::handlePost();
        }

        FrontendBreadcrumbs::fromDashboard( __( 'VCT exercise library', 'talenttrack' ) );
        self::renderHeader( __( 'VCT exercise library', 'talenttrack' ) );

        $category = isset( $_GET['category'] ) ? sanitize_key( (string) $_GET['category'] ) : '';
        $include_archived = isset( $_GET['archived'] ) && $_GET['archived'] === '1';
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;

        self::renderStyles();
        self::renderFilterChips( $category, $include_archived );

        if ( $can_write ) {
            self::renderSearchInput();
            self::renderAddForm();
        }

        $rows = ( new VctExercisesRepository() )->listAll(
            $category !== '' ? $category : null,
            $include_archived
        );

        if ( ! $rows ) {
            echo '<p class="tt-empty">' . esc_html__( 'No exercises match these filters.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderTable( $rows, $can_write, $edit_id );
        self::renderSearchScript();
    }

    /**
     * #1086 VCT-11 — scoped CSS for the surface. Tokens copied from
     * `.local-mockups/vct-library/` per the visual-fidelity rule.
     */
    private static function renderStyles(): void {
        echo '<style>
        .tt-vct-lib-edge { width: 4px; min-height: 28px; border-radius: 2px; display: inline-block; vertical-align: middle; margin-right: 8px; }
        .tt-vct-lib-edge[data-band="1"] { background: #c8dcdb; }
        .tt-vct-lib-edge[data-band="2"] { background: #9bc2bd; }
        .tt-vct-lib-edge[data-band="3"] { background: #6ba39c; }
        .tt-vct-lib-edge[data-band="4"] { background: #3b8580; }
        .tt-vct-lib-edge[data-band="5"], .tt-vct-lib-edge[data-band="6"], .tt-vct-lib-edge[data-band="7"], .tt-vct-lib-edge[data-band="8"], .tt-vct-lib-edge[data-band="9"], .tt-vct-lib-edge[data-band="10"] { background: #c75c1f; }
        .tt-vct-lib-search-row { background: #fff; padding: 12px 16px; border: 1px solid #d6dadd; border-radius: 8px; margin: 0 0 12px; }
        .tt-vct-lib-search-row label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #5b6e75; margin-bottom: 4px; }
        .tt-vct-lib-search-row input { width: 100%; height: 48px; padding: 0 12px; border: 1px solid #d6dadd; border-radius: 8px; font: inherit; font-size: 16px; box-sizing: border-box; }
        .tt-vct-lib-edit-row { background: #f0f3f2; }
        .tt-vct-lib-edit-row td { padding: 16px; }
        .tt-vct-lib-edit-form { display: grid; gap: 12px; grid-template-columns: 1fr; }
        @media (min-width: 768px) { .tt-vct-lib-edit-form { grid-template-columns: 1fr 1fr; } }
        .tt-vct-lib-edit-form label { display: flex; flex-direction: column; gap: 4px; }
        .tt-vct-lib-edit-form label > span:first-child { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #5b6e75; }
        .tt-vct-lib-edit-form input, .tt-vct-lib-edit-form select { min-height: 48px; padding: 0 12px; border: 1px solid #d6dadd; border-radius: 8px; font: inherit; font-size: 16px; background: #fff; }
        .tt-vct-lib-edit-actions { grid-column: 1 / -1; display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
        .tt-vct-lib-row[hidden] { display: none; }
        </style>';
    }

    /**
     * #1086 VCT-11 — search input. JS filter runs client-side; no new
     * repo method required for substring matching against rendered text.
     */
    private static function renderSearchInput(): void {
        ?>
        <div class="tt-vct-lib-search-row">
            <label for="tt-vct-lib-search"><?php esc_html_e( 'Search by name, code or keyword', 'talenttrack' ); ?></label>
            <input type="search" id="tt-vct-lib-search" inputmode="search" autocomplete="off"
                   placeholder="<?php esc_attr_e( 'e.g. 4 vs 2, rondo, header duel…', 'talenttrack' ); ?>">
        </div>
        <?php
    }

    /**
     * #1086 VCT-11 — JS that filters rendered rows in place via the
     * `data-search` attribute carrying the row's searchable text.
     */
    private static function renderSearchScript(): void {
        ?>
        <script>
        (function(){
            var input = document.getElementById('tt-vct-lib-search');
            if (!input) return;
            var rows = document.querySelectorAll('.tt-vct-lib-row');
            input.addEventListener('input', function(){
                var q = input.value.trim().toLowerCase();
                rows.forEach(function(r){
                    if (q === '') { r.hidden = false; return; }
                    var hay = (r.getAttribute('data-search') || '').toLowerCase();
                    r.hidden = hay.indexOf(q) === -1;
                });
            });
        })();
        </script>
        <?php
    }

    private static function renderFilterChips( string $current, bool $include_archived ): void {
        $categories = QueryHelpers::get_lookup_names( 'vct_exercise_category' );
        echo '<div class="tt-vct-library-filters" style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 16px;">';
        // All-categories chip.
        $base_url = remove_query_arg( 'category' );
        $all_active = $current === '';
        echo '<a class="tt-pill" style="display:inline-block;padding:6px 12px;border-radius:999px;background:'
            . ( $all_active ? '#0b3d2e;color:#fff' : '#eee;color:#333' )
            . ';text-decoration:none;font-size:13px;" href="' . esc_url( $base_url ) . '">'
            . esc_html__( 'All categories', 'talenttrack' )
            . '</a>';
        foreach ( $categories as $cat ) {
            $active = $current === $cat;
            $href = add_query_arg( [ 'category' => $cat ] );
            $label = LookupTranslator::byTypeAndName( 'vct_exercise_category', (string) $cat );
            echo '<a class="tt-pill" style="display:inline-block;padding:6px 12px;border-radius:999px;background:'
                . ( $active ? '#0b3d2e;color:#fff' : '#eee;color:#333' )
                . ';text-decoration:none;font-size:13px;" href="' . esc_url( $href ) . '">'
                . esc_html( $label )
                . '</a>';
        }

        // Archived toggle.
        $archived_href = add_query_arg( [ 'archived' => $include_archived ? '0' : '1' ] );
        echo '<a class="tt-pill" style="display:inline-block;padding:6px 12px;border-radius:999px;background:'
            . ( $include_archived ? '#dba617;color:#fff' : '#fff;color:#666;border:1px solid #ccc' )
            . ';text-decoration:none;font-size:13px;margin-left:auto;" href="' . esc_url( $archived_href ) . '">'
            . esc_html( $include_archived ? __( 'Hiding archived', 'talenttrack' ) : __( 'Show archived', 'talenttrack' ) )
            . '</a>';
        echo '</div>';
    }

    private static function renderAddForm(): void {
        $categories = QueryHelpers::get_lookup_names( 'vct_exercise_category' );
        $themes     = QueryHelpers::get_lookup_names( 'vct_tactical_theme' );

        echo '<details style="margin:0 0 16px;padding:12px;background:#f5f5f5;border-radius:8px;">';
        echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html__( '+ Add exercise', 'talenttrack' ) . '</summary>';
        echo '<form method="POST" action="" style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
        wp_nonce_field( 'tt_vct_library_add', '_tt_vct_lib_add_nonce' );
        echo '<input type="hidden" name="_tt_action" value="add">';
        echo '<label><span>' . esc_html__( 'Code (unique slug)', 'talenttrack' ) . '</span><input type="text" name="code" required pattern="[a-z0-9_]+" style="width:100%"></label>';
        echo '<label><span>' . esc_html__( 'Name', 'talenttrack' ) . '</span><input type="text" name="name_canonical" required style="width:100%"></label>';
        echo '<label><span>' . esc_html__( 'Category', 'talenttrack' ) . '</span><select name="category" required>';
        foreach ( $categories as $c ) echo '<option value="' . esc_attr( (string) $c ) . '">' . esc_html( LookupTranslator::byTypeAndName( 'vct_exercise_category', (string) $c ) ) . '</option>';
        echo '</select></label>';
        echo '<label><span>' . esc_html__( 'Theme (optional)', 'talenttrack' ) . '</span><select name="tactical_theme"><option value="">— —</option>';
        foreach ( $themes as $t ) echo '<option value="' . esc_attr( (string) $t ) . '">' . esc_html( LookupTranslator::byTypeAndName( 'vct_tactical_theme', (string) $t ) ) . '</option>';
        echo '</select></label>';
        echo '<label><span>' . esc_html__( 'Intensity band (1-10)', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="intensity_band" min="1" max="10" value="3" required></label>';
        echo '<label><span>' . esc_html__( 'Age min', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="age_min" min="6" max="19" value="9" required></label>';
        echo '<label><span>' . esc_html__( 'Age max', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="age_max" min="6" max="19" value="14" required></label>';
        echo '<label><span>' . esc_html__( 'Duration min (minutes)', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="duration_minutes_min" min="1" max="120" value="10" required></label>';
        echo '<label><span>' . esc_html__( 'Duration max (minutes)', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="duration_minutes_max" min="1" max="120" value="20" required></label>';
        echo '<label><span>' . esc_html__( 'Players min', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="players_min" min="1" max="30" value="4" required></label>';
        echo '<label><span>' . esc_html__( 'Players max', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="players_max" min="1" max="30" value="20" required></label>';

        echo '<fieldset style="grid-column:1 / -1;margin:8px 0 0;padding:8px;border:1px solid #ddd;">';
        echo '<legend>' . esc_html__( 'MD contexts (tick all that apply)', 'talenttrack' ) . '</legend>';
        // MD-4 … MD … MD+2 / NONE are intentional technical tokens used
        // industry-wide for match-day periodisation. They are NOT
        // translated — a Dutch coach reads "MD-2" exactly as an English
        // one does. (#1518: deliberate i18n exemption, not a leak.)
        $md_map = [
            'md_minus_4' => 'MD-4',
            'md_minus_3' => 'MD-3',
            'md_minus_2' => 'MD-2',
            'md_minus_1' => 'MD-1',
            'md_zero'    => 'MD',
            'md_plus_1'  => 'MD+1',
            'md_plus_2'  => 'MD+2',
            'md_none'    => 'NONE',
        ];
        foreach ( $md_map as $col => $label ) {
            $checked = $col === 'md_none' ? 'checked' : '';
            echo '<label style="display:inline-block;margin-right:12px;font-weight:normal;"><input type="checkbox" name="' . esc_attr( $col ) . '" value="1" ' . $checked . '> ' . esc_html( $label ) . '</label>';
        }
        echo '</fieldset>';

        echo '<button type="submit" class="tt-btn tt-btn-primary" style="grid-column:1 / -1;margin-top:8px;">' . esc_html__( 'Add exercise', 'talenttrack' ) . '</button>';
        echo '</form>';
        echo '</details>';
    }

    /** @param list<array<string,mixed>> $rows */
    private static function renderTable( array $rows, bool $can_write, int $edit_id = 0 ): void {
        $base_url = remove_query_arg( 'edit' );
        echo '<div class="tt-table-wrap"><table class="tt-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Name',     'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Category', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Theme',    'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Band',     'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Age',      'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status',   'talenttrack' ) . '</th>';
        if ( $can_write ) echo '<th></th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $is_archived = isset( $row['archived_at'] ) || self::isArchivedById( (int) $row['id'] );
            $cat_label   = LookupTranslator::byTypeAndName( 'vct_exercise_category', (string) $row['category'] );
            $theme_label = $row['tactical_theme'] !== null
                ? LookupTranslator::byTypeAndName( 'vct_tactical_theme', (string) $row['tactical_theme'] )
                : '';
            $row_id      = (int) $row['id'];
            $band        = (int) $row['intensity_band'];
            $search_hay  = trim( implode( ' ', [
                (string) $row['name_canonical'],
                (string) $row['code'],
                $cat_label,
                $theme_label,
            ] ) );

            echo '<tr class="tt-vct-lib-row" data-search="' . esc_attr( $search_hay ) . '">';
            echo '<td>';
            echo '<span class="tt-vct-lib-edge" data-band="' . esc_attr( (string) $band ) . '" title="' . esc_attr( sprintf( /* translators: %d = intensity band 1-10 */ __( 'Intensity band %d', 'talenttrack' ), $band ) ) . '"></span>';
            echo esc_html( (string) $row['name_canonical'] );
            echo '<br><code style="font-size:11px;color:#888;">' . esc_html( (string) $row['code'] ) . '</code></td>';
            echo '<td>' . esc_html( $cat_label ) . '</td>';
            echo '<td>' . esc_html( $theme_label !== '' ? $theme_label : '—' ) . '</td>';
            echo '<td>' . esc_html( (string) $band ) . '</td>';
            echo '<td>' . esc_html( (string) $row['age_min'] ) . '-' . esc_html( (string) $row['age_max'] ) . '</td>';
            echo '<td>' . esc_html( $is_archived ? __( 'Archived', 'talenttrack' ) : __( 'Active', 'talenttrack' ) ) . '</td>';

            if ( $can_write ) {
                echo '<td style="white-space:nowrap;">';
                if ( ! $is_archived ) {
                    // #1086 VCT-11 — inline-edit affordance. Clicking
                    // toggles ?edit=N on the URL; the edit form for the
                    // active id renders below the row.
                    $edit_url = $edit_id === $row_id
                        ? remove_query_arg( 'edit' )
                        : add_query_arg( [ 'edit' => $row_id ], $base_url ) . '#tt-vct-lib-edit-' . $row_id;
                    echo '<a class="tt-btn tt-btn-secondary" style="font-size:12px;padding:4px 10px;margin-right:6px;" href="' . esc_url( $edit_url ) . '">'
                        . ( $edit_id === $row_id ? esc_html__( 'Close', 'talenttrack' ) : esc_html__( 'Edit', 'talenttrack' ) )
                        . '</a>';

                    echo '<form method="POST" action="" style="display:inline;">';
                    wp_nonce_field( 'tt_vct_library_archive_' . $row_id, '_tt_vct_lib_archive_nonce' );
                    echo '<input type="hidden" name="_tt_action" value="archive">';
                    echo '<input type="hidden" name="exercise_id" value="' . esc_attr( (string) $row_id ) . '">';
                    echo '<button type="submit" class="tt-btn tt-btn-secondary" style="font-size:12px;padding:4px 10px;" onclick="return confirm(\'' . esc_attr__( 'Archive this exercise? It stays in history but drops out of new session candidates.', 'talenttrack' ) . '\');">'
                        . esc_html__( 'Archive', 'talenttrack' ) . '</button>';
                    echo '</form>';
                } else {
                    // #1784 — archived rows get the irreversible delete
                    // ($can_write already gated on tt_vct_admin_library).
                    echo '<button type="button" class="tt-btn tt-btn-danger" style="font-size:12px;padding:4px 10px;"'
                        . ' data-tt-archive-rest-path="' . esc_attr( 'vct/exercises/' . $row_id . '/permanent' ) . '"'
                        . ' data-tt-archive-confirm="' . esc_attr__( 'Permanently delete this exercise? This cannot be undone.', 'talenttrack' ) . '"'
                        . ' data-tt-archive-redirect="' . esc_attr( add_query_arg( [ 'archived' => '1' ] ) ) . '">'
                        . esc_html__( 'Delete permanently', 'talenttrack' ) . '</button>';
                }
                echo '</td>';
            }
            echo '</tr>';

            // #1086 VCT-11 — inline edit form, rendered as a full-width
            // row underneath the matching exercise. Visible only when
            // ?edit=<row_id> matches.
            if ( $can_write && $edit_id === $row_id && ! $is_archived ) {
                self::renderEditRow( $row, $base_url );
            }
        }

        echo '</tbody></table></div>';
    }

    /**
     * #1086 VCT-11 — inline edit form. Mirrors the add-form field set;
     * POSTs `_tt_action=update` back through `handlePost()`.
     *
     * @param array<string,mixed> $row
     */
    private static function renderEditRow( array $row, string $cancel_url ): void {
        $row_id     = (int) $row['id'];
        $categories = QueryHelpers::get_lookup_names( 'vct_exercise_category' );
        $themes     = QueryHelpers::get_lookup_names( 'vct_tactical_theme' );
        $current_cat   = (string) $row['category'];
        $current_theme = $row['tactical_theme'] !== null ? (string) $row['tactical_theme'] : '';
        ?>
        <tr class="tt-vct-lib-edit-row" id="tt-vct-lib-edit-<?php echo (int) $row_id; ?>">
            <td colspan="7">
                <form method="POST" action="" class="tt-vct-lib-edit-form">
                    <?php wp_nonce_field( 'tt_vct_library_update_' . $row_id, '_tt_vct_lib_update_nonce' ); ?>
                    <input type="hidden" name="_tt_action" value="update">
                    <input type="hidden" name="exercise_id" value="<?php echo (int) $row_id; ?>">

                    <label>
                        <span><?php esc_html_e( 'Name', 'talenttrack' ); ?></span>
                        <input type="text" name="name_canonical" required value="<?php echo esc_attr( (string) $row['name_canonical'] ); ?>">
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Code (unique slug)', 'talenttrack' ); ?></span>
                        <input type="text" name="code" required pattern="[a-z0-9_]+" value="<?php echo esc_attr( (string) $row['code'] ); ?>">
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Category', 'talenttrack' ); ?></span>
                        <select name="category" required>
                            <?php foreach ( $categories as $c ) :
                                $c = (string) $c; ?>
                                <option value="<?php echo esc_attr( $c ); ?>"<?php selected( $current_cat, $c ); ?>>
                                    <?php echo esc_html( LookupTranslator::byTypeAndName( 'vct_exercise_category', $c ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Theme (optional)', 'talenttrack' ); ?></span>
                        <select name="tactical_theme">
                            <option value="">— —</option>
                            <?php foreach ( $themes as $t ) :
                                $t = (string) $t; ?>
                                <option value="<?php echo esc_attr( $t ); ?>"<?php selected( $current_theme, $t ); ?>>
                                    <?php echo esc_html( LookupTranslator::byTypeAndName( 'vct_tactical_theme', $t ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Intensity band (1-10)', 'talenttrack' ); ?></span>
                        <input type="number" inputmode="numeric" name="intensity_band" min="1" max="10" value="<?php echo (int) $row['intensity_band']; ?>" required>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Age range (min–max)', 'talenttrack' ); ?></span>
                        <span style="display:flex;gap:6px;">
                            <input type="number" inputmode="numeric" name="age_min" min="6" max="19" value="<?php echo (int) $row['age_min']; ?>" required style="flex:1;">
                            <input type="number" inputmode="numeric" name="age_max" min="6" max="19" value="<?php echo (int) $row['age_max']; ?>" required style="flex:1;">
                        </span>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Duration (min–max minutes)', 'talenttrack' ); ?></span>
                        <span style="display:flex;gap:6px;">
                            <input type="number" inputmode="numeric" name="duration_minutes_min" min="1" max="120" value="<?php echo (int) $row['duration_minutes_min']; ?>" required style="flex:1;">
                            <input type="number" inputmode="numeric" name="duration_minutes_max" min="1" max="120" value="<?php echo (int) $row['duration_minutes_max']; ?>" required style="flex:1;">
                        </span>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Group size (min–max players)', 'talenttrack' ); ?></span>
                        <span style="display:flex;gap:6px;">
                            <input type="number" inputmode="numeric" name="players_min" min="1" max="30" value="<?php echo (int) $row['players_min']; ?>" required style="flex:1;">
                            <input type="number" inputmode="numeric" name="players_max" min="1" max="30" value="<?php echo (int) $row['players_max']; ?>" required style="flex:1;">
                        </span>
                    </label>

                    <div class="tt-vct-lib-edit-actions">
                        <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></a>
                        <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save exercise', 'talenttrack' ); ?></button>
                    </div>
                </form>
            </td>
        </tr>
        <?php
    }

    /**
     * Direct SQL probe (the repo's listAll already filters by archived
     * when not include_archived, but we still want a reliable per-row
     * flag for the "Show archived" view).
     */
    private static function isArchivedById( int $id ): bool {
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT archived_at FROM {$wpdb->prefix}tt_vct_exercises WHERE id = %d LIMIT 1",
            $id
        ) );
        return $row !== null;
    }

    private static function handlePost(): void {
        $action = isset( $_POST['_tt_action'] ) ? sanitize_key( (string) $_POST['_tt_action'] ) : '';

        if ( $action === 'add' ) {
            if ( ! wp_verify_nonce( (string) ( $_POST['_tt_vct_lib_add_nonce'] ?? '' ), 'tt_vct_library_add' ) ) {
                self::notice( 'error', __( 'Add failed: session expired. Please reload and try again.', 'talenttrack' ) );
                return;
            }
            $payload = [
                'code'                 => sanitize_key( (string) ( $_POST['code']           ?? '' ) ),
                'name_canonical'       => sanitize_text_field( (string) ( $_POST['name_canonical'] ?? '' ) ),
                'category'             => sanitize_key( (string) ( $_POST['category']       ?? '' ) ),
                'tactical_theme'       => sanitize_key( (string) ( $_POST['tactical_theme'] ?? '' ) ) ?: null,
                'intensity_band'       => (int) ( $_POST['intensity_band']       ?? 3 ),
                'age_min'              => (int) ( $_POST['age_min']              ?? 9 ),
                'age_max'              => (int) ( $_POST['age_max']              ?? 14 ),
                'duration_minutes_min' => (int) ( $_POST['duration_minutes_min'] ?? 10 ),
                'duration_minutes_max' => (int) ( $_POST['duration_minutes_max'] ?? 20 ),
                'players_min'          => (int) ( $_POST['players_min']          ?? 4 ),
                'players_max'          => (int) ( $_POST['players_max']          ?? 20 ),
                'equipment_json'       => [],
            ];
            foreach ( [ 'md_minus_4', 'md_minus_3', 'md_minus_2', 'md_minus_1', 'md_zero', 'md_plus_1', 'md_plus_2', 'md_none' ] as $col ) {
                $payload[ $col ] = ! empty( $_POST[ $col ] ) ? 1 : 0;
            }
            if ( $payload['code'] === '' || $payload['name_canonical'] === '' || $payload['category'] === '' ) {
                self::notice( 'error', __( 'Add failed: code, name, and category are required.', 'talenttrack' ) );
                return;
            }
            $id = ( new VctExercisesRepository() )->create( $payload );
            if ( $id <= 0 ) {
                self::notice( 'error', __( 'Add failed: database error. The code may already exist.', 'talenttrack' ) );
                return;
            }
            self::notice( 'success', __( 'Exercise added.', 'talenttrack' ) );
            return;
        }

        if ( $action === 'update' ) {
            $id = isset( $_POST['exercise_id'] ) ? absint( $_POST['exercise_id'] ) : 0;
            if ( ! wp_verify_nonce( (string) ( $_POST['_tt_vct_lib_update_nonce'] ?? '' ), 'tt_vct_library_update_' . $id ) ) {
                self::notice( 'error', __( 'Save failed: session expired. Please reload and try again.', 'talenttrack' ) );
                return;
            }
            $patch = [
                'code'                 => sanitize_key( (string) ( $_POST['code']           ?? '' ) ),
                'name_canonical'       => sanitize_text_field( (string) ( $_POST['name_canonical'] ?? '' ) ),
                'category'             => sanitize_key( (string) ( $_POST['category']       ?? '' ) ),
                'tactical_theme'       => sanitize_key( (string) ( $_POST['tactical_theme'] ?? '' ) ) ?: null,
                'intensity_band'       => max( 1, min( 10, (int) ( $_POST['intensity_band']       ?? 3 ) ) ),
                'age_min'              => max( 6, min( 19, (int) ( $_POST['age_min']              ?? 9 ) ) ),
                'age_max'              => max( 6, min( 19, (int) ( $_POST['age_max']              ?? 14 ) ) ),
                'duration_minutes_min' => max( 1, min( 120, (int) ( $_POST['duration_minutes_min'] ?? 10 ) ) ),
                'duration_minutes_max' => max( 1, min( 120, (int) ( $_POST['duration_minutes_max'] ?? 20 ) ) ),
                'players_min'          => max( 1, min( 30,  (int) ( $_POST['players_min']          ?? 4 ) ) ),
                'players_max'          => max( 1, min( 30,  (int) ( $_POST['players_max']          ?? 20 ) ) ),
            ];
            if ( $id <= 0 || $patch['code'] === '' || $patch['name_canonical'] === '' || $patch['category'] === '' ) {
                self::notice( 'error', __( 'Save failed: code, name, and category are required.', 'talenttrack' ) );
                return;
            }
            $ok = ( new VctExercisesRepository() )->update( $id, $patch );
            self::notice(
                $ok ? 'success' : 'error',
                $ok ? __( 'Exercise updated.', 'talenttrack' ) : __( 'Save failed: database error.', 'talenttrack' )
            );
            return;
        }

        if ( $action === 'archive' ) {
            $id = isset( $_POST['exercise_id'] ) ? absint( $_POST['exercise_id'] ) : 0;
            if ( ! wp_verify_nonce( (string) ( $_POST['_tt_vct_lib_archive_nonce'] ?? '' ), 'tt_vct_library_archive_' . $id ) ) {
                self::notice( 'error', __( 'Archive failed: session expired. Please reload and try again.', 'talenttrack' ) );
                return;
            }
            $ok = ( new VctExercisesRepository() )->archive( $id );
            self::notice(
                $ok ? 'success' : 'error',
                $ok ? __( 'Exercise archived.', 'talenttrack' ) : __( 'Archive failed.', 'talenttrack' )
            );
        }
    }

    private static function notice( string $variant, string $msg ): void {
        $bg = $variant === 'error' ? '#fdecea' : ( $variant === 'success' ? '#e9f5e9' : '#fff8e1' );
        $bar = $variant === 'error' ? '#b32d2e' : ( $variant === 'success' ? '#2c8a2c' : '#dba617' );
        echo '<div class="tt-notice tt-notice--' . esc_attr( $variant ) . '" style="margin:8px 0 16px;padding:12px;background:' . esc_attr( $bg ) . ';border-left:4px solid ' . esc_attr( $bar ) . ';">'
            . esc_html( $msg ) . '</div>';
    }
}
