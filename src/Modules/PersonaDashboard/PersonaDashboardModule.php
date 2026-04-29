<?php
namespace TT\Modules\PersonaDashboard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\PersonaDashboard\Admin\AuditSubscriber;
use TT\Modules\PersonaDashboard\Admin\EditorPage;
use TT\Modules\PersonaDashboard\Defaults\CoreTemplates;
use TT\Modules\PersonaDashboard\Defaults\CoreWidgets;
use TT\Modules\PersonaDashboard\Defaults\CoreKpis;
use TT\Modules\PersonaDashboard\Rest\ActivePersonaController;
use TT\Modules\PersonaDashboard\Rest\PersonaTemplateRestController;
use TT\Shared\Admin\AdminMenuRegistry;

/**
 * PersonaDashboardModule (#0060) — persona-aware landing pages.
 *
 * Owns:
 *   - WidgetRegistry (14 widget types in v1).
 *   - KpiDataSourceRegistry (25 KPI sources in v1).
 *   - PersonaTemplateRegistry (8 ship-default per-persona templates,
 *     plus tt_config-backed per-club override resolution).
 *   - PersonaLandingRenderer + GridRenderer used by DashboardShortcode
 *     when the tt_persona_dashboard_enabled flag is on.
 *   - REST: GET /personas/{slug}/template.
 *   - Capability: tt_edit_persona_templates (used by sprint 2 editor).
 *
 * Sprint 1 ships the framework + catalog + defaults gated behind the
 * feature flag. Legacy FrontendTileGrid stays the default render path
 * until sprint 3 flips the flag.
 */
class PersonaDashboardModule implements ModuleInterface {

    public function getName(): string { return 'persona_dashboard'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // Seed the registries on every request — append-only, so a
        // double-register from a parallel test harness is harmless.
        CoreWidgets::register();
        CoreKpis::register();
        CoreTemplates::register();

        PersonaTemplateRestController::init();
        ActivePersonaController::init();
        AuditSubscriber::init();

        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        add_action( 'admin_enqueue_scripts', [ EditorPage::class, 'enqueueAssets' ] );

        // Editor admin page — registered behind the existing
        // AdminMenuRegistry pattern so module-disable continues to gate
        // it via ModuleRegistry::isEnabled().
        AdminMenuRegistry::register( [
            'module_class' => self::class,
            'parent'       => 'talenttrack',
            'title'        => __( 'Dashboard layouts', 'talenttrack' ),
            'label'        => __( 'Dashboard layouts', 'talenttrack' ),
            'cap'          => 'tt_edit_persona_templates',
            'slug'         => EditorPage::SLUG,
            'callback'     => [ EditorPage::class, 'render' ],
            'group'        => 'configuration',
            'order'        => 30,
        ] );
    }

    /**
     * tt_edit_persona_templates — gate for the wp-admin editor (sprint 2).
     * Granted to administrator + tt_club_admin by default. Head of
     * Development can be granted via the matrix admin UI per club.
     */
    public static function ensureCapabilities(): void {
        $cap   = 'tt_edit_persona_templates';
        $roles = [ 'administrator', 'tt_club_admin' ];
        foreach ( $roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $cap ) ) $role->add_cap( $cap );
        }
    }
}
