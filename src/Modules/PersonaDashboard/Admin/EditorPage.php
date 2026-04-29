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

        echo '<div class="wrap tt-pde-wrap">';
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
        foreach ( WidgetRegistry::all() as $w ) {
            $widgets[] = [
                'id'                  => $w->id(),
                'label'               => $w->label(),
                'default_size'        => $w->defaultSize(),
                'allowed_sizes'       => $w->allowedSizes(),
                'persona_context'     => $w->personaContext(),
                'cap_required'        => $w->capRequired(),
                'default_priority'    => $w->defaultMobilePriority(),
            ];
        }

        $kpis_by_context = [
            PersonaContext::ACADEMY       => [],
            PersonaContext::COACH         => [],
            PersonaContext::PLAYER_PARENT => [],
        ];
        foreach ( KpiDataSourceRegistry::all() as $k ) {
            $kpis_by_context[ $k->context() ][] = [
                'id'    => $k->id(),
                'label' => $k->label(),
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
            'rest_url'       => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce'     => wp_create_nonce( 'wp_rest' ),
            'club_id'        => $club_id,
            'widgets'        => $widgets,
            'kpis_by_context'=> $kpis_by_context,
            'templates'      => $templates,
            'user_counts'    => $user_counts,
            'persona_labels' => self::personaLabelsMap( $personas ),
            'i18n'           => self::i18nStrings(),
        ];
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
            'persona_label_placeholder' => __( 'Use widget default', 'talenttrack' ),
            'mobile_preview_label'    => __( 'Mobile preview · 360 px', 'talenttrack' ),
            'desktop_label'           => __( 'Desktop · 12 columns', 'talenttrack' ),
            'grab'                    => __( 'Press space to grab, arrow keys to move, space to drop, escape to cancel.', 'talenttrack' ),
            'grabbed'                 => __( 'Grabbed %s. Use arrow keys to move, space to drop, escape to cancel.', 'talenttrack' ),
            'dropped'                 => __( 'Dropped %1$s at column %2$d, row %3$d.', 'talenttrack' ),
            'cancelled'               => __( 'Move cancelled.', 'talenttrack' ),
            'preview_as'              => __( 'Preview as', 'talenttrack' ),
        ];
    }

    private static function currentClubId(): int {
        if ( class_exists( '\\TT\\Infrastructure\\Tenancy\\CurrentClub' ) ) {
            return (int) \TT\Infrastructure\Tenancy\CurrentClub::id();
        }
        return 1;
    }
}
