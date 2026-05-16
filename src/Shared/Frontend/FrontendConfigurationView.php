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
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $sub = isset( $_GET['config_sub'] ) ? sanitize_key( (string) $_GET['config_sub'] ) : '';

        // v3.92.1 — breadcrumb: when sub is set, render two-level chain
        // (Dashboard → Configuration → [sub]); otherwise just Dashboard
        // → Configuration.
        $config_label = __( 'Configuration', 'talenttrack' );
        if ( $sub !== '' ) {
            $sub_labels = [
                'branding'    => __( 'Branding', 'talenttrack' ),
                'theme'       => __( 'Theme & fonts', 'talenttrack' ),
                'rating'      => __( 'Rating scale', 'talenttrack' ),
            ];
            $current_sub = $sub_labels[ $sub ] ?? ucfirst( str_replace( '_', ' ', $sub ) );
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                $current_sub,
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'configuration', $config_label ) ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $config_label );
        }

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
            // v3.110.163 — surface `player_value` as a first-class lookup
            // tile. The Goal wizard's LinkStep already exposes a "Value"
            // picker that reads this vocabulary; it just had no maintenance
            // surface beyond wp-admin. Seeded with 8 starters by #0044.
            [ __( 'Player values',      'talenttrack' ), __( 'Player virtues used as PDP goal links: Commitment, Coachability, Resilience, etc.', 'talenttrack' ), 'player_values',   'lightbulb' ],
            // v3.110.201 (#831) — eight lookup_types that were already in
            // tt_lookups and read by the renderer but had no maintenance
            // surface on the frontend. Adding them as tiles unlocks the
            // same translate/extend UX the other categories already have.
            [ __( 'Activity statuses',     'talenttrack' ), __( 'Draft / scheduled / conducted. Colour-coded pills on the activities list.',                  'talenttrack' ), 'activity_statuses',          'workflow' ],
            [ __( 'Certification types',   'talenttrack' ), __( 'Staff certifications (UEFA-A/B/C, First aid, GDPR awareness, Child safeguarding, …).',       'talenttrack' ), 'cert_types',                 'rate-card' ],
            [ __( 'Tournament formations', 'talenttrack' ), __( 'Formations selectable when configuring a tournament (4-3-3, 3-5-2, …).',                     'talenttrack' ), 'tournament_formations',      'kanban' ],
            [ __( 'Opponent levels',       'talenttrack' ), __( 'Opponent strength buckets selectable on tournament setup.',                                  'talenttrack' ), 'tournament_opponent_levels', 'podium' ],
            [ __( 'Behaviour ratings',     'talenttrack' ), __( 'Concerning … Exemplary. Labels for the player behaviour card and evaluation review.',         'talenttrack' ), 'behaviour_ratings',          'profile' ],
            [ __( 'Potential bands',       'talenttrack' ), __( 'Far below club level … Elite potential. Drives the player potential card.',                  'talenttrack' ), 'potential_bands',            'categories' ],
            [ __( 'Journey event types',   'talenttrack' ), __( 'Trial / signing / promotion / release / graduation. Tags player timeline events.',          'talenttrack' ), 'journey_event_types',        'track' ],
            [ __( 'Competition types',     'talenttrack' ), __( 'Competition categories (league, cup, friendly, tournament, …) used by match pickers.',       'talenttrack' ), 'competition_types',          'methodology' ],
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
            // v3.110.163 — player virtues used as PDP goal-link target.
            // Description column is on so the operator can write a short
            // gloss for each value ("Commitment — turning up on time and
            // ready to train") that surfaces wherever the value is shown.
            'player_values'   => [ 'label' => __( 'Player values',       'talenttrack' ), 'type' => 'player_value',      'show_desc' => true,  'show_color' => false ],
            // v3.110.201 (#831) — eight lookup_types previously hidden from the
            // frontend grid. `show_desc=true` on the four where the description
            // column is operator-meaningful (gloss for the label); `show_color`
            // only on activity_status because its pills are colour-coded list-side.
            'activity_statuses'          => [ 'label' => __( 'Activity statuses',     'talenttrack' ), 'type' => 'activity_status',           'show_desc' => false, 'show_color' => true  ],
            'cert_types'                 => [ 'label' => __( 'Certification types',   'talenttrack' ), 'type' => 'cert_type',                 'show_desc' => true,  'show_color' => false ],
            'tournament_formations'      => [ 'label' => __( 'Tournament formations', 'talenttrack' ), 'type' => 'tournament_formation',      'show_desc' => false, 'show_color' => false ],
            'tournament_opponent_levels' => [ 'label' => __( 'Opponent levels',       'talenttrack' ), 'type' => 'tournament_opponent_level', 'show_desc' => true,  'show_color' => false ],
            'behaviour_ratings'          => [ 'label' => __( 'Behaviour ratings',     'talenttrack' ), 'type' => 'behaviour_rating_label',    'show_desc' => true,  'show_color' => false ],
            'potential_bands'            => [ 'label' => __( 'Potential bands',       'talenttrack' ), 'type' => 'potential_band',            'show_desc' => true,  'show_color' => false ],
            'journey_event_types'        => [ 'label' => __( 'Journey event types',   'talenttrack' ), 'type' => 'journey_event_type',        'show_desc' => true,  'show_color' => false ],
            'competition_types'          => [ 'label' => __( 'Competition types',     'talenttrack' ), 'type' => 'competition_type',          'show_desc' => false, 'show_color' => false ],
        ];
        if ( ! isset( $registry[ $slug ] ) ) return null;
        $meta = $registry[ $slug ];
        $meta['slug'] = $slug;
        return $meta;
    }

    /**
     * v3.110.190 (#798) — every locale the operator might want to
     * maintain a translation for. Returns the union of WP-installed
     * locales + the plugin-shipped `.po` set + the site locale, minus
     * `en_US` (which is the canonical name field above the translations
     * block — English isn't a "translation" of itself).
     *
     * Was previously a narrower set scoped to `get_available_languages()`
     * minus the current locale; that excluded both fr/de/es (the plugin
     * ships .po files for them but WP wasn't told to install them) and
     * the current locale itself (so a Dutch site with English canonical
     * names had nowhere to enter the Dutch override).
     *
     * @return list<string>
     */
    private static function translationTargets(): array {
        $locales = \TT\Infrastructure\Query\LookupTranslator::installedLocales();
        $out = [];
        foreach ( $locales as $loc ) {
            if ( $loc === 'en_US' ) continue; // canonical name field handles English.
            $out[] = $loc;
        }
        return array_values( array_unique( $out ) );
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
     * v3.110.203 (#830) — rewritten as a master-detail layout: a left
     * rail lists every row, a right pane shows the edit-or-add form.
     * Above 768px the two render side by side; below 768px they stack
     * and clicking a row scrolls the pane into view. Row clicks populate
     * the form in-place (no page reload) by reading data-* attributes
     * baked into each row at render time. Translations are bulk-loaded
     * server-side in one SELECT so the JS round-trip is also avoided.
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

        // v3.110.203 — one bulk SELECT for translations across every
        // row in the rail. Keyed by `lookup_id => locale => field => value`
        // so the JS row-click handler can populate the per-locale inputs
        // without a REST round-trip. Empty when no rows.
        $tx_by_row = self::loadTranslationsForLookupIds(
            array_map( fn( $r ) => (int) $r->id, $items )
        );
        $tx_targets = self::translationTargets();

        $base   = remove_query_arg( [ 'edit' ] );
        $add_id = 'tt-lkp-' . sanitize_html_class( $meta['slug'] );

        self::masterDetailStyles();
        ?>
        <div class="tt-lookup-md" data-tt-lookups-editor
             data-lookup-type="<?php echo esc_attr( $type ); ?>"
             data-show-desc="<?php echo $meta['show_desc'] ? '1' : '0'; ?>"
             data-show-color="<?php echo $meta['show_color'] ? '1' : '0'; ?>"
             data-edit-id="<?php echo (int) $edit_id; ?>">

            <aside class="tt-lookup-md-rail" aria-label="<?php esc_attr_e( 'Lookup rows', 'talenttrack' ); ?>">
                <div class="tt-lookup-md-rail-head">
                    <button type="button" class="tt-btn tt-btn-primary tt-btn-small tt-lookup-md-new"
                            data-tt-lookup-new>
                        <?php esc_html_e( '+ Add new', 'talenttrack' ); ?>
                    </button>
                </div>
                <div class="tt-lookup-md-rail-body">
                    <?php if ( empty( $items ) ) : ?>
                        <p class="tt-lookup-md-empty"><em><?php esc_html_e( 'No items yet. Use "+ Add new" to seed the first row.', 'talenttrack' ); ?></em></p>
                    <?php else : ?>
                        <ul class="tt-lookup-md-list tt-sortable-table" data-tt-sortable="1">
                            <?php foreach ( $items as $row ) :
                                $row_meta_arr = QueryHelpers::lookup_meta( $row );
                                $row_color    = is_string( $row_meta_arr['color'] ?? null ) ? (string) $row_meta_arr['color'] : '';
                                $is_locked    = ! empty( $row_meta_arr['is_locked'] );
                                $row_id       = (int) $row->id;
                                $row_tx       = $tx_by_row[ $row_id ] ?? [];
                                $is_active    = ( $editing && (int) $editing->id === $row_id );
                                ?>
                                <li class="tt-lookup-md-row<?php echo $is_active ? ' is-active' : ''; ?>"
                                    data-id="<?php echo $row_id; ?>"
                                    data-tt-lookup-row
                                    role="button"
                                    tabindex="0"
                                    data-row-name="<?php echo esc_attr( (string) $row->name ); ?>"
                                    data-row-sort="<?php echo (int) $row->sort_order; ?>"
                                    data-row-desc="<?php echo esc_attr( (string) ( $row->description ?? '' ) ); ?>"
                                    data-row-color="<?php echo esc_attr( $row_color ); ?>"
                                    data-row-locked="<?php echo $is_locked ? '1' : '0'; ?>"
                                    data-row-tx="<?php echo esc_attr( (string) wp_json_encode( $row_tx ) ); ?>">
                                    <span class="tt-lookup-md-row-grip tt-drag-handle"
                                          title="<?php esc_attr_e( 'Drag to reorder', 'talenttrack' ); ?>">⋮⋮</span>
                                    <?php if ( $meta['show_color'] && $row_color !== '' ) : ?>
                                        <span class="tt-lookup-md-row-swatch" style="background:<?php echo esc_attr( $row_color ); ?>" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <span class="tt-lookup-md-row-name">
                                        <?php echo esc_html( \TT\Infrastructure\Query\LookupTranslator::name( $row ) ); ?>
                                        <?php if ( $is_locked ) : ?>
                                            <span class="tt-lookup-md-row-lock" title="<?php esc_attr_e( 'Locked — workflow rules depend on this row.', 'talenttrack' ); ?>">🔒</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="tt-lookup-md-row-sort tt-sort-order-cell"><?php echo (int) $row->sort_order; ?></span>
                                    <?php if ( ! $is_locked ) : ?>
                                        <button type="button" class="tt-lookup-md-row-delete"
                                                data-tt-lookup-delete="<?php echo $row_id; ?>"
                                                data-tt-lookup-name="<?php echo esc_attr( (string) $row->name ); ?>"
                                                title="<?php esc_attr_e( 'Delete', 'talenttrack' ); ?>"
                                                aria-label="<?php esc_attr_e( 'Delete', 'talenttrack' ); ?>">×</button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php
                // Keep drag-reorder wiring intact. Targets the same
                // `data-tt-sortable` selector the previous table used,
                // so the existing wp-admin endpoint keeps working
                // unchanged.
                \TT\Shared\Admin\DragReorder::renderScript( 'lookup', $type );
                ?>
            </aside>

            <section class="tt-lookup-md-pane" aria-label="<?php esc_attr_e( 'Lookup editor', 'talenttrack' ); ?>">
                <header class="tt-lookup-md-pane-head">
                    <h3 class="tt-lookup-md-pane-title" data-tt-lookup-pane-title>
                        <?php echo $editing ? esc_html__( 'Edit row', 'talenttrack' ) : esc_html__( 'Add new row', 'talenttrack' ); ?>
                    </h3>
                    <button type="button" class="tt-lookup-md-pane-back" data-tt-lookup-pane-back
                            aria-label="<?php esc_attr_e( 'Back to list', 'talenttrack' ); ?>">
                        ← <?php esc_html_e( 'Back to list', 'talenttrack' ); ?>
                    </button>
                </header>
                <form data-tt-lookup-form class="tt-lookup-md-pane-body">
                    <input type="hidden" name="id" value="<?php echo (int) ( $editing->id ?? 0 ); ?>" data-tt-lookup-id />
                    <div class="tt-grid tt-grid-2">
                        <div class="tt-field">
                            <label class="tt-field-label tt-field-required" for="<?php echo esc_attr( $add_id ); ?>-name"><?php esc_html_e( 'Name', 'talenttrack' ); ?></label>
                            <input type="text" id="<?php echo esc_attr( $add_id ); ?>-name" class="tt-input" name="name" required value="<?php echo esc_attr( (string) ( $editing->name ?? '' ) ); ?>" />
                        </div>
                        <div class="tt-field">
                            <label class="tt-field-label" for="<?php echo esc_attr( $add_id ); ?>-sort"><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label>
                            <input type="number" id="<?php echo esc_attr( $add_id ); ?>-sort" class="tt-input" name="sort_order" inputmode="numeric" min="0" step="1" value="<?php echo esc_attr( (string) ( $editing->sort_order ?? 0 ) ); ?>" />
                        </div>
                        <?php if ( $meta['show_desc'] ) : ?>
                            <div class="tt-field" style="grid-column:1 / -1;">
                                <label class="tt-field-label" for="<?php echo esc_attr( $add_id ); ?>-desc"><?php esc_html_e( 'Description', 'talenttrack' ); ?></label>
                                <input type="text" id="<?php echo esc_attr( $add_id ); ?>-desc" class="tt-input" name="description" value="<?php echo esc_attr( (string) ( $editing->description ?? '' ) ); ?>" />
                            </div>
                        <?php endif; ?>
                        <?php if ( $meta['show_color'] ) :
                            $existing_meta = $editing ? QueryHelpers::lookup_meta( $editing ) : [];
                            $existing_color = is_string( $existing_meta['color'] ?? null ) ? (string) $existing_meta['color'] : '#5b6e75';
                            ?>
                            <div class="tt-field">
                                <label class="tt-field-label" for="<?php echo esc_attr( $add_id ); ?>-color"><?php esc_html_e( 'Pill colour', 'talenttrack' ); ?></label>
                                <input type="color" id="<?php echo esc_attr( $add_id ); ?>-color" name="meta[color]" value="<?php echo esc_attr( $existing_color ); ?>" />
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php
                    $existing_translations = $editing ? ( $tx_by_row[ (int) $editing->id ] ?? [] ) : [];
                    if ( ! empty( $tx_targets ) ) :
                        ?>
                        <div class="tt-field tt-lookup-md-tx" style="grid-column:1 / -1;">
                            <span class="tt-field-label"><?php esc_html_e( 'Translations', 'talenttrack' ); ?></span>
                            <p class="tt-lookup-md-tx-help">
                                <?php
                                if ( $meta['show_desc'] ) {
                                    esc_html_e( 'Per-locale name and description. Leave blank to fall back to the canonical English values and the .po-shipped translation. Click "Translate" to pre-fill the name fields from the configured engine — review and edit before saving.', 'talenttrack' );
                                } else {
                                    esc_html_e( 'Per-locale display name. Leave blank to fall back to the canonical English value and the .po-shipped translation. Click "Translate" to fill these from the configured engine — review and edit before saving.', 'talenttrack' );
                                }
                                ?>
                            </p>
                            <p>
                                <button type="button" class="tt-btn tt-btn-secondary" data-tt-lookup-translate><?php esc_html_e( 'Translate to other languages', 'talenttrack' ); ?></button>
                                <span class="tt-form-msg" data-tt-translate-msg style="margin-left:8px;"></span>
                            </p>
                            <div class="tt-lookup-md-tx-list">
                                <?php foreach ( $tx_targets as $locale ) :
                                    $field_id_name = $add_id . '-tx-name-' . sanitize_html_class( $locale );
                                    $field_id_desc = $add_id . '-tx-desc-' . sanitize_html_class( $locale );
                                    $value_name = (string) ( $existing_translations[ $locale ]['name']        ?? '' );
                                    $value_desc = (string) ( $existing_translations[ $locale ]['description'] ?? '' );
                                    ?>
                                    <div class="tt-field tt-lookup-md-tx-row">
                                        <label class="tt-field-label" for="<?php echo esc_attr( $field_id_name ); ?>"><code><?php echo esc_html( $locale ); ?></code></label>
                                        <input type="text" id="<?php echo esc_attr( $field_id_name ); ?>"
                                               class="tt-input"
                                               name="translations[<?php echo esc_attr( $locale ); ?>][name]"
                                               value="<?php echo esc_attr( $value_name ); ?>"
                                               data-tt-tx-locale="<?php echo esc_attr( $locale ); ?>"
                                               data-tt-tx-field="name"
                                               placeholder="<?php esc_attr_e( 'Translated name', 'talenttrack' ); ?>" />
                                        <?php if ( $meta['show_desc'] ) : ?>
                                            <input type="text" id="<?php echo esc_attr( $field_id_desc ); ?>"
                                                   class="tt-input"
                                                   name="translations[<?php echo esc_attr( $locale ); ?>][description]"
                                                   value="<?php echo esc_attr( $value_desc ); ?>"
                                                   data-tt-tx-locale="<?php echo esc_attr( $locale ); ?>"
                                                   data-tt-tx-field="description"
                                                   placeholder="<?php esc_attr_e( 'Translated description', 'talenttrack' ); ?>" />
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="tt-lookup-md-pane-foot">
                        <?php
                        $save_label_idle = $editing ? __( 'Save changes', 'talenttrack' ) : __( 'Add row', 'talenttrack' );
                        echo FormSaveButton::render( [
                            'label'        => $save_label_idle,
                            'label_saving' => __( 'Saving…', 'talenttrack' ),
                            'label_saved'  => __( 'Saved', 'talenttrack' ),
                            'cancel_url'   => $base,
                            'cancel_label' => __( 'Cancel', 'talenttrack' ),
                        ] );
                        ?>
                    </div>
                    <div class="tt-form-msg" data-tt-lookup-msg></div>
                </form>
            </section>
        </div>

        <script>
        (function(){
            'use strict';
            var root = document.querySelector('[data-tt-lookups-editor]');
            if (!root) return;

            var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';
            var rest  = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
            var type  = root.getAttribute('data-lookup-type');

            // i18n — localized labels used by inline JS error paths.
            var T_ERROR = '<?php echo esc_js( __( 'Error', 'talenttrack' ) ); ?>';
            var T_NETWORK_ERROR = '<?php echo esc_js( __( 'Network error.', 'talenttrack' ) ); ?>';

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
                    var meta = {};
                    if (fd.get('meta[color]')) meta.color = String(fd.get('meta[color]') || '');
                    if (Object.keys(meta).length > 0) body.meta = meta;

                    // v3.110.190 (#798) — collect per-locale name +
                    // description edits and send as a top-level
                    // `translations` field; the REST controller writes
                    // through TranslationsRepository to the canonical
                    // `tt_translations` table. The old meta.translations
                    // path is gone — it was a write-only sink the
                    // renderer didn't read.
                    var translations = {};
                    var tx_inputs = form.querySelectorAll('[data-tt-tx-locale]');
                    tx_inputs.forEach(function(inp){
                        var loc   = inp.getAttribute('data-tt-tx-locale');
                        var field = inp.getAttribute('data-tt-tx-field') || 'name';
                        var val   = String(inp.value || '').trim();
                        if (!loc) return;
                        if (!translations[loc]) translations[loc] = {};
                        // Empty string sent explicitly so the controller
                        // can drop a previously-set translation on save.
                        translations[loc][field] = val;
                    });
                    if (Object.keys(translations).length > 0) body.translations = translations;

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
                        msg.textContent = first || (T_ERROR + ' ' + r.status);
                      })
                      .catch(function(){ msg.textContent = T_NETWORK_ERROR; });
                });
            }

            // v3.74.4 — Translate button: POST to /translations/preview
            // and fill the per-locale fields. Admin can edit before save.
            var tx_btn = root.querySelector('[data-tt-lookup-translate]');
            if (tx_btn) {
                tx_btn.addEventListener('click', function(){
                    var msg = root.querySelector('[data-tt-translate-msg]');
                    var name_input = root.querySelector('input[name="name"]');
                    var text = name_input ? String(name_input.value || '').trim() : '';
                    if (text === '') { msg.textContent = '<?php echo esc_js( __( 'Enter a name first.', 'talenttrack' ) ); ?>'; return; }
                    msg.textContent = '<?php echo esc_js( __( 'Translating…', 'talenttrack' ) ); ?>';
                    tx_btn.disabled = true;
                    fetch(rest + 'translations/preview', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce, 'Accept': 'application/json' },
                        body: JSON.stringify({ text: text, source_lang: '<?php echo esc_js( substr( (string) get_locale(), 0, 2 ) ); ?>' })
                    }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, status: r.status, json: j }; }); })
                      .then(function(r){
                        tx_btn.disabled = false;
                        if (r.ok && r.json && r.json.success) {
                            var translations = (r.json.data && r.json.data.translations) || {};
                            Object.keys(translations).forEach(function(loc){
                                var inp = form.querySelector('[data-tt-tx-locale="' + loc + '"]');
                                if (inp && (!inp.value || inp.value.trim() === '')) inp.value = translations[loc];
                            });
                            msg.textContent = '<?php echo esc_js( __( 'Translated. Review and edit before saving.', 'talenttrack' ) ); ?>';
                        } else {
                            var first = r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message;
                            msg.textContent = first || (T_ERROR + ' ' + r.status);
                        }
                      })
                      .catch(function(){ tx_btn.disabled = false; msg.textContent = '<?php echo esc_js( __( 'Network error.', 'talenttrack' ) ); ?>'; });
                });
            }

            // Delete (per-row buttons) — keep `closest` so swatch / text
            // clicks inside the row don't accidentally hit Delete.
            root.addEventListener('click', function(e){
                var btn = e.target.closest && e.target.closest('[data-tt-lookup-delete]');
                if (!btn) return;
                e.stopPropagation(); // don't also fire the row-select handler
                var id = parseInt(btn.getAttribute('data-tt-lookup-delete'), 10);
                var name = btn.getAttribute('data-tt-lookup-name') || '';
                if (!id) return;
                if (!window.confirm('<?php echo esc_js( __( 'Delete this row?', 'talenttrack' ) ); ?>'.replace('%s', name))) return;
                btn.disabled = true;
                fetch(rest + 'lookups/' + encodeURIComponent(type) + '/' + id, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' }
                }).then(function(r){ if (r.ok) window.location.reload(); else { btn.disabled = false; window.alert(T_ERROR + ' ' + r.status); } });
            });

            // v3.110.203 (#830) — row-click populates the form in-place
            // (no page navigation). Rail rows carry every value they own
            // as `data-row-*` attributes, including a JSON blob of all
            // translations for that row. The form is rendered once
            // server-side; we just rewrite its input values.
            var SAVE_LABEL_ADD  = '<?php echo esc_js( __( 'Add row', 'talenttrack' ) ); ?>';
            var SAVE_LABEL_EDIT = '<?php echo esc_js( __( 'Save changes', 'talenttrack' ) ); ?>';
            var TITLE_ADD       = '<?php echo esc_js( __( 'Add new row', 'talenttrack' ) ); ?>';
            var TITLE_EDIT      = '<?php echo esc_js( __( 'Edit row', 'talenttrack' ) ); ?>';

            function populate(row) {
                if (!form) return;
                var idEl   = form.querySelector('[data-tt-lookup-id]');
                var name   = form.querySelector('input[name="name"]');
                var sort   = form.querySelector('input[name="sort_order"]');
                var desc   = form.querySelector('input[name="description"]');
                var color  = form.querySelector('input[name="meta[color]"]');
                var title  = root.querySelector('[data-tt-lookup-pane-title]');
                var save   = form.querySelector('.tt-save-btn');
                var label  = save && save.querySelector('.tt-save-btn-label');
                var msg    = root.querySelector('[data-tt-lookup-msg]');

                if (msg) msg.textContent = '';

                if (row === null) {
                    if (idEl)  idEl.value  = '0';
                    if (name)  name.value  = '';
                    if (sort)  sort.value  = '0';
                    if (desc)  desc.value  = '';
                    if (color) color.value = '#5b6e75';
                    if (title) title.textContent = TITLE_ADD;
                    if (save)  save.setAttribute('data-label-idle', SAVE_LABEL_ADD);
                    if (label) label.textContent = SAVE_LABEL_ADD;
                } else {
                    if (idEl)  idEl.value  = String(row.getAttribute('data-id') || '0');
                    if (name)  name.value  = String(row.getAttribute('data-row-name') || '');
                    if (sort)  sort.value  = String(row.getAttribute('data-row-sort') || '0');
                    if (desc)  desc.value  = String(row.getAttribute('data-row-desc') || '');
                    if (color) color.value = String(row.getAttribute('data-row-color') || '') || '#5b6e75';
                    if (title) title.textContent = TITLE_EDIT;
                    if (save)  save.setAttribute('data-label-idle', SAVE_LABEL_EDIT);
                    if (label) label.textContent = SAVE_LABEL_EDIT;
                }

                // Translations: parse the JSON blob (or {} for blank).
                var tx = {};
                if (row !== null) {
                    var raw = row.getAttribute('data-row-tx') || '';
                    if (raw !== '') { try { tx = JSON.parse(raw); } catch (e) { tx = {}; } }
                }
                form.querySelectorAll('[data-tt-tx-locale]').forEach(function(inp){
                    var loc   = inp.getAttribute('data-tt-tx-locale');
                    var field = inp.getAttribute('data-tt-tx-field') || 'name';
                    var v     = (tx && tx[loc] && tx[loc][field]) || '';
                    inp.value = String(v);
                });

                // URL: keep ?edit=N for deep-link / refresh so the
                // selected row survives a reload. Empty selection
                // clears the param.
                var id = row !== null ? parseInt(row.getAttribute('data-id'), 10) : 0;
                var url = new URL(window.location.href);
                if (id > 0) url.searchParams.set('edit', String(id));
                else        url.searchParams.delete('edit');
                window.history.replaceState({}, '', url.toString());

                // Mobile: scroll the pane into view so the form is
                // visible after a tap on the rail. No-op on desktop
                // (the pane is already onscreen).
                if (window.matchMedia('(max-width: 767.98px)').matches) {
                    root.classList.add('is-pane-open');
                    var pane = root.querySelector('.tt-lookup-md-pane');
                    if (pane && pane.scrollIntoView) pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }

                // Mark the rail row active.
                root.querySelectorAll('[data-tt-lookup-row]').forEach(function(el){
                    if (row !== null && el === row) el.classList.add('is-active');
                    else el.classList.remove('is-active');
                });

                if (name) name.focus();
            }

            // Rail row click.
            root.addEventListener('click', function(e){
                if (e.target.closest && e.target.closest('[data-tt-lookup-delete]')) return; // handled above
                if (e.target.closest && e.target.closest('.tt-lookup-md-row-grip'))   return; // drag-handle, ignore
                var row = e.target.closest && e.target.closest('[data-tt-lookup-row]');
                if (!row) return;
                populate(row);
            });

            // Keyboard: Enter / Space on a focused row triggers the
            // same populate() the click handler runs. Each row has
            // role="button" tabindex="0", so it's reachable via Tab.
            root.addEventListener('keydown', function(e){
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var row = e.target.closest && e.target.closest('[data-tt-lookup-row]');
                if (!row) return;
                if (e.target.closest && e.target.closest('[data-tt-lookup-delete]')) return;
                e.preventDefault();
                populate(row);
            });

            // "+ Add new" button → blank form, no row selected.
            var add_btn = root.querySelector('[data-tt-lookup-new]');
            if (add_btn) add_btn.addEventListener('click', function(){ populate(null); });

            // Mobile-only "Back to list" inside the pane header.
            var back_btn = root.querySelector('[data-tt-lookup-pane-back]');
            if (back_btn) back_btn.addEventListener('click', function(){
                root.classList.remove('is-pane-open');
                var rail = root.querySelector('.tt-lookup-md-rail');
                if (rail && rail.scrollIntoView) rail.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            // On mobile load: if we landed with ?edit=N, the pane is
            // already populated server-side — surface it.
            if (window.matchMedia('(max-width: 767.98px)').matches) {
                var initial_edit = parseInt(root.getAttribute('data-edit-id') || '0', 10);
                if (initial_edit > 0) root.classList.add('is-pane-open');
            }
        })();
        </script>
        <?php
    }

    private static function renderSubBackLink(): void {
        $base = remove_query_arg( [ 'config_sub' ] );
        echo '<p style="margin:0 0 var(--tt-sp-3);"><a class="tt-link" href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All configuration', 'talenttrack' ) . '</a></p>';
    }

    /**
     * v3.110.203 (#830) — bulk-load translations for every row in the
     * rail in one SELECT. Without this we'd either do N+1 `allFor()`
     * calls or fetch on row-click; both are unnecessary round-trips
     * for data already keyed by `(entity_type='lookup', entity_id)`.
     *
     * @param list<int> $ids
     * @return array<int, array<string, array<string, string>>>  id => locale => field => value
     */
    private static function loadTranslationsForLookupIds( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), fn( $n ) => $n > 0 ) ) );
        if ( empty( $ids ) ) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'tt_translations';

        // Schema check — early installs may not have the table yet.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return [];

        $club_id      = \TT\Infrastructure\Tenancy\CurrentClub::id();
        $entity_type  = \TT\Modules\I18n\TranslatableFieldRegistry::ENTITY_LOOKUP;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $params   = array_merge( [ $club_id, $entity_type ], $ids );
        $sql      = $wpdb->prepare(
            "SELECT entity_id, field, locale, value
               FROM {$table}
              WHERE club_id = %d AND entity_type = %s AND entity_id IN ($placeholders)",
            ...$params
        );
        $rows = (array) $wpdb->get_results( $sql, ARRAY_A );

        $out = [];
        foreach ( $rows as $r ) {
            $id    = (int) ( $r['entity_id'] ?? 0 );
            $field = (string) ( $r['field']   ?? '' );
            $loc   = (string) ( $r['locale']  ?? '' );
            $val   = (string) ( $r['value']   ?? '' );
            if ( $id <= 0 || $field === '' || $loc === '' ) continue;
            $out[ $id ][ $loc ][ $field ] = $val;
        }
        return $out;
    }

    /**
     * Inline CSS for the lookup master-detail editor.
     * Mobile-first: stacked rail + pane below 768px; switches to a
     * two-column grid above. Pane footer (Save / Cancel) sticks to the
     * bottom of its column on desktop, scrolls inline on mobile.
     */
    private static function masterDetailStyles(): void {
        ?>
        <style>
        .tt-lookup-md { display: grid; grid-template-columns: 1fr; gap: var(--tt-sp-3, 12px); }
        .tt-lookup-md-rail,
        .tt-lookup-md-pane { background: #fff; border: 1px solid var(--tt-line, #e5e7ea); border-radius: 8px; }
        .tt-lookup-md-rail-head { display: flex; justify-content: flex-end; padding: var(--tt-sp-2, 8px) var(--tt-sp-3, 12px); border-bottom: 1px solid var(--tt-line, #e5e7ea); }
        .tt-lookup-md-rail-body { padding: var(--tt-sp-2, 8px) 0; max-height: 60vh; overflow-y: auto; }
        .tt-lookup-md-empty { padding: var(--tt-sp-3, 12px); color: var(--tt-muted, #6b7280); margin: 0; }
        .tt-lookup-md-list { list-style: none; margin: 0; padding: 0; }
        .tt-lookup-md-row { display: flex; align-items: center; gap: var(--tt-sp-2, 8px); padding: var(--tt-sp-2, 8px) var(--tt-sp-3, 12px); border-bottom: 1px solid var(--tt-line-soft, #eef0f2); cursor: pointer; min-height: 48px; user-select: none; }
        .tt-lookup-md-row:last-child { border-bottom: 0; }
        .tt-lookup-md-row:hover { background: #fafbfc; }
        .tt-lookup-md-row.is-active { background: #eef5ff; }
        .tt-lookup-md-row-grip { cursor: grab; color: #b6bcc2; font-size: 14px; min-width: 18px; }
        .tt-lookup-md-row-swatch { display: inline-block; width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }
        .tt-lookup-md-row-name { flex: 1; font-weight: 500; }
        .tt-lookup-md-row-lock { margin-left: 6px; color: #888; font-size: 11px; }
        .tt-lookup-md-row-sort { color: var(--tt-muted, #6b7280); font-size: 12px; min-width: 24px; text-align: right; }
        .tt-lookup-md-row-delete { background: transparent; border: 0; color: #b32d2e; font-size: 22px; line-height: 1; cursor: pointer; padding: 4px 8px; border-radius: 4px; min-width: 32px; min-height: 32px; }
        .tt-lookup-md-row-delete:hover { background: #fde2e2; }
        .tt-lookup-md-pane { display: flex; flex-direction: column; }
        .tt-lookup-md-pane-head { display: flex; align-items: center; justify-content: space-between; gap: var(--tt-sp-2, 8px); padding: var(--tt-sp-2, 8px) var(--tt-sp-3, 12px); border-bottom: 1px solid var(--tt-line, #e5e7ea); }
        .tt-lookup-md-pane-title { margin: 0; font-size: 16px; line-height: 1.3; }
        .tt-lookup-md-pane-back { display: none; background: transparent; border: 0; color: #1a4f9e; cursor: pointer; padding: 6px 10px; font-size: 14px; }
        .tt-lookup-md-pane-body { padding: var(--tt-sp-3, 12px); display: flex; flex-direction: column; gap: var(--tt-sp-3, 12px); overflow-y: auto; max-height: 70vh; flex: 1; }
        .tt-lookup-md-tx { margin-top: var(--tt-sp-3, 12px); border-top: 1px solid var(--tt-line, #e5e7ea); padding-top: var(--tt-sp-3, 12px); }
        .tt-lookup-md-tx-help { font-size: 12px; color: var(--tt-muted, #6b7280); margin: 0 0 var(--tt-sp-3, 12px); }
        .tt-lookup-md-tx-list { display: flex; flex-direction: column; gap: var(--tt-sp-2, 8px); }
        .tt-lookup-md-tx-row { padding: var(--tt-sp-2, 8px) 0; border-bottom: 1px solid var(--tt-line-soft, #eef0f2); display: flex; flex-direction: column; gap: 4px; }
        .tt-lookup-md-tx-row:last-child { border-bottom: 0; }
        .tt-lookup-md-tx-row .tt-field-label { margin-bottom: 4px; }
        .tt-lookup-md-pane-foot { position: sticky; bottom: 0; background: #fff; padding: var(--tt-sp-3, 12px) 0 0; border-top: 1px solid var(--tt-line, #e5e7ea); margin-top: var(--tt-sp-3, 12px); }
        /* Mobile: rail stays up top, pane hidden until a row is picked
         * (or until ?edit=N comes in on initial load — handled in JS). */
        @media (max-width: 767.98px) {
            .tt-lookup-md-pane { display: none; }
            .tt-lookup-md.is-pane-open .tt-lookup-md-pane { display: flex; }
            .tt-lookup-md.is-pane-open .tt-lookup-md-rail { display: none; }
            .tt-lookup-md-pane-back { display: inline-block; }
            .tt-lookup-md-rail-body { max-height: none; }
        }
        @media (min-width: 768px) {
            .tt-lookup-md { grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); align-items: start; }
            .tt-lookup-md-pane-back { display: none; }
        }
        </style>
        <?php
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
        .tt-cfg-tile { display: block; background: #fff; border: 1px solid var(--tt-line, #e5e7ea); border-radius: 8px; padding: 14px; text-decoration: none; color: #1a1d21; min-height: 76px; box-shadow: var(--tt-shadow-sm, none); transition: transform var(--tt-motion-duration, 180ms) var(--tt-motion-easing, cubic-bezier(0.2, 0.8, 0.2, 1)), box-shadow var(--tt-motion-duration, 180ms) var(--tt-motion-easing, ease), border-color var(--tt-motion-duration, 180ms) var(--tt-motion-easing, ease); }
        .tt-cfg-tile:hover, .tt-cfg-tile:focus { transform: translateY(-1px); box-shadow: var(--tt-shadow-md, 0 4px 12px rgba(0,0,0,0.08)); border-color: #d0d4d8; color: #1a1d21; }
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
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-min" class="tt-input" name="config[rating_min]" min="0" max="10" step="0.5" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_min', '5' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-rating-max"><?php esc_html_e( 'Max', 'talenttrack' ); ?></label>
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-max" class="tt-input" name="config[rating_max]" min="1" max="20" step="0.5" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_max', '10' ) ); ?>" />
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

    /**
     * v3.110.189 — academy-configurable PDP cycle blocks per season.
     * Form scaffolding for `frontend-pdp-blocks.js` to hydrate.
     */
    private static function renderPdpBlocksForm(): void {
        $seasons_repo = new \TT\Modules\Pdp\Repositories\SeasonsRepository();
        $blocks_repo  = new \TT\Modules\Pdp\Repositories\PdpBlocksRepository();

        $all_seasons = $seasons_repo->all();
        if ( empty( $all_seasons ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'No seasons configured yet. Open the wp-admin Seasons page first and add a season — then come back here to set its PDP blocks.', 'talenttrack' )
                . '</p>';
            return;
        }

        $current = $seasons_repo->current();
        $current_id = $current ? (int) $current->id : (int) $all_seasons[0]->id;

        $payload = [ 'seasons' => [] ];
        foreach ( $all_seasons as $s ) {
            $payload['seasons'][] = [
                'id'         => (int) $s->id,
                'name'       => (string) $s->name,
                'start_date' => (string) $s->start_date,
                'end_date'   => (string) $s->end_date,
                'is_current' => (int) $s->is_current === 1,
                'blocks'     => $blocks_repo->listForSeason( (int) $s->id ),
            ];
        }
        ?>
        <p style="color:var(--tt-muted, #5b6e75); margin: 0 0 var(--tt-sp-3, 16px);">
            <?php esc_html_e( "Configure the dates of each PDP cycle block for a season. Coaches opening a new PDP file pick how many blocks (2, 3 or 4); the file's conversation windows use the dates set here.", 'talenttrack' ); ?>
        </p>

        <form id="tt-pdp-blocks-form" class="tt-pdp-blocks" novalidate>
            <div class="tt-panel">
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-pdp-blocks-season"><?php esc_html_e( 'Season', 'talenttrack' ); ?></label>
                        <select id="tt-pdp-blocks-season" class="tt-input" data-tt-pdp-blocks-season>
                            <?php foreach ( $all_seasons as $s ) : ?>
                                <option value="<?php echo (int) $s->id; ?>" <?php selected( $current_id, (int) $s->id ); ?>>
                                    <?php echo esc_html( (string) $s->name );
                                    if ( (int) $s->is_current === 1 ) {
                                        echo ' — ' . esc_html__( 'current', 'talenttrack' );
                                    } ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label"><?php esc_html_e( 'Number of blocks', 'talenttrack' ); ?></label>
                        <div class="tt-pdp-blocks-size" role="radiogroup" aria-label="<?php esc_attr_e( 'Number of blocks in the cycle', 'talenttrack' ); ?>">
                            <?php foreach ( [ 2, 3, 4 ] as $n ) : ?>
                                <label class="tt-pdp-blocks-size-option">
                                    <input type="radio" name="tt-pdp-blocks-size" value="<?php echo (int) $n; ?>" data-tt-pdp-blocks-size />
                                    <span><?php echo (int) $n; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tt-panel">
                <h3 style="margin: 0 0 var(--tt-sp-2, 12px); font-size: 0.95rem;"><?php esc_html_e( 'Block dates', 'talenttrack' ); ?></h3>
                <div class="tt-pdp-blocks-rows" data-tt-pdp-blocks-rows></div>
            </div>

            <div class="tt-panel">
                <h3 style="margin: 0 0 var(--tt-sp-2, 12px); font-size: 0.95rem;"><?php esc_html_e( 'Year timeline', 'talenttrack' ); ?></h3>
                <div class="tt-pdp-blocks-timeline" data-tt-pdp-blocks-timeline></div>
                <div class="tt-pdp-blocks-axis" data-tt-pdp-blocks-axis aria-hidden="true"></div>
            </div>

            <div class="tt-pdp-blocks-messages" data-tt-pdp-blocks-messages role="status" aria-live="polite"></div>

            <div class="tt-form-actions" style="margin-top: var(--tt-sp-3, 16px);">
                <button type="submit" class="tt-btn tt-btn-primary" data-tt-pdp-blocks-save>
                    <?php esc_html_e( 'Save blocks', 'talenttrack' ); ?>
                </button>
                <span class="tt-form-msg" data-tt-pdp-blocks-msg></span>
            </div>
        </form>

        <script type="application/json" data-tt-pdp-blocks-payload>
            <?php echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON in script type=application/json is safe ?>
        </script>
        <?php
        wp_enqueue_script(
            'tt-pdp-blocks-config',
            TT_PLUGIN_URL . 'assets/js/frontend-pdp-blocks.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-pdp-blocks-config', 'TT_PDP_BLOCKS', [
            'rest_root' => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'i18n'      => [
                /* translators: %d = block number, 1-indexed */
                'block_label'        => __( 'Block %d', 'talenttrack' ),
                'from'               => __( 'From', 'talenttrack' ),
                'to'                 => __( 'To', 'talenttrack' ),
                'saving'             => __( 'Saving…', 'talenttrack' ),
                'saved'              => __( 'Blocks saved.', 'talenttrack' ),
                'save_failed'        => __( 'Could not save. Try again.', 'talenttrack' ),
                /* translators: 1: block number, 2: season start, 3: season end */
                'err_outside_season' => __( 'Block %1$d extends outside the season window (%2$s – %3$s).', 'talenttrack' ),
                /* translators: 1: block A number, 2: block B number */
                'err_overlap'        => __( 'Block %1$d overlaps with block %2$d.', 'talenttrack' ),
                /* translators: 1: gap start date, 2: gap end date */
                'err_gap'            => __( '%1$s to %2$s is not covered by any block.', 'talenttrack' ),
                /* translators: %d = block number */
                'err_end_before'     => __( 'Block %d ends before it starts.', 'talenttrack' ),
                'msg_no_issues'      => __( 'All dates inside the season; no overlaps; no gaps.', 'talenttrack' ),
            ],
        ] );
        wp_enqueue_style(
            'tt-pdp-blocks-config',
            TT_PLUGIN_URL . 'assets/css/frontend-pdp-blocks.css',
            [],
            TT_VERSION
        );
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
                <div class="tt-table-wrap">
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
            // v3.92.5 — was guarding the entire block on `wp.media` being
            // ready at script-execution time. Inline `<script>` runs at
            // parse time, but media-views.js (which defines wp.media) loads
            // via wp_enqueue_media() with no `dom_loaded` ordering guarantee.
            // On Dutch installs the operator reported the Choose logo button
            // simply did nothing — wp.media wasn't ready yet so the click
            // listener never got registered. Moved the readiness check
            // INSIDE the click handler so the button always responds, and
            // the user gets a console hint if media-views genuinely failed
            // to load.
            var frame;
            var pickBtn  = document.getElementById('tt-cfg-logo-pick');
            var clearBtn = document.getElementById('tt-cfg-logo-clear');
            var hidden   = document.getElementById('tt-cfg-logo-url');
            var preview  = document.getElementById('tt-cfg-logo-preview');
            if (pickBtn) pickBtn.addEventListener('click', function(){
                if (typeof wp === 'undefined' || !wp.media) {
                    if (window.console) console.warn('TalentTrack: wp.media not loaded — Choose logo button can\'t open the picker.');
                    return;
                }
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
