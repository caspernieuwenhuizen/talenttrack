<?php
namespace TT\Modules\CustomWidgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\CustomWidgets\Admin\CustomWidgetsAdminPage;
use TT\Modules\CustomWidgets\DataSources\ActivitiesRecent;
use TT\Modules\CustomWidgets\DataSources\EvaluationsRecent;
use TT\Modules\CustomWidgets\DataSources\GoalsOpen;
use TT\Modules\CustomWidgets\DataSources\PdpFiles;
use TT\Modules\CustomWidgets\DataSources\PlayersActive;
use TT\Modules\CustomWidgets\Rest\CustomWidgetsRestController;
use TT\Modules\CustomWidgets\Widgets\CustomWidgetWidget;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;
use TT\Shared\Admin\AdminMenuRegistry;

/**
 * CustomWidgetsModule (#0078) — admin-authored persona-dashboard
 * widgets backed by a registered CustomDataSource layer.
 *
 * **Closed at v3.109.7 (Phase 6).** The full epic shipped across:
 *   - Phase 1 (v3.106.2) — `CustomDataSource` interface + 5
 *     reference sources.
 *   - Phase 2 (v3.109.3) — `tt_custom_widgets` migration + repository
 *     + service + REST CRUD (8 endpoints).
 *   - Phase 3 (v3.109.4) — TalentTrack → Custom widgets admin page +
 *     six-step builder (Source → Columns → Filters → Format → Preview
 *     → Save), vanilla JS state machine + live preview against the
 *     Phase 2 REST endpoint.
 *   - Phase 4 (v3.109.5) — `Renderer\CustomWidgetRenderer` (table /
 *     KPI / bar / line; Chart.js v4.4.0 from CDN) + synthetic
 *     `CustomWidgetWidget` registered with `WidgetRegistry`.
 *   - Phase 5 (v3.109.6) — `tt_author_custom_widgets` +
 *     `tt_manage_custom_widgets` caps + `custom_widgets` matrix entity
 *     + per-widget transient cache + audit log + clear-cache wiring +
 *     source-cap inheritance at render time.
 *   - Phase 6 (v3.109.7) — docs (`docs/custom-widgets.md` EN+NL) +
 *     module docblock cleanup. Closes #0078.
 *
 * **Feature flag**. The whole module remains opt-in via
 * `tt_custom_widgets_enabled` (default off). Operators flip it on per
 * club with `wp option update tt_custom_widgets_enabled 1` (or set
 * the same key on `tt_config`). When off, the boot path short-
 * circuits before any registration — no admin page, no REST routes,
 * no editor palette tile. Default-off is deliberate so existing
 * installs aren't surprised by a new admin page on upgrade.
 */
class CustomWidgetsModule implements ModuleInterface {

    public function getName(): string { return 'custom_widgets'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // Feature-flag-gated. Default off — beta installs opt in via
        // `wp option update tt_custom_widgets_enabled 1` until the
        // surface ships in Phase 6.
        if ( ! self::isFeatureEnabled() ) return;

        self::registerInitialDataSources();
        CustomWidgetsRestController::init();

        // Phase 3 — admin builder page. Phase 5 adds the clear-cache
        // admin-post hook + cap-ensure helpers below.
        add_action( 'admin_enqueue_scripts', [ CustomWidgetsAdminPage::class, 'enqueueAssets' ] );
        add_action( 'admin_post_tt_custom_widget_archive', [ CustomWidgetsAdminPage::class, 'handleArchive' ] );
        add_action( 'admin_post_tt_custom_widget_clear_cache', [ CustomWidgetsAdminPage::class, 'handleClearCache' ] );

        // Phase 5 — ensure the new caps land on the right roles. The
        // matrix entity gates are seeded by migration 0077, but the
        // bridging caps need to exist on `wp_user_capabilities` so
        // `current_user_can()` returns the right answer for callers
        // that haven't been migrated to matrix-aware checks yet.
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        AdminMenuRegistry::register( [
            'module_class' => self::class,
            'parent'       => 'talenttrack',
            'title'        => __( 'Custom widgets', 'talenttrack' ),
            'label'        => __( 'Custom widgets', 'talenttrack' ),
            'cap'          => 'tt_edit_persona_templates',
            'slug'         => CustomWidgetsAdminPage::SLUG,
            'callback'     => [ CustomWidgetsAdminPage::class, 'render' ],
            'group'        => 'configuration',
            'order'        => 35,
        ] );

        // Configuration tile — same pattern as Dashboard layouts.
        add_filter( 'tt_config_tile_groups', [ self::class, 'addBuilderTile' ], 10, 1 );

        // Phase 4 — register the synthetic Widget on the persona-
        // dashboard registry so each saved custom widget shows up
        // in the editor palette via dataSourceCatalogue(). Wired
        // late on `init` so WidgetRegistry has booted.
        add_action( 'init', static function () {
            if ( class_exists( WidgetRegistry::class ) ) {
                WidgetRegistry::register( new CustomWidgetWidget() );
            }
        }, 20 );

        // Phase 4 — render-side CSS for the chart frame + table +
        // KPI styles. Loaded on the front-end dashboard render where
        // the persona dashboard fires.
        add_action( 'wp_enqueue_scripts', static function () {
            wp_enqueue_style(
                'tt-custom-widgets-render',
                TT_PLUGIN_URL . 'assets/css/custom-widgets-render.css',
                [],
                TT_VERSION
            );
        } );
    }

    /**
     * @param array<int, array{label: string, tiles: array<int, array<string,mixed>>}> $groups
     * @return array<int, array{label: string, tiles: array<int, array<string,mixed>>}>
     */
    public static function addBuilderTile( array $groups ): array {
        $tile = [
            'label'       => __( 'Custom widgets', 'talenttrack' ),
            'description' => __( 'Compose your own dashboard widgets — pick a data source, choose columns and filters, drop them onto a persona dashboard.', 'talenttrack' ),
            'icon'        => '🧮',
            'url'         => admin_url( 'admin.php?page=' . CustomWidgetsAdminPage::SLUG ),
            'cap'         => 'tt_edit_persona_templates',
        ];
        foreach ( $groups as &$group ) {
            if ( ! is_array( $group ) ) continue;
            $label = (string) ( $group['label'] ?? '' );
            if ( strpos( $label, 'Branding' ) !== false || strpos( $label, 'Personas' ) !== false ) {
                $group['tiles'][] = $tile;
                return $groups;
            }
        }
        unset( $group );
        $groups[] = [
            'label' => __( 'Personas', 'talenttrack' ),
            'tiles' => [ $tile ],
        ];
        return $groups;
    }

    /**
     * tt_author_custom_widgets — granted to administrator + tt_club_admin
     * + tt_head_dev by default. Mirrors the persona-dashboard editor
     * cap-ensure pattern (#0060 sprint 2). The matrix layer (#0033)
     * is the authoritative gate; this layer keeps role-based callers
     * working during the upgrade window. tt_manage_custom_widgets
     * gives admins delete authority on top.
     */
    public static function ensureCapabilities(): void {
        $roles = [
            'administrator'  => [ 'tt_author_custom_widgets', 'tt_manage_custom_widgets' ],
            'tt_club_admin'  => [ 'tt_author_custom_widgets', 'tt_manage_custom_widgets' ],
            'tt_head_dev'    => [ 'tt_author_custom_widgets' ],
        ];
        foreach ( $roles as $role_key => $caps ) {
            $role = get_role( $role_key );
            if ( ! $role ) continue;
            foreach ( $caps as $cap ) {
                if ( ! $role->has_cap( $cap ) ) $role->add_cap( $cap );
            }
        }
    }

    /**
     * Feature toggle check. Reads `tt_custom_widgets_enabled` from
     * `tt_config` if the helper is loaded; falls back to
     * `wp_options` for installs predating the per-club config layer.
     */
    private static function isFeatureEnabled(): bool {
        if ( class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            return ( new \TT\Infrastructure\Config\ConfigService() )->getBool( 'tt_custom_widgets_enabled', false );
        }
        return (bool) get_option( 'tt_custom_widgets_enabled', false );
    }

    /**
     * The 5 reference sources from the spec. Registered centrally
     * here for Phase 1 sequencing simplicity. A follow-up moves each
     * registration into its owning module's `boot()` so the
     * CustomWidgets module doesn't need to know about every other
     * module's tables.
     */
    private static function registerInitialDataSources(): void {
        CustomDataSourceRegistry::register( new PlayersActive() );
        CustomDataSourceRegistry::register( new EvaluationsRecent() );
        CustomDataSourceRegistry::register( new GoalsOpen() );
        CustomDataSourceRegistry::register( new ActivitiesRecent() );
        CustomDataSourceRegistry::register( new PdpFiles() );
    }
}
