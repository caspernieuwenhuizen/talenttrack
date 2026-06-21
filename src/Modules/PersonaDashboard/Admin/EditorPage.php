<?php
namespace TT\Modules\PersonaDashboard\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Registry\KpiDataSourceRegistry;
use TT\Modules\PersonaDashboard\Registry\PersonaTemplateRegistry;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;

/**
 * EditorPage — wp-admin shell for the persona dashboard editor (#0060 sprint 2).
 *
 * Renders three panes (palette · canvas · properties) with a top toolbar
 * (persona dropdown · undo/redo · mobile preview · reset · draft · publish).
 * The PHP side is intentionally thin: page chrome, asset enqueue, and a
 * single bootstrap JSON blob with everything the JS needs (catalogs +
 * resolved templates per persona). All edit/drag/drop/save logic lives
 * in the editor JS.
 */
final class EditorPage {

    public const SLUG = 'tt-dashboard-layouts';

    public static function render(): void {
        if ( ! current_user_can( 'tt_edit_persona_templates' ) ) {
            wp_die( esc_html__( 'You do not have permission to edit persona dashboard layouts.', 'talenttrack' ) );
        }

        $personas    = PersonaTemplateRegistry::defaultPersonas();
        $club_id     = self::currentClubId();
        $current_user = wp_get_current_user();
        $bootstrap   = self::buildBootstrap( $personas, $club_id );

        echo '<div class="wrap tt-pde-wrap" data-library-open="true">';
        echo '<header class="tt-pde-toolbar" role="toolbar" aria-label="' . esc_attr__( 'Editor toolbar', 'talenttrack' ) . '">';
        echo '<div class="tt-pde-toolbar-left">';
        echo '<h1 class="tt-pde-title">' . esc_html__( 'Dashboard layouts', 'talenttrack' ) . '</h1>';
        echo '<label class="tt-pde-persona-picker">';
        echo '<span class="tt-pde-label">' . esc_html__( 'Persona', 'talenttrack' ) . '</span>';
        echo '<select id="tt-pde-persona" data-tt-pde="persona-select">';
        foreach ( $personas as $p ) {
            echo '<option value="' . esc_attr( $p ) . '">' . esc_html( self::personaLabel( $p ) ) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        echo '</div>';
        echo '<div class="tt-pde-toolbar-right">';
        echo '<button type="button" class="button tt-pde-btn" data-tt-pde="library-toggle" aria-pressed="true">';
        echo esc_html__( 'Library', 'talenttrack' ) . '</button>';
        echo '<span class="tt-pde-divider" aria-hidden="true"></span>';
        echo '<button type="button" class="button tt-pde-btn" data-tt-pde="undo" aria-keyshortcuts="Control+Z" disabled>';
        echo esc_html__( 'Undo', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="button tt-pde-btn" data-tt-pde="redo" aria-keyshortcuts="Control+Shift+Z" disabled>';
        echo esc_html__( 'Redo', 'talenttrack' ) . '</button>';
        echo '<span class="tt-pde-divider" aria-hidden="true"></span>';
        echo '<button type="button" class="button tt-pde-btn" data-tt-pde="mobile-preview" aria-pressed="false">';
        echo esc_html__( 'Mobile preview', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="button tt-pde-btn" data-tt-pde="reset">';
        echo esc_html__( 'Reset to default', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="button tt-pde-btn" data-tt-pde="save-draft">';
        echo esc_html__( 'Save draft', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="button button-primary tt-pde-btn-primary" data-tt-pde="publish">';
        echo esc_html__( 'Publish', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</header>';

        echo '<div class="tt-pde-status" data-tt-pde="status" aria-live="polite"></div>';

        echo '<div class="tt-pde-grid" data-tt-pde="grid">';

        // Left — widget + KPI palette.
        echo '<aside class="tt-pde-pane tt-pde-palette" aria-label="' . esc_attr__( 'Widget and KPI palette', 'talenttrack' ) . '">';
        echo '<nav class="tt-pde-tabs" role="tablist">';
        echo '<button type="button" role="tab" class="tt-pde-tab is-active" data-tt-pde-tab="widgets" aria-selected="true">'
            . esc_html__( 'Widgets', 'talenttrack' ) . '</button>';
        echo '<button type="button" role="tab" class="tt-pde-tab" data-tt-pde-tab="kpis" aria-selected="false">'
            . esc_html__( 'KPIs', 'talenttrack' ) . '</button>';
        echo '</nav>';
        echo '<div class="tt-pde-palette-body" role="tabpanel" data-tt-pde-tabpanel="widgets"></div>';
        echo '<div class="tt-pde-palette-body" role="tabpanel" data-tt-pde-tabpanel="kpis" hidden></div>';
        echo '</aside>';

        // Center — canvas.
        echo '<main class="tt-pde-pane tt-pde-canvas-wrap" aria-label="' . esc_attr__( 'Layout canvas', 'talenttrack' ) . '">';
        echo '<div class="tt-pde-canvas-frame" data-tt-pde="canvas-frame">';
        echo '<div class="tt-pde-bands">';
        echo '<section class="tt-pde-band tt-pde-band-hero" data-tt-pde-band="hero" aria-label="' . esc_attr__( 'Hero band', 'talenttrack' ) . '"></section>';
        echo '<section class="tt-pde-band tt-pde-band-task" data-tt-pde-band="task" aria-label="' . esc_attr__( 'Task band', 'talenttrack' ) . '"></section>';
        echo '<section class="tt-pde-canvas" data-tt-pde="canvas" aria-label="' . esc_attr__( 'Bento grid', 'talenttrack' ) . '" role="application"></section>';
        echo '</div>';
        echo '</div>';
        echo '</main>';

        // Right — properties panel.
        echo '<aside class="tt-pde-pane tt-pde-properties" data-tt-pde="properties" aria-label="' . esc_attr__( 'Selected widget properties', 'talenttrack' ) . '">';
        echo '<div class="tt-pde-properties-empty">'
            . esc_html__( 'Select a widget on the canvas to edit its properties.', 'talenttrack' )
            . '</div>';
        echo '</aside>';

        echo '</div>'; // .tt-pde-grid

        echo '<div class="tt-pde-modal-root" data-tt-pde="modal-root" aria-hidden="true"></div>';

        echo '</div>'; // .wrap

        wp_localize_script( 'tt-persona-dashboard-editor', 'TT_PDE_Bootstrap', $bootstrap );
    }

    /**
     * Asset enqueue for the editor screen only.
     */
    public static function enqueueAssets( string $hook ): void {
        // Hook from `add_submenu_page` is "talenttrack_page_tt-dashboard-layouts" (when parent is "talenttrack").
        if ( strpos( $hook, self::SLUG ) === false ) return;

        wp_enqueue_style(
            'tt-persona-dashboard-editor',
            TT_PLUGIN_URL . 'assets/css/persona-dashboard-editor.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-persona-dashboard-editor',
            TT_PLUGIN_URL . 'assets/js/persona-dashboard-editor.js',
            [],
            TT_VERSION,
            true
        );
    }

    /**
     * @param list<string> $personas
     * @return array<string,mixed>
     */
    private static function buildBootstrap( array $personas, int $club_id ): array {
        $widgets = [];
        $data_sources_by_widget = [];
        $multi_data_source_widgets = [];
        foreach ( WidgetRegistry::all() as $w ) {
            // v3.110.110 — description + intended_personas. Methods are
            // defaulted to empty on AbstractWidget; concrete widgets
            // override when they want to surface rich detail. KPIs go
            // through the same `method_exists` pattern below to stay
            // backwards compatible with existing implementations that
            // don't declare the new optional methods.
            $description = method_exists( $w, 'description' )
                ? (string) $w->description()
                : '';
            $intended_personas = method_exists( $w, 'intendedPersonas' )
                ? (array) $w->intendedPersonas()
                : [];
            $widgets[] = [
                'id'                  => $w->id(),
                'label'               => $w->label(),
                'description'         => $description,
                'intended_personas'   => array_values( array_map( 'strval', $intended_personas ) ),
                'default_size'        => $w->defaultSize(),
                'allowed_sizes'       => $w->allowedSizes(),
                'persona_context'     => $w->personaContext(),
                'cap_required'        => $w->capRequired(),
                'default_priority'    => $w->defaultMobilePriority(),
            ];
            // #0077 M1 — publish each widget's catalogue so the editor
            // can show a dropdown instead of a free-text data_source.
            $catalogue = method_exists( $w, 'dataSourceCatalogue' ) ? $w->dataSourceCatalogue() : [];
            if ( ! empty( $catalogue ) ) {
                $data_sources_by_widget[ $w->id() ] = $catalogue;
            }
            // #1611 — widgets whose data_source is a comma-joined list of
            // catalogue ids signal a multi-select. The editor reads this
            // to render a checklist instead of the single-select dropdown.
            if ( method_exists( $w, 'dataSourceMultiple' ) && $w->dataSourceMultiple() ) {
                $multi_data_source_widgets[ $w->id() ] = true;
            }
        }

        $kpis_by_context = [
            PersonaContext::ACADEMY       => [],
            PersonaContext::COACH         => [],
            PersonaContext::PLAYER_PARENT => [],
        ];
        foreach ( KpiDataSourceRegistry::all() as $k ) {
            $kpi_description = method_exists( $k, 'description' )
                ? (string) $k->description()
                : '';
            $kpis_by_context[ $k->context() ][] = [
                'id'          => $k->id(),
                'label'       => $k->label(),
                'description' => $kpi_description,
            ];
        }

        $templates = [];
        $user_counts = [];
        foreach ( $personas as $persona ) {
            $templates[ $persona ] = [
                'persona_slug' => $persona,
                'default'      => PersonaTemplateRegistry::resolve( $persona, $club_id )->toArray(),
                'draft'        => self::loadOverrideArray( $persona, 'draft' ),
                'published'    => self::loadOverrideArray( $persona, 'published' ),
            ];
            $user_counts[ $persona ] = self::countUsersForPersona( $persona );
        }

        return [
            'rest_url'                 => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce'               => wp_create_nonce( 'wp_rest' ),
            'club_id'                  => $club_id,
            'widgets'                  => $widgets,
            'kpis_by_context'          => $kpis_by_context,
            // #0077 M1 — { widget_id: { preset_id: human_label } }
            'data_sources_by_widget'   => $data_sources_by_widget,
            // #1611 — { widget_id: true } for widgets whose data_source is
            // a comma-joined CSV of catalogue ids (multi-select checklist).
            'multi_data_source_widgets' => $multi_data_source_widgets,
            'templates'                => $templates,
            'user_counts'              => $user_counts,
            'persona_labels'           => self::personaLabelsMap( $personas ),
            'i18n'                     => self::i18nStrings(),
            // #1102 — visibility hints. Editor renders a green-check or
            // amber-warning line beside each slot's "Capability required"
            // line based on whether the active persona's default WP role
            // typically holds the cap (widgets) or whether the chosen
            // tile slug's underlying cap is reachable (navigation tiles).
            // Cheap heuristic — does NOT account for per-user matrix
            // scope overrides; the hint label says so explicitly.
            'default_role_for_persona' => self::defaultRolesByPersona( $personas ),
            'role_caps'                => self::wpRoleCapsByRole( $personas ),
            'tile_caps_by_slug'        => self::tileCapsBySlug(),
        ];
    }

    /**
     * #1102 — { persona_slug: wp_role_slug } map. Used by the editor JS
     * to look up which role's caps to compare against.
     *
     * @param list<string> $personas
     * @return array<string,string>
     */
    private static function defaultRolesByPersona( array $personas ): array {
        $out = [];
        foreach ( $personas as $p ) {
            $role = \TT\Modules\Authorization\PersonaResolver::defaultWpRoleFor( $p );
            if ( $role !== null ) {
                $out[ $p ] = $role;
            }
        }
        return $out;
    }

    /**
     * #1102 — { wp_role_slug: { cap_name: true, ... } } map. One entry
     * per WP role the personas-in-scope might map to, so the editor JS
     * can answer "does this role hold this cap?" client-side.
     *
     * @param list<string> $personas
     * @return array<string, array<string, bool>>
     */
    private static function wpRoleCapsByRole( array $personas ): array {
        $roles_needed = [];
        foreach ( $personas as $p ) {
            $role = \TT\Modules\Authorization\PersonaResolver::defaultWpRoleFor( $p );
            if ( $role !== null ) {
                $roles_needed[ $role ] = true;
            }
        }
        $out = [];
        foreach ( array_keys( $roles_needed ) as $role_slug ) {
            $role = get_role( $role_slug );
            $out[ $role_slug ] = $role ? array_filter( $role->capabilities ) : [];
        }
        return $out;
    }

    /**
     * #1102 — { tile_slug: cap_name } map for every tile that declares
     * a `cap`. Navigation tiles consume a `view_slug` as their data
     * source; the editor needs this map to surface the same visibility
     * hint that widgets get via their own `cap_required`.
     *
     * @return array<string,string>
     */
    private static function tileCapsBySlug(): array {
        if ( ! class_exists( \TT\Shared\Tiles\TileRegistry::class ) ) return [];
        $out = [];
        foreach ( \TT\Shared\Tiles\TileRegistry::allRegistered() as $tile ) {
            $slug = (string) ( $tile['view_slug'] ?? $tile['slug'] ?? '' );
            $cap  = (string) ( $tile['cap']       ?? '' );
            if ( $slug !== '' && $cap !== '' ) {
                $out[ $slug ] = $cap;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function loadOverrideArray( string $persona, string $status ): ?array {
        $template = PersonaTemplateRegistry::loadOverride( $persona, $status );
        return $template ? $template->toArray() : null;
    }

    /** @return array<string,string> */
    private static function personaLabelsMap( array $personas ): array {
        $out = [];
        foreach ( $personas as $p ) $out[ $p ] = self::personaLabel( $p );
        return $out;
    }

    private static function personaLabel( string $slug ): string {
        $labels = [
            'player'              => __( 'Player', 'talenttrack' ),
            'parent'              => __( 'Parent', 'talenttrack' ),
            'head_coach'          => __( 'Head coach', 'talenttrack' ),
            'assistant_coach'     => __( 'Assistant coach', 'talenttrack' ),
            'team_manager'        => __( 'Team manager', 'talenttrack' ),
            'head_of_development' => __( 'Head of Development', 'talenttrack' ),
            'scout'               => __( 'Scout', 'talenttrack' ),
            'academy_admin'       => __( 'Academy admin', 'talenttrack' ),
            'readonly_observer'   => __( 'Read-only observer', 'talenttrack' ),
        ];
        return $labels[ $slug ] ?? str_replace( '_', ' ', $slug );
    }

    /**
     * Best-effort count of users a publish would affect for a given persona.
     * Not perfectly accurate (PersonaResolver runs per-user; SQL roughly
     * approximates by WP role) but good enough for the publish-confirmation
     * heads-up. Returns null if the count can't be computed.
     */
    private static function countUsersForPersona( string $persona ): ?int {
        $role_for_persona = [
            'player'              => 'tt_player',
            'parent'              => 'tt_parent',
            'head_coach'          => 'tt_coach',
            'assistant_coach'     => 'tt_coach',
            'team_manager'        => 'tt_team_manager',
            'head_of_development' => 'tt_head_dev',
            'scout'               => 'tt_scout',
            'academy_admin'       => 'tt_club_admin',
            'readonly_observer'   => 'tt_readonly_observer',
        ];
        $role = $role_for_persona[ $persona ] ?? null;
        if ( $role === null ) return null;
        $users = get_users( [ 'role' => $role, 'fields' => 'ID', 'number' => -1 ] );
        return is_array( $users ) ? count( $users ) : null;
    }

    /** @return array<string,string> */
    private static function i18nStrings(): array {
        return [
            'add_widget'              => __( 'Add widget', 'talenttrack' ),
            'add_kpi'                 => __( 'Add KPI', 'talenttrack' ),
            'remove'                  => __( 'Remove', 'talenttrack' ),
            'resize'                  => __( 'Resize', 'talenttrack' ),
            'drag_handle'             => __( 'Drag to reorder', 'talenttrack' ),
            'size'                    => __( 'Size', 'talenttrack' ),
            'mobile_priority'         => __( 'Mobile priority', 'talenttrack' ),
            'mobile_visible'          => __( 'Show on mobile', 'talenttrack' ),
            'data_source'             => __( 'Data source', 'talenttrack' ),
            'persona_label'           => __( 'Persona label override', 'talenttrack' ),
            'select_widget'           => __( 'Select a widget on the canvas to edit its properties.', 'talenttrack' ),
            'reset_confirm_title'     => __( 'Reset this persona to the ship default?', 'talenttrack' ),
            'reset_confirm_body'      => __( 'Your published layout for this persona will be replaced by the TalentTrack default. This cannot be undone.', 'talenttrack' ),
            'publish_confirm_title'   => __( 'Publish layout', 'talenttrack' ),
            'publish_confirm_body'    => __( 'This will replace the live %1$s dashboard for ~%2$d users on their next page load.', 'talenttrack' ),
            'publish_no_count_body'   => __( 'This will replace the live %s dashboard on the next page load for users matching this persona.', 'talenttrack' ),
            'publish_confirm_button'  => __( 'Publish now', 'talenttrack' ),
            'cancel'                  => __( 'Cancel', 'talenttrack' ),
            'reset_confirm_button'    => __( 'Reset to default', 'talenttrack' ),
            'saved_draft'             => __( 'Draft saved.', 'talenttrack' ),
            'published'               => __( 'Layout published.', 'talenttrack' ),
            'reset_done'              => __( 'Reset to default.', 'talenttrack' ),
            'save_failed'             => __( 'Save failed. Try again.', 'talenttrack' ),
            'unsaved_changes'         => __( 'You have unsaved changes.', 'talenttrack' ),
            'no_widgets_placed'       => __( 'No widgets placed. Drag from the palette on the left, or use the keyboard: focus a palette item and press Enter to add it.', 'talenttrack' ),
            'kpi_context_academy'     => __( 'Academy-wide', 'talenttrack' ),
            'kpi_context_coach'       => __( 'Coach', 'talenttrack' ),
            'kpi_context_player'      => __( 'Player / parent', 'talenttrack' ),
            // v3.110.110 — properties-panel detail block.
            'intended_for'            => __( 'Intended for', 'talenttrack' ),
            'cap_required'            => __( 'Capability required', 'talenttrack' ),
            // #1102 — visibility hint badges next to the cap line.
            'visibility_ok'           => __( 'Visible to %s users by default.', 'talenttrack' ),
            'visibility_blocked'      => __( 'Hidden — %1$s users lack `%2$s` by default. Grant via Authorization → Matrix or remove from this layout.', 'talenttrack' ),
            'visibility_caveat'       => __( 'Based on the persona\'s default WordPress role only — per-user matrix scopes can override this.', 'talenttrack' ),
            'kpi_label'               => __( 'KPI', 'talenttrack' ),
            'persona_label_placeholder' => __( 'Use widget default', 'talenttrack' ),
            'mobile_preview_label'    => __( 'Mobile preview · 360 px', 'talenttrack' ),
            'desktop_label'           => __( 'Desktop · 12 columns', 'talenttrack' ),
            'grab'                    => __( 'Press space to grab, arrow keys to move, space to drop, escape to cancel.', 'talenttrack' ),
            'grabbed'                 => __( 'Grabbed %s. Use arrow keys to move, space to drop, escape to cancel.', 'talenttrack' ),
            'dropped'                 => __( 'Dropped %1$s at column %2$d, row %3$d.', 'talenttrack' ),
            'cancelled'               => __( 'Move cancelled.', 'talenttrack' ),
            'preview_as'              => __( 'Preview as', 'talenttrack' ),
            'widget_added'            => __( 'Added %s to the canvas.', 'talenttrack' ),
            // #1142 — swap-on-hover status announcement.
            'swapped'                 => __( 'Swapped.', 'talenttrack' ),
        ];
    }

    private static function currentClubId(): int {
        if ( class_exists( '\\TT\\Infrastructure\\Tenancy\\CurrentClub' ) ) {
            return (int) \TT\Infrastructure\Tenancy\CurrentClub::id();
        }
        return 1;
    }
}
