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
 * Phase 1 (this ship — v3.106.2):
 *   - `Domain\CustomDataSource` interface.
 *   - `CustomDataSourceRegistry` static catalogue.
 *   - 5 reference data sources: players_active, evaluations_recent,
 *     goals_open, activities_recent, pdp_files.
 *
 * Subsequent phases build the rest of the spec on top:
 *   - Phase 2 — `tt_custom_widgets` migration + REST CRUD.
 *   - Phase 3 — admin builder page (TalentTrack → Custom widgets).
 *   - Phase 4 — rendering engine + persona-dashboard editor palette
 *     integration.
 *   - Phase 5 — cap layer (`tt_author_custom_widgets`), per-widget
 *     transient cache, audit-log integration.
 *   - Phase 6 — docs + i18n + README link.
 *
 * **Feature flag**. The whole module is opt-in via the
 * `tt_custom_widgets_enabled` feature toggle (default off). Beta
 * installs flip it on; production stays off until Phase 6 ships.
 * The toggle gates the boot path entirely — when off, no data sources
 * register, no admin pages exist. Phase 1 ships the gate and the
 * data layer behind it.
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

        // Phase 3 — admin builder page.
        add_action( 'admin_enqueue_scripts', [ CustomWidgetsAdminPage::class, 'enqueueAssets' ] );
        add_action( 'admin_post_tt_custom_widget_archive', [ CustomWidgetsAdminPage::class, 'handleArchive' ] );
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
