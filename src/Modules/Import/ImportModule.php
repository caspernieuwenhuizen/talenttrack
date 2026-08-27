<?php
namespace TT\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * ImportModule (#2955) — spreadsheet import for club records.
 *
 * Holds the Excel parsing, validation and template machinery that used
 * to live under `DemoData\Excel`. DemoData still drives it for sample
 * datasets; onboarding drives it for a club's real squad. Neither owns
 * it, which is the point — an academy that switches DemoData off in
 * production must still be able to import.
 *
 * The module registers no surfaces of its own yet. `DemoDataPage`
 * remains the admin entry point for demo workbooks.
 */
class ImportModule implements ModuleInterface {

    public function getName(): string { return 'import'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        \TT\Infrastructure\REST\ImportRestController::init();

        add_action( 'admin_post_tt_import_undo', [ Frontend\ImportUndoHandler::class, 'handle' ] );

        \TT\Shared\Tiles\TileRegistry::register( [
            'module_class' => self::class,
            // Literal, not the class constant: check-module-toggles reads
            // this statically and cannot resolve a computed slug.
            'view_slug'    => 'import-history',
            'group'        => __( 'Administration', 'talenttrack' ),
            'kind'         => 'admin',
            'order'        => 75,
            'label'        => __( 'Import history', 'talenttrack' ),
            'description'  => __( 'What came in from a spreadsheet, and a way to take a whole import back out again.', 'talenttrack' ),
            'icon'         => 'import',
            'cap'          => Frontend\FrontendImportHistoryView::CAP,
        ] );
    }
}
