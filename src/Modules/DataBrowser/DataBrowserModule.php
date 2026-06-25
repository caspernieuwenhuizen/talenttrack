<?php
namespace TT\Modules\DataBrowser;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\DataBrowserRestController;

/**
 * DataBrowserModule (#1859).
 *
 * A read-only, matrix/academy-admin-only browser over the live `tt_*`
 * schema: friendly table/column labels, raw paginated rows, and
 * lightweight relationship navigation. Admin transparency / data-audit
 * tooling — scoped tightly to the two admin capabilities, never widening
 * player-data exposure beyond them (CLAUDE.md §1).
 *
 * The domain lives in this module ({@see DataBrowserService} and friends);
 * the REST controller and the frontend view are thin consumers of it.
 */
class DataBrowserModule implements ModuleInterface {

    public function getName(): string {
        return 'data_browser';
    }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        DataBrowserRestController::init();
    }
}
