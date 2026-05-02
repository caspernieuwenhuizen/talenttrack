<?php
namespace TT\Modules\CustomCss;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\CustomCss\Frontend\FrontendCustomCssView;
use TT\Modules\CustomCss\Rest\DesignSystemController;

/**
 * CustomCssModule (#0064) — owns the per-club custom-CSS pipeline.
 *
 * The module wires the inline-style enqueue (CustomCssEnqueue), seeds
 * the `tt_admin_styling` capability, and registers itself on boot.
 * The authoring surface lives in `src/Modules/CustomCss/Frontend/`
 * and is dispatched from DashboardShortcode like any other Me / admin
 * tile slug.
 *
 * Migration `0049_custom_css.php` provisions the history table; the
 * "live" payload sits in `tt_config` keyed `custom_css.<surface>.*`.
 */
class CustomCssModule implements ModuleInterface {

    public function getName(): string { return 'custom_css'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        CustomCssEnqueue::init();
        FrontendCustomCssView::register();
        DesignSystemController::init();
    }

    /**
     * Idempotent capability seed.
     *
     *   tt_admin_styling — author, save, revert, upload custom CSS.
     *                      Granted to administrator + tt_club_admin
     *                      only. Coaches / scouts / staff don't get
     *                      this by default; some clubs may delegate
     *                      to a "marketing manager" role later.
     */
    public static function ensureCapabilities(): void {
        $cap = 'tt_admin_styling';
        $roles = [ 'administrator', 'tt_club_admin' ];
        foreach ( $roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $cap ) ) $role->add_cap( $cap );
        }
    }
}
