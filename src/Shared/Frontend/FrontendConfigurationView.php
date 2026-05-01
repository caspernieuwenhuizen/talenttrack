<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\BrandFonts;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendConfigurationView — frontend mirror of the wp-admin
 * Configuration page.
 *
 * Layout follows the wp-admin Configuration tile grid: a landing page
 * with one sub-tile per configuration area. Branding, Theme & fonts,
 * and Rating scale render frontend forms inline (?config_sub=…); the
 * remaining areas (lookups, evaluation types, feature toggles,
 * backups, translations, audit log) link out to the existing wp-admin
 * tabs because they're heavier admin work that doesn't yet have a
 * dedicated frontend port.
 *
 * Saving the inline forms still goes through
 * `POST /wp-json/talenttrack/v1/config`.
 */
class FrontendConfigurationView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $sub = isset( $_GET['config_sub'] ) ? sanitize_key( (string) $_GET['config_sub'] ) : '';

        switch ( $sub ) {
            case 'branding':
                self::renderHeader( __( 'Branding', 'talenttrack' ) );
                self::renderSubBackLink();
                wp_enqueue_media();
                self::renderBrandingForm();
                return;
            case 'theme':
                self::renderHeader( __( 'Theme & fonts', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderThemeForm();
                return;
            case 'rating':
                self::renderHeader( __( 'Rating scale', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderRatingForm();
                return;
            case 'menus':
                self::renderHeader( __( 'wp-admin menus', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderMenusForm();
                return;
            case 'dashboard':
                self::renderHeader( __( 'Default dashboard', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderDashboardForm();
                return;
            case 'lookups':
                // v3.74.3 — #5: per-category frontend editor. When
                // `category` is on the URL, render the dedicated CRUD
                // surface for that lookup type; otherwise render the
                // index that picks one. Editing no longer jumps to
                // wp-admin.
                $category_slug = isset( $_GET['category'] ) ? sanitize_key( (string) $_GET['category'] ) : '';
                if ( $category_slug !== '' ) {
                    $meta = self::lookupCategoryMeta( $category_slug );
                    if ( $meta !== null ) {
                        self::renderHeader( $meta['label'] );
                        self::renderLookupsBackLink();
                        self::renderLookupCategoryEditor( $meta );
                        return;
                    }
                }
                self::renderHeader( __( 'Lookups', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderLookupsIndex();
                return;
        }

        self::renderHeader( __( 'Configuration', 'talenttrack' ) );
        self::renderTileGrid();
    }

    /**
     * Sub-tile index that mirrors every individual `tt_lookups`
     * category visible in the wp-admin Configuration → Lookups tabs.
     * Each card opens the matching wp-admin tab for inline editing.
     *
     * Closing the parity gap from #0061: previously the frontend
     * collapsed all 10 lookup tabs to a single "Lookups" tile, which
     * obscured what's actually configurable.
     */
    private static function renderLookupsIndex(): void {
        self::tileGridStyles();
        // v3.74.3 — #5: each card now points at the dedicated frontend
        // editor (`?config_sub=lookups&category=<slug>`) instead of
        // jumping to wp-admin. The "Rating scale" card stays special-
        // cased because rating-scale lives in tt_config (min/max/step),
        // not tt_lookups.
        $base = remove_query_arg( [ 'config_sub', 'category', 'edit' ] );
        $rating_url = add_query_arg( [ 'config_sub' => 'rating' ], $base );

        $cards = [
            [ __( 'Evaluation types',   'talenttrack' ), __( 'The evaluation templates rosters can attach to a player record.', 'talenttrack' ), 'eval_types',     'evaluations' ],
            [ __( 'Activity types',     'talenttrack' ), __( 'Training, game, tournament, meeting — colour-coded type pills.',   'talenttrack' ), 'activity_types', 'activities' ],
            [ __( 'Game subtypes',      'talenttrack' ), __( 'Friendly, league, cup, tournament. Filters game-only reports.',     'talenttrack' ), 'game_subtypes',  'sessions' ],
            [ __( 'Positions',          'talenttrack' ), __( 'Football positions players can be tagged with.',                    'talenttrack' ), 'positions',       'compare' ],
            [ __( 'Preferred foot',     'talenttrack' ), __( 'Left, right, both — used on the player edit form.',                  'talenttrack' ), 'foot_options',    'players' ],
            [ __( 'Age groups',         'talenttrack' ), __( 'U7, U8, … U23 — feed the team age-group dropdown and weights.',     'talenttrack' ), 'age_groups',      'teams' ],
            [ __( 'Goal statuses',      'talenttrack' ), __( 'Open / in progress / done / cancelled. Drives the goals KPI.',     'talenttrack' ), 'goal_statuses',   'goals' ],
            [ __( 'Goal priorities',    'talenttrack' ), __( 'Low / medium / high. Sorts the my-goals list.',                     'talenttrack' ), 'goal_priorities', 'goals' ],
            [ __( 'Attendance statuses', 'talenttrack' ), __( 'Present / absent / excused / late. Drives the attendance KPI.',  'talenttrack' ), 'att_statuses',    'inbox' ],
            [ __( 'Rating scale',       'talenttrack' ), __( 'Min, max and step for evaluation ratings.',                         'talenttrack' ), '__rating',        'weights' ],
        ];

        echo '<p style="margin-bottom:var(--tt-sp-4); color:var(--tt-muted);">';
        esc_html_e( 'Pick a lookup category. Values are translatable and feed every dropdown across the dashboard.', 'talenttrack' );
        echo '</p>';

        echo '<div class="tt-cfg-tile-grid">';
        foreach ( $cards as $row ) {
            list( $title, $desc, $slug, $icon ) = $row;
            if ( $slug === '__rating' ) {
                $url = $rating_url;
            } else {
                $url = add_query_arg( [ 'config_sub' => 'lookups', 'category' => $slug ], $base );
            }
            echo '<a class="tt-cfg-tile" href="' . esc_url( $url ) . '">';
            echo '<div class="tt-cfg-tile-icon">' . \TT\Shared\Icons\IconRenderer::render( $icon ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<div class="tt-cfg-tile-title">' . esc_html( $title ) . '</div>';
            echo '<div class="tt-cfg-tile-desc">' . esc_html( $desc ) . '</div>';
            echo '</a>';
        }
        echo '</div>';
    }

    /**
     * v3.74.3 — registry of lookup categories editable on the frontend.
     * Each entry maps the URL slug (used in `?category=`) to the
     * underlying `tt_lookups.lookup_type` plus presentation flags.
     *
     * @return array{label:string,type:string,show_desc:bool,show_color:bool,slug:string}|null
     */
    private static function lookupCategoryMeta( string $slug ): ?array {
        $registry = [
            'eval_types'      => [ 'label' => __( 'Evaluation types',    'talenttrack' ), 'type' => 'eval_type',         'show_desc' => true,  'show_color' => false ],
            'activity_types'  => [ 'label' => __( 'Activity types',      'talenttrack' ), 'type' => 'activity_type',     'show_desc' => true,  'show_color' => true  ],
            'game_subtypes'   => [ 'label' => __( 'Game subtypes',       'talenttrack' ), 'type' => 'game_subtype',      'show_desc' => false, 'show_color' => false ],
            'positions'       => [ 'label' => __( 'Positions',           'talenttrack' ), 'type' => 'position',          'show_desc' => false, 'show_color' => false ],
            'foot_options'    => [ 'label' => __( 'Preferred foot',      'talenttrack' ), 'type' => 'foot_option',       'show_desc' => false, 'show_color' => false ],
            'age_groups'      => [ 'label' => __( 'Age groups',          'talenttrack' ), 'type' => 'age_group',         'show_desc' => false, 'show_color' => false ],
            'goal_statuses'   => [ 'label' => __( 'Goal statuses',       'talenttrack' ), 'type' => 'goal_status',       'show_desc' => false, 'show_color' => true  ],
            'goal_priorities' => [ 'label' => __( 'Goal priorities',     'talenttrack' ), 'type' => 'goal_priority',     'show_desc' => false, 'show_color' => false ],
            'att_statuses'    => [ 'label' => __( 'Attendance statuses', 'talenttrack' ), 'type' => 'attendance_status', 'show_desc' => false, 'show_color' => true  ],
        ];
        if ( ! isset( $registry[ $slug ] ) ) return null;
        $meta = $registry[ $slug ];
        $meta['slug'] = $slug;
        return $meta;
    }

    private static function renderLookupsBackLink(): void {
        $base = add_query_arg( [ 'config_sub' => 'lookups' ], remove_query_arg( [ 'category', 'edit' ] ) );
        echo '<p style="margin:0 0 var(--tt-sp-3);"><a class="tt-link" href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All lookups', 'talenttrack' ) . '</a></p>';
    }

    /**
     * v3.74.3 — frontend CRUD editor for one lookup category.
     * Lists current rows; offers an inline Add form; opens an Edit
     * form when `?edit=<id>` is on the URL. Save / delete go through
     * the existing `LookupsRestController` endpoints, so authorisation
     * + tenancy + audit logging stay centralised.
     *
     * Drag-reorder is intentionally deferred to v2 — sort_order is
     * editable as a numeric field on the Edit form.
     *
     * @param array{label:string,type:string,show_desc:bool,show_color:bool,slug:string} $meta
     */
    private static function renderLookupCategoryEditor( array $meta ): void {
        $type     = $meta['type'];
        $items    = QueryHelpers::get_lookups( $type );
        $edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $editing  = null;
        if ( $edit_id > 0 ) {
            foreach ( $items as $row ) {
                if ( (int) $row->id === $edit_id ) { $editing = $row; break; }
            }
        }

        $base = remove_query_arg( [ 'edit' ] );
        ?>
        <div class="tt-panel" data-tt-lookups-editor data-lookup-type="<?php echo esc_attr( $type ); ?>" data-show-desc="<?php echo $meta['show_desc'] ? '1' : '0'; ?>" data-show-color="<?php echo $meta['show_color'] ? '1' : '0'; ?>">
            <table class="tt-table" style="width:100%; margin-bottom: var(--tt-sp-4);">
                <thead><tr>
                    <th style="width:60px;"><?php esc_html_e( 'Order', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                    <?php if ( $meta['show_desc'] ) : ?><th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th><?php endif; ?>
                    <?php if ( $meta['show_color'] ) : ?><th style="width:80px;"><?php esc_html_e( 'Colour', 'talenttrack' ); ?></th><?php endif; ?>
                    <th style="width:160px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="<?php echo 3 + ( $meta['show_desc'] ? 1 : 0 ) + ( $meta['show_color'] ? 1 : 0 ); ?>"><em><?php esc_html_e( 'No items yet.', 'talenttrack' ); ?></em></td></tr>
                    <?php else : foreach ( $items as $row ) :
                        $row_meta_arr = QueryHelpers::lookup_meta( $row );
                        $row_color    = is_string( $row_meta_arr['color'] ?? null ) ? (string) $row_meta_arr['color'] : '';
                        $is_locked    = ! empty( $row_meta_arr['is_locked'] );
                        ?>
                        <tr>
                            <td><?php echo (int) $row->sort_order; ?></td>
                            <td>
                                <strong><?php echo esc_html( \TT\Infrastructure\Query\LookupTranslator::name( $row ) ); ?></strong>
                                <?php if ( $is_locked ) : ?>
                                    <span title="<?php esc_attr_e( 'Locked — workflow rules depend on this row.', 'talenttrack' ); ?>" style="margin-left:6px; color:#888; font-size:11px;">🔒</span>
                                <?php endif; ?>
                            </td>
                            <?php if ( $meta['show_desc'] ) : ?><td><?php echo esc_html( (string) ( $row->description ?? '' ) ); ?></td><?php endif; ?>
                            <?php if ( $meta['show_color'] ) : ?>
                                <td><?php if ( $row_color !== '' ) : ?><span style="display:inline-block; width:18px; height:18px; border-radius:3px; background:<?php echo esc_attr( $row_color ); ?>; vertical-align:middle;"></span><?php endif; ?></td>
                            <?php endif; ?>
                            <td>
                                <a class="tt-btn tt-btn-secondary tt-btn-small" href="<?php echo esc_url( add_query_arg( [ 'edit' => (int) $row->id ], $base ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                                <?php if ( ! $is_locked ) : ?>
                                    <button type="button" class="tt-btn tt-btn-secondary tt-btn-small" data-tt-lookup-delete="<?php echo (int) $row->id; ?>" data-tt-lookup-name="<?php echo esc_attr( (string) $row->name ); ?>" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h3 style="margin-top:var(--tt-sp-4);">
                <?php echo $editing ? esc_html__( 'Edit row', 'talenttrack' ) : esc_html__( 'Add new row', 'talenttrack' ); ?>
            </h3>
            <form data-tt-lookup-form>
                <input type="hidden" name="id" value="<?php echo (int) ( $editing->id ?? 0 ); ?>" />
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label tt-field-required" for="tt-lkp-name"><?php esc_html_e( 'Name', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-lkp-name" class="tt-input" name="name" required value="<?php echo esc_attr( (string) ( $editing->name ?? '' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-lkp-sort"><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label>
                        <input type="number" id="tt-lkp-sort" class="tt-input" name="sort_order" inputmode="numeric" min="0" step="1" value="<?php echo esc_attr( (string) ( $editing->sort_order ?? 0 ) ); ?>" />
                    </div>
                    <?php if ( $meta['show_desc'] ) : ?>
                        <div class="tt-field" style="grid-column:1 / -1;">
                            <label class="tt-field-label" for="tt-lkp-desc"><?php esc_html_e( 'Description', 'talenttrack' ); ?></label>
                            <input type="text" id="tt-lkp-desc" class="tt-input" name="description" value="<?php echo esc_attr( (string) ( $editing->description ?? '' ) ); ?>" />
                        </div>
                    <?php endif; ?>
                    <?php if ( $meta['show_color'] ) :
                        $existing_meta = $editing ? QueryHelpers::lookup_meta( $editing ) : [];
                        $existing_color = is_string( $existing_meta['color'] ?? null ) ? (string) $existing_meta['color'] : '#5b6e75';
                        ?>
                        <div class="tt-field">
                            <label class="tt-field-label" for="tt-lkp-color"><?php esc_html_e( 'Pill colour', 'talenttrack' ); ?></label>
                            <input type="color" id="tt-lkp-color" name="meta[color]" value="<?php echo esc_attr( $existing_color ); ?>" />
                        </div>
                    <?php endif; ?>
                </div>
                <p style="margin-top:var(--tt-sp-3);">
                    <button type="submit" class="tt-btn tt-btn-primary"><?php echo $editing ? esc_html__( 'Save changes', 'talenttrack' ) : esc_html__( 'Add row', 'talenttrack' ); ?></button>
                    <?php if ( $editing ) : ?>
                        <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $base ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></a>
                    <?php endif; ?>
                </p>
                <div class="tt-form-msg" data-tt-lookup-msg></div>
            </form>
        </div>

        <script>
        (function(){
            'use strict';
            var root = document.querySelector('[data-tt-lookups-editor]');
            if (!root) return;

            var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';
            var rest  = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
            var type  = root.getAttribute('data-lookup-type');

            // Save (create / update)
            var form = root.querySelector('[data-tt-lookup-form]');
            if (form) {
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    var msg = root.querySelector('[data-tt-lookup-msg]');
                    var fd  = new FormData(form);
                    var id  = parseInt(fd.get('id') || '0', 10);
                    var body = {
                        name:       String(fd.get('name') || '').trim(),
                        sort_order: parseInt(fd.get('sort_order') || '0', 10),
                    };
                    if (fd.get('description') !== null) body.description = String(fd.get('description') || '');
                    if (fd.get('meta[color]'))         body.meta = { color: String(fd.get('meta[color]') || '') };

                    var url = rest + 'lookups/' + encodeURIComponent(type);
                    var method = 'POST';
                    if (id > 0) { url += '/' + id; method = 'PUT'; }

                    msg.textContent = '';
                    fetch(url, {
                        method: method,
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce, 'Accept': 'application/json' },
                        body: JSON.stringify(body)
                    }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, status: r.status, json: j }; }); })
                      .then(function(r){
                        if (r.ok) { window.location.reload(); return; }
                        var first = r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message;
                        msg.textContent = first || ('Error ' + r.status);
                      })
                      .catch(function(){ msg.textContent = 'Network error.'; });
                });
            }

            // Delete (per-row buttons)
            root.addEventListener('click', function(e){
                var btn = e.target.closest && e.target.closest('[data-tt-lookup-delete]');
                if (!btn) return;
                var id = parseInt(btn.getAttribute('data-tt-lookup-delete'), 10);
                var name = btn.getAttribute('data-tt-lookup-name') || '';
                if (!id) return;
                if (!window.confirm('<?php echo esc_js( __( 'Delete this row?', 'talenttrack' ) ); ?>'.replace('%s', name))) return;
                btn.disabled = true;
                fetch(rest + 'lookups/' + encodeURIComponent(type) + '/' + id, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' }
                }).then(function(r){ if (r.ok) window.location.reload(); else { btn.disabled = false; window.alert('Error ' + r.status); } });
            });
        })();
        </script>
        <?php
    }

    private static function renderSubBackLink(): void {
        $base = remove_query_arg( [ 'config_sub' ] );
        echo '<p style="margin:0 0 var(--tt-sp-3);"><a class="tt-link" href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All configuration', 'talenttrack' ) . '</a></p>';
    }

    /**
     * Inline CSS for the configuration tile grid. Shared between the
     * top-level Configuration index and the Lookups sub-index so a
     * direct deep-link to `?config_sub=lookups` still picks up the
     * styling without the top-level tile grid having run first.
     */
    private static function tileGridStyles(): void {
        ?>
        <style>
        .tt-cfg-tile-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
        .tt-cfg-tile { display: block; background: #fff; border: 1px solid #e5e7ea; border-radius: 8px; padding: 14px; text-decoration: none; color: #1a1d21; min-height: 76px; transition: transform 180ms cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 180ms ease, border-color 180ms ease; }
        .tt-cfg-tile:hover, .tt-cfg-tile:focus { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: #d0d4d8; color: #1a1d21; }
        /* v3.72.5 — icon column on each tile, mirrors the wp-admin
         * dashboard tile look. Width fixed; icon fills its slot. */
        .tt-cfg-tile-icon { width: 28px; height: 28px; margin-bottom: 8px; color: #0b3d2e; }
        .tt-cfg-tile-icon .tt-icon { width: 28px; height: 28px; }
        .tt-cfg-tile-title { font-weight: 600; font-size: 14px; line-height: 1.25; margin: 0 0 4px; color: #1a1d21; }
        .tt-cfg-tile-desc { color: #6b7280; font-size: 12px; line-height: 1.35; margin: 0; }
        </style>
        <?php
    }

    private static function renderTileGrid(): void {
        $base       = remove_query_arg( [ 'config_sub' ] );
        $admin_url  = admin_url( 'admin.php?page=tt-config' );

        // v3.72.5 — added per-tile icons so the Configuration grid is
        // scannable at a glance like the wp-admin dashboard. Reuses the
        // existing IconRenderer SVG set; no new assets required.
        $frontend_tiles = [
            'dashboard' => [ __( 'Default dashboard', 'talenttrack' ), __( 'Choose what every user sees on the dashboard root: the persona dashboard or the classic tile grid.', 'talenttrack' ), 'dashboard' ],
            'branding'  => [ __( 'Branding', 'talenttrack' ),     __( 'Academy name, logo, primary and secondary colours.', 'talenttrack' ), 'rate-card' ],
            'theme'     => [ __( 'Theme & fonts', 'talenttrack' ), __( 'Theme inheritance, display + body fonts and accent colours.', 'talenttrack' ), 'settings' ],
            'lookups'   => [ __( 'Lookups', 'talenttrack' ),       __( 'Activity types, positions, age groups, goal statuses, evaluation types — every dropdown vocabulary in one place.', 'talenttrack' ), 'categories' ],
            'rating'    => [ __( 'Rating scale', 'talenttrack' ),  __( 'Min, max and step for evaluation ratings.', 'talenttrack' ), 'weights' ],
            'menus'     => [ __( 'wp-admin menus', 'talenttrack' ), __( 'Show or hide the legacy wp-admin menu entries.', 'talenttrack' ), 'gear' ],
        ];

        // v3.72.5 — Players CSV bulk import as a Configuration tile
        // (primary entry point). The button on the Players page stays
        // as a secondary entry for power users still in that surface.
        $players_import_url = current_user_can( 'tt_edit_players' )
            ? add_query_arg( [ 'tt_view' => 'players-import' ], remove_query_arg( [ 'tt_view', 'config_sub' ] ) )
            : null;

        $admin_tiles = [];
        if ( $players_import_url !== null ) {
            $admin_tiles[] = [
                __( 'Players CSV import', 'talenttrack' ),
                __( 'Bulk-import players from a spreadsheet. Map columns, choose duplicate-handling, preview before commit.', 'talenttrack' ),
                $players_import_url,
                'import',
            ];
        }
        $admin_tiles = array_merge( $admin_tiles, [
            [ __( 'Custom CSS', 'talenttrack' ),                 __( 'Per-club custom styling (#0064): visual editor, code editor, file upload, starter templates, history with revert. Frontend + wp-admin surfaces.', 'talenttrack' ), add_query_arg( [ 'tt_view' => 'custom-css' ], remove_query_arg( [ 'tt_view', 'config_sub' ] ) ), 'rate-card' ],
            [ __( 'Spond integration', 'talenttrack' ),          __( 'Per-team iCal sync status and "Refresh now" buttons. Lives in wp-admin.', 'talenttrack' ),                admin_url( 'admin.php?page=tt-spond' ), 'sessions' ],
            [ __( 'Feature toggles', 'talenttrack' ),            __( 'Per-module enable/disable toggles. Live in wp-admin.', 'talenttrack' ),                                add_query_arg( [ 'tab' => 'toggles' ],     $admin_url ), 'gear' ],
            [ __( 'Backups', 'talenttrack' ),                    __( 'Manual + scheduled database backups. Lives in wp-admin.', 'talenttrack' ),                              add_query_arg( [ 'tab' => 'backups' ],     $admin_url ), 'migrations' ],
            [ __( 'Translations', 'talenttrack' ),               __( 'Per-locale string overrides and the .po/.mo refresh job.', 'talenttrack' ),                              add_query_arg( [ 'tab' => 'translations' ], $admin_url ), 'docs' ],
            [ __( 'Audit log', 'talenttrack' ),                  __( 'Settings + sensitive data change history.', 'talenttrack' ),                                              add_query_arg( [ 'tab' => 'audit' ],       $admin_url ), 'audit-log' ],
            [ __( 'Setup wizard', 'talenttrack' ),               __( 'Re-run the first-run onboarding wizard.', 'talenttrack' ),                                                add_query_arg( [ 'tab' => 'wizard' ],      $admin_url ), 'lightbulb' ],
        ] );

        echo '<p style="margin-bottom:var(--tt-sp-4); color:var(--tt-muted);">';
        esc_html_e( 'Pick a configuration area. Branding, theme, rating scale, and lookups are edited inline; the remaining areas open in wp-admin.', 'talenttrack' );
        echo '</p>';

        self::tileGridStyles();

        echo '<div class="tt-cfg-tile-grid">';
        foreach ( $frontend_tiles as $slug => $meta ) {
            $title = $meta[0];
            $desc  = $meta[1];
            $icon  = $meta[2] ?? '';
            $url = add_query_arg( [ 'config_sub' => $slug ], $base );
            echo '<a class="tt-cfg-tile" href="' . esc_url( $url ) . '">';
            if ( $icon !== '' ) {
                echo '<div class="tt-cfg-tile-icon">' . \TT\Shared\Icons\IconRenderer::render( $icon ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — IconRenderer returns sanitised SVG.
            }
            echo '<div class="tt-cfg-tile-title">' . esc_html( $title ) . '</div>';
            echo '<div class="tt-cfg-tile-desc">' . esc_html( $desc ) . '</div>';
            echo '</a>';
        }
        foreach ( $admin_tiles as $tile ) {
            $title = $tile[0];
            $desc  = $tile[1];
            $url   = $tile[2];
            $icon  = $tile[3] ?? '';
            echo '<a class="tt-cfg-tile" href="' . esc_url( $url ) . '">';
            if ( $icon !== '' ) {
                echo '<div class="tt-cfg-tile-icon">' . \TT\Shared\Icons\IconRenderer::render( $icon ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '<div class="tt-cfg-tile-title">' . esc_html( $title ) . ' ↗</div>';
            echo '<div class="tt-cfg-tile-desc">' . esc_html( $desc ) . '</div>';
            echo '</a>';
        }
        echo '</div>';
    }

    private static function renderBrandingForm(): void {
        $logo = QueryHelpers::get_config( 'logo_url', '' );
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="branding">
            <div class="tt-panel">
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-academy-name"><?php esc_html_e( 'Academy name', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-cfg-academy-name" class="tt-input" name="config[academy_name]" value="<?php echo esc_attr( QueryHelpers::get_config( 'academy_name', '' ) ); ?>" />
                    </div>

                    <div class="tt-field">
                        <span class="tt-field-label"><?php esc_html_e( 'Logo', 'talenttrack' ); ?></span>
                        <input type="hidden" id="tt-cfg-logo-url" name="config[logo_url]" value="<?php echo esc_attr( $logo ); ?>" />
                        <div id="tt-cfg-logo-preview" style="margin-bottom:8px;">
                            <?php if ( $logo ) : ?>
                                <img src="<?php echo esc_url( $logo ); ?>" alt="" style="max-height:80px; border-radius:6px; border:1px solid var(--tt-line);" />
                            <?php endif; ?>
                        </div>
                        <button type="button" class="tt-btn tt-btn-secondary" id="tt-cfg-logo-pick"><?php esc_html_e( 'Choose logo…', 'talenttrack' ); ?></button>
                        <button type="button" class="tt-btn tt-btn-secondary" id="tt-cfg-logo-clear" style="margin-left:6px;"><?php esc_html_e( 'Remove', 'talenttrack' ); ?></button>
                    </div>

                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-primary-color"><?php esc_html_e( 'Primary color', 'talenttrack' ); ?></label>
                        <input type="color" id="tt-cfg-primary-color" name="config[primary_color]" value="<?php echo esc_attr( QueryHelpers::get_config( 'primary_color', '#0b3d2e' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-secondary-color"><?php esc_html_e( 'Secondary color', 'talenttrack' ); ?></label>
                        <input type="color" id="tt-cfg-secondary-color" name="config[secondary_color]" value="<?php echo esc_attr( QueryHelpers::get_config( 'secondary_color', '#e8b624' ) ); ?>" />
                    </div>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save branding', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( true );
    }

    private static function renderThemeForm(): void {
        $theme_inherit = (string) QueryHelpers::get_config( 'theme_inherit', '0' );
        $font_display  = (string) QueryHelpers::get_config( 'font_display',  BrandFonts::SYSTEM_DEFAULT );
        $font_body     = (string) QueryHelpers::get_config( 'font_body',     BrandFonts::SYSTEM_DEFAULT );
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="theme">
            <div class="tt-panel">
                <p style="margin:0 0 var(--tt-sp-3); color:var(--tt-muted);">
                    <?php esc_html_e( 'Inheritance applies to fonts, colors, and basic links/buttons. TalentTrack’s structural design (spacing, layout, player cards) is unchanged. Pick fonts and accent colors below — fields left as “(System default)” or empty fall back to TalentTrack’s defaults.', 'talenttrack' ); ?>
                </p>

                <div class="tt-field">
                    <label>
                        <input type="checkbox" name="config[theme_inherit]" value="1" <?php checked( $theme_inherit, '1' ); ?> />
                        <?php esc_html_e( 'Defer typography, link color, headings and plain buttons to the active WP theme.', 'talenttrack' ); ?>
                    </label>
                </div>

                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-font-display"><?php esc_html_e( 'Display font', 'talenttrack' ); ?></label>
                        <select id="tt-cfg-font-display" class="tt-input" name="config[font_display]">
                            <?php foreach ( BrandFonts::displayOptions() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $font_display, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-font-body"><?php esc_html_e( 'Body font', 'talenttrack' ); ?></label>
                        <select id="tt-cfg-font-body" class="tt-input" name="config[font_body]">
                            <?php foreach ( BrandFonts::bodyOptions() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $font_body, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php
                    foreach ( [
                        'color_accent'  => [ __( 'Accent color',     'talenttrack' ), '#1e88e5' ],
                        'color_danger'  => [ __( 'Danger color',     'talenttrack' ), '#b32d2e' ],
                        'color_warning' => [ __( 'Warning color',    'talenttrack' ), '#dba617' ],
                        'color_success' => [ __( 'Success color',    'talenttrack' ), '#00a32a' ],
                        'color_info'    => [ __( 'Info color',       'talenttrack' ), '#2271b1' ],
                        'color_focus'   => [ __( 'Focus ring color', 'talenttrack' ), '#1e88e5' ],
                    ] as $key => $meta ) :
                        [ $label, $default ] = $meta;
                        ?>
                        <div class="tt-field">
                            <label class="tt-field-label" for="tt-cfg-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                            <input type="color" id="tt-cfg-<?php echo esc_attr( $key ); ?>" name="config[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( QueryHelpers::get_config( $key, $default ) ); ?>" />
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save theme', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( false );
    }

    private static function renderRatingForm(): void {
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="rating">
            <div class="tt-panel">
                <div class="tt-grid tt-grid-3">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-rating-min"><?php esc_html_e( 'Min', 'talenttrack' ); ?></label>
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-min" class="tt-input" name="config[rating_min]" min="0" max="10" step="0.5" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_min', '1' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-rating-max"><?php esc_html_e( 'Max', 'talenttrack' ); ?></label>
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-max" class="tt-input" name="config[rating_max]" min="1" max="20" step="0.5" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_max', '5' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-rating-step"><?php esc_html_e( 'Step', 'talenttrack' ); ?></label>
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-step" class="tt-input" name="config[rating_step]" min="0.1" max="1" step="0.1" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_step', '0.5' ) ); ?>" />
                    </div>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save rating scale', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( false );
    }

    private static function renderMenusForm(): void {
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="menus">
            <div class="tt-panel">
                <p style="margin:0 0 var(--tt-sp-3); color:var(--tt-muted);">
                    <?php esc_html_e( 'TalentTrack admin tools moved to the frontend in v3.12.0. The legacy wp-admin menu entries (Players, Teams, Configuration, …) are hidden by default. Direct URLs to those pages still work as an emergency fallback.', 'talenttrack' ); ?>
                </p>
                <div class="tt-field">
                    <input type="hidden" name="config[show_legacy_menus]" value="0" />
                    <label>
                        <input type="checkbox" name="config[show_legacy_menus]" value="1" <?php checked( QueryHelpers::get_config( 'show_legacy_menus', '0' ), '1' ); ?> />
                        <?php esc_html_e( 'Show legacy wp-admin menus', 'talenttrack' ); ?>
                    </label>
                    <p class="tt-field-hint" style="margin-top:6px;">
                        <?php esc_html_e( 'Re-expose the legacy menu entries in wp-admin for users who prefer them. Plugin still works on both surfaces; this just controls menu visibility.', 'talenttrack' ); ?>
                    </p>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save menus', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( false );
    }

    private static function renderDashboardForm(): void {
        $current = QueryHelpers::get_config( 'persona_dashboard.enabled', '1' );
        $is_persona = $current !== '0';

        // #0069 — per-persona override map. Empty string = inherit from
        // global default. '1' = force persona dashboard. '0' = force
        // classic tile grid. Used for testing one persona at a time
        // without flipping the whole site.
        $personas = [
            'academy_admin'       => __( 'Academy admin',           'talenttrack' ),
            'head_of_development' => __( 'Head of Development',     'talenttrack' ),
            'head_coach'          => __( 'Head coach',              'talenttrack' ),
            'assistant_coach'     => __( 'Assistant coach',         'talenttrack' ),
            'team_manager'        => __( 'Team manager',            'talenttrack' ),
            'scout'               => __( 'Scout',                   'talenttrack' ),
            'player'              => __( 'Player',                  'talenttrack' ),
            'parent'              => __( 'Parent',                  'talenttrack' ),
            'readonly_observer'   => __( 'Read-only observer',      'talenttrack' ),
        ];
        $per_persona = [];
        foreach ( $personas as $key => $_label ) {
            $per_persona[ $key ] = (string) QueryHelpers::get_config( 'persona_dashboard.' . $key . '.enabled', '' );
        }
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="dashboard">
            <div class="tt-panel">
                <p style="margin:0 0 var(--tt-sp-3); color:var(--tt-muted);">
                    <?php esc_html_e( 'Choose what every user sees when they open the dashboard. The persona dashboard is the configurable per-role landing built in #0060; the classic tile grid is the legacy menu of all available views.', 'talenttrack' ); ?>
                </p>

                <div class="tt-field">
                    <label style="display:block; margin-bottom:8px;">
                        <input type="radio" name="config[persona_dashboard.enabled]" value="1" <?php checked( $is_persona, true ); ?> />
                        <strong><?php esc_html_e( 'Persona dashboard (recommended)', 'talenttrack' ); ?></strong>
                    </label>
                    <p class="tt-field-hint" style="margin:0 0 var(--tt-sp-3) 24px;">
                        <?php esc_html_e( 'Each user lands on a layout tailored to their persona (player, parent, coach, head of development, club admin, scout, observer). Layouts are edited under wp-admin → Configuration → Dashboard layouts.', 'talenttrack' ); ?>
                    </p>

                    <label style="display:block; margin-bottom:8px;">
                        <input type="radio" name="config[persona_dashboard.enabled]" value="0" <?php checked( $is_persona, false ); ?> />
                        <strong><?php esc_html_e( 'Classic tile grid', 'talenttrack' ); ?></strong>
                    </label>
                    <p class="tt-field-hint" style="margin:0 0 0 24px;">
                        <?php esc_html_e( 'Falls back to the original tile grid (every user sees the same menu of tiles, filtered by capability). Use this if a customer is not yet ready to roll out the persona dashboard or hits a blocker.', 'talenttrack' ); ?>
                    </p>
                </div>
            </div>

            <div class="tt-panel" style="margin-top: 16px;">
                <h3 style="margin: 0 0 var(--tt-sp-2);">
                    <?php esc_html_e( 'Per-persona overrides', 'talenttrack' ); ?>
                </h3>
                <p style="margin:0 0 var(--tt-sp-3); color:var(--tt-muted); font-size: 13px;">
                    <?php esc_html_e( "Optional. Force a specific dashboard for a single persona — useful for testing one persona at a time on a real install without flipping the whole site. \"Inherit\" follows the global default above.", 'talenttrack' ); ?>
                </p>
                <table class="tt-table" style="width:100%; max-width: 520px;">
                    <thead>
                        <tr>
                            <th style="text-align:left;"><?php esc_html_e( 'Persona', 'talenttrack' ); ?></th>
                            <th style="text-align:left; width:200px;"><?php esc_html_e( 'Dashboard', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $personas as $key => $label ) :
                            $cur = $per_persona[ $key ];
                            $name = 'config[persona_dashboard.' . $key . '.enabled]';
                            ?>
                            <tr>
                                <td><?php echo esc_html( $label ); ?></td>
                                <td>
                                    <select name="<?php echo esc_attr( $name ); ?>" class="tt-input">
                                        <option value=""  <?php selected( $cur, '' );  ?>><?php esc_html_e( 'Inherit (use global default)', 'talenttrack' ); ?></option>
                                        <option value="1" <?php selected( $cur, '1' ); ?>><?php esc_html_e( 'Persona dashboard', 'talenttrack' ); ?></option>
                                        <option value="0" <?php selected( $cur, '0' ); ?>><?php esc_html_e( 'Classic tile grid', 'talenttrack' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save default dashboard', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( false );
    }

    private static function renderConfigJs( bool $with_logo ): void {
        ?>
        <script>
        (function(){
            <?php if ( $with_logo ) : ?>
            if (typeof wp !== 'undefined' && wp.media) {
                var frame;
                var pickBtn = document.getElementById('tt-cfg-logo-pick');
                var clearBtn = document.getElementById('tt-cfg-logo-clear');
                var hidden  = document.getElementById('tt-cfg-logo-url');
                var preview = document.getElementById('tt-cfg-logo-preview');
                if (pickBtn) pickBtn.addEventListener('click', function(){
                    if (!frame) {
                        frame = wp.media({
                            title: '<?php echo esc_js( __( 'Select logo', 'talenttrack' ) ); ?>',
                            button: { text: '<?php echo esc_js( __( 'Use', 'talenttrack' ) ); ?>' },
                            library: { type: 'image' },
                            multiple: false
                        });
                        frame.on('select', function(){
                            var att = frame.state().get('selection').first().toJSON();
                            hidden.value = att.url;
                            preview.innerHTML = '<img src="' + att.url + '" alt="" style="max-height:80px; border-radius:6px; border:1px solid var(--tt-line);" />';
                        });
                    }
                    frame.open();
                });
                if (clearBtn) clearBtn.addEventListener('click', function(){
                    hidden.value = '';
                    preview.innerHTML = '';
                });
            }
            <?php endif; ?>

            var form = document.getElementById('tt-config-form');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = form.querySelector('.tt-save-btn');
                var i18n = (window.TT && window.TT.i18n) || {};
                var rest = window.TT || {};
                if (btn) btn.setAttribute('data-state', 'saving');

                var fd = new FormData(form);
                var config = {};
                fd.forEach(function(value, key){
                    var m = /^config\[(.+)\]$/.exec(key);
                    if (m) config[m[1]] = value;
                });
                if (form.dataset.ttConfigSub === 'theme' && (config.theme_inherit === undefined || config.theme_inherit === '')) config.theme_inherit = '0';
                if (form.dataset.ttConfigSub === 'menus' && (config.show_legacy_menus === undefined || config.show_legacy_menus === '')) config.show_legacy_menus = '0';

                var url = (rest.rest_url || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/') + 'config';
                var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
                if (rest.rest_nonce) headers['X-WP-Nonce'] = rest.rest_nonce;
                fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify({ config: config }) })
                    .then(function(res){ return res.json().then(function(json){ return { ok: res.ok, json: json }; }); })
                    .then(function(r){
                        var msg = form.querySelector('.tt-form-msg');
                        if (r.ok && r.json && r.json.success) {
                            if (btn) btn.setAttribute('data-state', 'saved');
                            if (msg) { msg.classList.add('tt-success'); msg.textContent = i18n.saved || 'Saved.'; }
                            setTimeout(function(){ if (btn) btn.setAttribute('data-state', 'idle'); }, 1500);
                        } else {
                            if (btn) btn.setAttribute('data-state', 'error');
                            var errMsg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || i18n.error_generic || 'Error.';
                            if (msg) { msg.classList.add('tt-error'); msg.textContent = errMsg; }
                            setTimeout(function(){ if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                        }
                    })
                    .catch(function(){
                        if (btn) btn.setAttribute('data-state', 'error');
                        var msg = form.querySelector('.tt-form-msg');
                        if (msg) { msg.classList.add('tt-error'); msg.textContent = (i18n.network_error || 'Network error.'); }
                        setTimeout(function(){ if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                    });
            });
        })();
        </script>
        <?php
    }
}
